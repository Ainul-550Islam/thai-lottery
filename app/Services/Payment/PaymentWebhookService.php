<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentProcessingResult;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\AuditAction;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RiskLevel;
use App\Enums\WithdrawalStatus;
use App\Exceptions\DepositException;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\Withdrawal;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Robust, production-grade Payment Webhook Processing Engine.
 *
 * GUARANTEES
 * ----------
 * 1. CRYPTOGRAPHIC INTEGRITY: Webhook signatures are verified before payload execution.
 * 2. REPLAY & DEDUPLICATION: Webhook event IDs are keyed in atomic cache.
 * 3. TRANSACTIONAL SETTLEMENT: Webhook events execute inside atomic database transactions.
 * 4. STRICT FINANCIAL INTEGRITY:
 *    - Deposit Success -> Payment Captured -> Deposit Approved -> Deposit Completed ->
 *      Wallet Credited -> Balanced Double-Entry Ledger Posting (DEBIT Cash, CREDIT Liability).
 *    - Deposit Failure/Expiry -> Payment Failed -> Deposit Cancelled -> Zero Wallet/Ledger writes.
 *    - Reversal/Refund -> FinancialReversalService -> Balanced Mirror Postings -> Wallet Reversal.
 * 5. IDEMPOTENCY: Redundant/duplicate webhooks return replayed status with ZERO duplicate credits.
 */
class PaymentWebhookService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly PaymentGatewayManager $gateways,
        private readonly DepositApprovalService $depositApproval,
        private readonly DepositCompletionService $depositCompletion,
        private readonly WithdrawalApprovalService $withdrawalApproval,
        private readonly WithdrawalCompletionService $withdrawalCompletion,
        private readonly FinancialReversalService $reversalService,
        private readonly FinancialStateTransitionService $transitions,
    ) {
    }

    /**
     * Process an incoming webhook request from a gateway.
     *
     * @throws FinancialException
     */
    public function handleWebhookRequest(string $gatewayName, Request $request): PaymentProcessingResult
    {
        $driver = $this->gateways->driver($gatewayName);

        // 1. Signature Verification
        if ((bool) $this->config->get('payment.webhook.verify_signature', true)) {
            if (! $driver->verifyWebhookSignature($request)) {
                Log::warning('Payment webhook signature verification failed', [
                    'gateway' => $gatewayName,
                    'ip' => $request->ip(),
                ]);

                return PaymentProcessingResult::failed($gatewayName, 'Invalid webhook signature.');
            }
        }

        // 2. Parse Payload
        $payload = $driver->parseWebhook($request);

        // 3. Process normalized payload
        return $this->processWebhookPayload($payload);
    }

    /**
     * Process a normalized webhook payload.
     *
     * @throws FinancialException
     */
    public function processWebhookPayload(WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;
        $eventId = $payload->eventId;

        // Replay Protection Cache Check
        $cacheKey = (string) $this->config->get('payment.webhook.replay_cache_prefix', 'payment:webhook:seen:')
            .$gateway.':'.$eventId;
        $cacheTtl = (int) $this->config->get('payment.idempotency.cache_ttl', 86400);

        if (! $this->cache->add($cacheKey, true, $cacheTtl)) {
            Log::info('Payment webhook event already processed (replay cache hit)', [
                'gateway' => $gateway,
                'event_id' => $eventId,
            ]);

            return PaymentProcessingResult::ignored(
                gateway: $gateway,
                reason: 'Webhook event ID already processed.',
                replayed: true,
            );
        }

        return DB::transaction(function () use ($payload, $gateway): PaymentProcessingResult {
            if ($payload->eventType->isDeposit()) {
                return $this->processDepositWebhook($payload);
            }

            if ($payload->eventType->isWithdrawal()) {
                return $this->processWithdrawalWebhook($payload);
            }

            if ($payload->eventType->isReversal()) {
                return $this->processReversalWebhook($payload);
            }

            return PaymentProcessingResult::ignored(
                gateway: $gateway,
                reason: 'Ignored unsupported webhook event: '.$payload->eventType->value,
            );
        });
    }

    /**
     * Handle Deposit webhook event (Success, Failure, Cancellation, Expiration).
     */
    private function processDepositWebhook(WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;
        $deposit = $this->resolveDeposit($payload);

        if (! $deposit instanceof Deposit) {
            Log::warning('Payment webhook received for non-existent deposit', [
                'gateway' => $gateway,
                'internal_reference' => $payload->internalReference,
                'provider_reference' => $payload->providerReference,
            ]);

            return PaymentProcessingResult::failed($gateway, 'Matching deposit not found.');
        }

        $payment = $this->resolveOrCreatePayment($deposit, $payload);

        // Case A: Deposit Succeeded
        if ($payload->isSuccess) {
            // Validate currency match if explicitly present in payload
            $hasExplicitCurrency = isset($payload->rawData['currency']);
            if ($hasExplicitCurrency && $payload->currency !== null && $payload->currency !== $deposit->currency) {
                Log::warning('Payment webhook currency mismatch', [
                    'gateway' => $gateway,
                    'deposit_id' => $deposit->id,
                    'expected_currency' => $deposit->currency->value,
                    'received_currency' => $payload->currency->value,
                ]);

                $this->recordAudit(
                    action: AuditAction::Deposit,
                    auditable: $deposit,
                    description: sprintf(
                        'Payment webhook currency mismatch for Deposit %s: expected %s, received %s.',
                        $deposit->reference_number,
                        $deposit->currency->value,
                        $payload->currency->value,
                    ),
                    metadata: [
                        'deposit_id' => $deposit->id,
                        'expected_currency' => $deposit->currency->value,
                        'received_currency' => $payload->currency->value,
                        'gateway' => $gateway,
                    ],
                );

                return PaymentProcessingResult::failed($gateway, 'Payment webhook currency mismatch.');
            }

            // Validate amount match if present in payload
            if ($payload->amount !== null) {
                $expectedAmount = (string) $deposit->amount;
                $receivedAmount = (string) $payload->amount;

                if (bccomp($expectedAmount, $receivedAmount, 2) !== 0) {
                    Log::warning('Payment webhook amount mismatch', [
                        'gateway' => $gateway,
                        'deposit_id' => $deposit->id,
                        'expected_amount' => $expectedAmount,
                        'received_amount' => $receivedAmount,
                    ]);

                    $this->recordAudit(
                        action: AuditAction::Deposit,
                        auditable: $deposit,
                        description: sprintf(
                            'Payment webhook amount mismatch for Deposit %s: expected %s %s, received %s %s.',
                            $deposit->reference_number,
                            $expectedAmount,
                            $deposit->currency->value,
                            $receivedAmount,
                            $deposit->currency->value,
                        ),
                        metadata: [
                            'deposit_id' => $deposit->id,
                            'expected_amount' => $expectedAmount,
                            'received_amount' => $receivedAmount,
                            'gateway' => $gateway,
                        ],
                    );

                    return PaymentProcessingResult::failed($gateway, 'Payment webhook amount mismatch.');
                }
            }

            return $this->finalizeSuccessfulDeposit($deposit, $payment, $payload);
        }

        // Case B: Deposit Failed / Expired / Cancelled
        return $this->finalizeFailedDeposit($deposit, $payment, $payload);
    }

    /**
     * Finalize a successful deposit: complete payment, credit wallet, and record ledger.
     */
    private function finalizeSuccessfulDeposit(Deposit $deposit, Payment $payment, WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;

        // Replay check: already confirmed deposit
        if ($deposit->status === DepositStatus::Confirmed && $deposit->financial_transaction_id !== null) {
            $existingTx = FinancialTransaction::query()->find($deposit->financial_transaction_id);

            return PaymentProcessingResult::completed(
                gateway: $gateway,
                payment: $payment,
                transaction: $existingTx ?? new FinancialTransaction(),
                actionTaken: 'replayed',
                replayed: true,
                metadata: ['already_settled' => true],
            );
        }

        // Update Payment status to Captured
        $payment->status = PaymentStatus::Captured;
        $payment->captured_at = Carbon::now();
        $payment->gateway_reference = $payload->providerReference ?? $payment->gateway_reference;
        $payment->gateway_response = $payload->rawData;
        $payment->save();

        // If deposit is Pending, advance to Approved first
        if ($deposit->status === DepositStatus::Pending) {
            $this->depositApproval->approve($deposit, null, 'Auto-approved via payment webhook verification');
            $deposit->refresh();
        }

        // Complete the deposit and credit the wallet
        $completion = $this->depositCompletion->complete(
            deposit: $deposit,
            idempotencyKey: null,
            options: [
                'description' => sprintf('Deposit %s via %s', $deposit->reference_number, strtoupper($gateway)),
                'provider' => $gateway,
                'provider_reference' => $payload->providerReference,
                'metadata' => [
                    'webhook_event_id' => $payload->eventId,
                    'gateway' => $gateway,
                ],
            ],
        );

        /** @var FinancialTransaction $transaction */
        $transaction = $completion['transaction'];

        $this->recordAudit(
            action: AuditAction::Deposit,
            auditable: $deposit,
            description: sprintf(
                'Deposit %s successfully confirmed via %s webhook: %s %s credited to player wallet #%d.',
                $deposit->reference_number,
                strtoupper($gateway),
                $deposit->net_amount,
                $deposit->currency->value,
                $deposit->wallet_id,
            ),
            oldValues: ['status' => 'pending'],
            newValues: ['status' => 'confirmed'],
            metadata: [
                'payment_id' => $payment->getKey(),
                'transaction_id' => $transaction->getKey(),
                'gateway' => $gateway,
                'event_id' => $payload->eventId,
            ],
        );

        return PaymentProcessingResult::completed(
            gateway: $gateway,
            payment: $payment,
            transaction: $transaction,
            actionTaken: 'credited',
            replayed: false,
            metadata: ['wallet_credited' => true],
        );
    }

    /**
     * Finalize a failed or cancelled deposit.
     */
    private function finalizeFailedDeposit(Deposit $deposit, Payment $payment, WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;
        $reason = $payload->failureReason ?? 'Payment was cancelled or failed by provider.';

        // Payment status
        $payment->status = PaymentStatus::Failed;
        $payment->failed_at = Carbon::now();
        $payment->failure_reason = $reason;
        $payment->gateway_response = $payload->rawData;
        $payment->save();

        // Deposit status
        if ($deposit->status->canCancel()) {
            $this->transitions->transitionDeposit($deposit, DepositStatus::Failed, [
                'failed_at' => Carbon::now(),
                'failure_reason' => $reason,
                'metadata' => array_merge(is_array($deposit->metadata) ? $deposit->metadata : [], [
                    'failed_at' => Carbon::now()->toIso8601String(),
                    'webhook_event_id' => $payload->eventId,
                    'gateway' => $gateway,
                ]),
            ]);
        }

        $this->recordAudit(
            action: AuditAction::Deposit,
            auditable: $deposit,
            description: sprintf('Deposit %s marked failed via %s webhook: %s', $deposit->reference_number, strtoupper($gateway), $reason),
            oldValues: ['status' => 'pending'],
            newValues: ['status' => 'failed'],
            metadata: ['gateway' => $gateway, 'reason' => $reason],
        );

        return PaymentProcessingResult::failed(
            gateway: $gateway,
            errorMessage: $reason,
            payment: $payment,
        );
    }

    /**
     * Handle Withdrawal webhook event (P2P / Disbursement callbacks).
     */
    private function processWithdrawalWebhook(WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;
        $withdrawal = $this->resolveWithdrawal($payload);

        if (! $withdrawal instanceof Withdrawal) {
            Log::warning('Withdrawal webhook received for non-existent withdrawal', [
                'gateway' => $gateway,
                'internal_reference' => $payload->internalReference,
                'provider_reference' => $payload->providerReference,
            ]);

            return PaymentProcessingResult::failed($gateway, 'Matching withdrawal not found.');
        }

        // 1. Validate currency match
        if ($payload->currency !== null && $payload->currency !== $withdrawal->currency) {
            Log::warning('Withdrawal webhook currency mismatch', [
                'gateway' => $gateway,
                'withdrawal_id' => $withdrawal->id,
                'expected_currency' => $withdrawal->currency->value,
                'received_currency' => $payload->currency->value,
            ]);

            return PaymentProcessingResult::failed($gateway, 'Withdrawal webhook currency mismatch.');
        }

        // 2. Validate amount match (compare exact decimal string against gross or net amount)
        if ($payload->amount !== null) {
            $amountMatchesGross = bccomp((string) $withdrawal->amount, (string) $payload->amount, 2) === 0;
            $amountMatchesNet = bccomp((string) $withdrawal->net_amount, (string) $payload->amount, 2) === 0;

            if (! $amountMatchesGross && ! $amountMatchesNet) {
                Log::warning('Withdrawal webhook amount mismatch', [
                    'gateway' => $gateway,
                    'withdrawal_id' => $withdrawal->id,
                    'expected_amount' => (string) $withdrawal->amount,
                    'expected_net_amount' => (string) $withdrawal->net_amount,
                    'received_amount' => (string) $payload->amount,
                ]);

                return PaymentProcessingResult::failed($gateway, 'Withdrawal webhook amount mismatch.');
            }
        }

        // 3. Case A: Successful Payout Webhook
        if ($payload->isSuccess) {
            if ($withdrawal->status === WithdrawalStatus::Completed) {
                return PaymentProcessingResult::ignored($gateway, 'Withdrawal already completed.', true);
            }

            if ($withdrawal->status === WithdrawalStatus::Approved || $withdrawal->status === WithdrawalStatus::Processing || $withdrawal->status === WithdrawalStatus::Pending) {
                if ($withdrawal->status === WithdrawalStatus::Pending) {
                    $this->withdrawalApproval->approve($withdrawal, null, 'Auto-approved via payment webhook verification');
                    $withdrawal->refresh();
                }

                $completion = $this->withdrawalCompletion->complete(
                    withdrawal: $withdrawal,
                    options: [
                        'provider' => $gateway,
                        'provider_reference' => $payload->providerReference,
                    ],
                );

                /** @var FinancialTransaction $tx */
                $tx = $completion['transaction'];

                $payment = Payment::query()
                    ->where('payable_type', Withdrawal::class)
                    ->where('payable_id', $withdrawal->id)
                    ->first();

                if ($payment instanceof Payment) {
                    $payment->status = PaymentStatus::Captured;
                    $payment->captured_at = Carbon::now();
                    $payment->gateway_reference = $payload->providerReference ?? $payment->gateway_reference;
                    $payment->gateway_response = $payload->rawData;
                    $payment->save();
                }

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $withdrawal,
                    description: sprintf('Withdrawal %s disbursed successfully via %s webhook.', $withdrawal->reference_number, strtoupper($gateway)),
                    oldValues: ['status' => $withdrawal->status->value],
                    newValues: ['status' => 'completed'],
                    metadata: ['transaction_id' => $tx->getKey(), 'gateway' => $gateway],
                );

                return PaymentProcessingResult::completed(
                    gateway: $gateway,
                    payment: $payment,
                    transaction: $tx,
                    actionTaken: 'debited',
                );
            }
        }

        // 4. Case B: Failed / Declined / Cancelled Payout Webhook
        if ($withdrawal->status->isFinal()) {
            return PaymentProcessingResult::ignored($gateway, 'Withdrawal already in final state: '.$withdrawal->status->value, true);
        }

        $reason = $payload->failureReason ?? 'Withdrawal payout failed or was rejected by provider.';

        // Release the hold and mark Failed
        $this->withdrawalApproval->markFailed($withdrawal, $reason);

        $payment = Payment::query()
            ->where('payable_type', Withdrawal::class)
            ->where('payable_id', $withdrawal->id)
            ->first();

        if ($payment instanceof Payment) {
            $payment->status = PaymentStatus::Failed;
            $payment->failed_at = Carbon::now();
            $payment->failure_reason = $reason;
            $payment->gateway_response = $payload->rawData;
            $payment->save();
        }

        $this->recordAudit(
            action: AuditAction::Withdraw,
            auditable: $withdrawal,
            description: sprintf('Withdrawal %s marked failed via %s webhook: %s', $withdrawal->reference_number, strtoupper($gateway), $reason),
            oldValues: ['status' => $withdrawal->status->value],
            newValues: ['status' => 'failed'],
            metadata: ['gateway' => $gateway, 'reason' => $reason],
        );

        return PaymentProcessingResult::failed(
            gateway: $gateway,
            errorMessage: $reason,
            payment: $payment,
        );
    }

    /**
     * Handle Chargeback / Reversal webhook event.
     */
    private function processReversalWebhook(WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;

        // Try resolving deposit first
        $deposit = $this->resolveDeposit($payload);
        if ($deposit instanceof Deposit && $deposit->financial_transaction_id !== null) {
            $originalTx = FinancialTransaction::query()->find($deposit->financial_transaction_id);

            if ($originalTx instanceof FinancialTransaction && $this->reversalService->canReverse($originalTx)) {
                $reversalTx = $this->reversalService->reverse(
                    original: $originalTx,
                    reason: sprintf('Deposit payment reversed/refunded by %s webhook', strtoupper($gateway)),
                );

                $this->transitions->transitionDeposit($deposit, DepositStatus::Refunded, [
                    'metadata' => array_merge(is_array($deposit->metadata) ? $deposit->metadata : [], [
                        'refunded_at' => Carbon::now()->toIso8601String(),
                        'reversal_transaction_id' => (int) $reversalTx->getKey(),
                    ]),
                ]);

                return PaymentProcessingResult::completed(
                    gateway: $gateway,
                    payment: null,
                    transaction: $reversalTx,
                    actionTaken: 'reversed',
                );
            }
        }

        // Try resolving withdrawal for reversal (e.g. bounced payout)
        $withdrawal = $this->resolveWithdrawal($payload);
        if ($withdrawal instanceof Withdrawal && $withdrawal->financial_transaction_id !== null) {
            $originalTx = FinancialTransaction::query()->find($withdrawal->financial_transaction_id);

            if ($originalTx instanceof FinancialTransaction && $this->reversalService->canReverse($originalTx)) {
                $reversalTx = $this->reversalService->reverse(
                    original: $originalTx,
                    reason: sprintf('Withdrawal payout reversed/bounced by %s webhook', strtoupper($gateway)),
                );

                $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
                $metadata['reversed_at'] = Carbon::now()->toIso8601String();
                $metadata['reversal_transaction_id'] = (int) $reversalTx->getKey();
                $withdrawal->metadata = $metadata;
                $withdrawal->save();

                return PaymentProcessingResult::completed(
                    gateway: $gateway,
                    payment: null,
                    transaction: $reversalTx,
                    actionTaken: 'reversed',
                );
            }
        }

        return PaymentProcessingResult::ignored($gateway, 'Transaction is not reversible or already reversed.');
    }

    /**
     * Locate the matching Deposit model.
     */
    private function resolveDeposit(WebhookPayload $payload): ?Deposit
    {
        if ($payload->internalReference !== null && trim($payload->internalReference) !== '') {
            $deposit = Deposit::query()
                ->where('reference_number', trim($payload->internalReference))
                ->orWhere('uuid', trim($payload->internalReference))
                ->first();

            if ($deposit instanceof Deposit) {
                return $deposit;
            }
        }

        if ($payload->providerReference !== null && trim($payload->providerReference) !== '') {
            $deposit = Deposit::query()
                ->where('provider_reference', trim($payload->providerReference))
                ->first();

            if ($deposit instanceof Deposit) {
                return $deposit;
            }
        }

        return null;
    }

    /**
     * Locate the matching Withdrawal model.
     */
    private function resolveWithdrawal(WebhookPayload $payload): ?Withdrawal
    {
        if ($payload->internalReference !== null && trim($payload->internalReference) !== '') {
            $withdrawal = Withdrawal::query()
                ->where('reference_number', trim($payload->internalReference))
                ->orWhere('uuid', trim($payload->internalReference))
                ->first();

            if ($withdrawal instanceof Withdrawal) {
                return $withdrawal;
            }
        }

        if ($payload->providerReference !== null && trim($payload->providerReference) !== '') {
            $withdrawal = Withdrawal::query()
                ->where('provider_reference', trim($payload->providerReference))
                ->first();

            if ($withdrawal instanceof Withdrawal) {
                return $withdrawal;
            }
        }

        return null;
    }

    /**
     * Resolve existing Payment record or create a new one.
     */
    private function resolveOrCreatePayment(Deposit $deposit, WebhookPayload $payload): Payment
    {
        $gateway = $payload->gateway;
        $providerRef = $payload->providerReference;

        $payment = Payment::query()
            ->where('payable_type', Deposit::class)
            ->where('payable_id', $deposit->getKey())
            ->first();

        if (! $payment instanceof Payment) {
            $payment = new Payment();
            $payment->fill([
                'reference_number' => 'PAY-'.strtoupper(bin2hex(random_bytes(8))),
                'user_id' => $deposit->user_id,
                'payable_type' => Deposit::class,
                'payable_id' => $deposit->getKey(),
                'method' => $deposit->method ?? PaymentMethod::tryFrom($gateway) ?? PaymentMethod::Manual,
                'status' => PaymentStatus::Pending,
                'currency' => $deposit->currency,
                'amount' => (string) $deposit->amount,
                'fee' => (string) $deposit->fee,
                'gateway' => $gateway,
                'gateway_reference' => $providerRef,
                'gateway_response' => $payload->rawData,
            ]);
            $payment->save();
        }

        return $payment;
    }

    private function recordAudit(
        AuditAction $action,
        object $auditable,
        string $description,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
    ): void {
        $log = new AuditLog();
        $log->fill([
            'user_id' => null,
            'action' => $action,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => get_class($auditable),
            'auditable_id' => (int) $auditable->getKey(),
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
        ]);
        $log->save();
    }
}
