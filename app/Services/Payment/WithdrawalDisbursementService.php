<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\Enums\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\RiskLevel;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Withdrawal;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator for Real-Money Outbound Withdrawal Disbursements.
 *
 * NON-BLOCKING ARCHITECTURE:
 * Phase 1: DB Transaction (State Validation, Mark Processing, Commit)
 * Phase 2: External Gateway HTTP Request (NO DB locks held)
 * Phase 3: DB Transaction (Finalize Settlement, Release Hold on Failure, or Record Pending)
 */
class WithdrawalDisbursementService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly WalletLockService $locks,
        private readonly WalletHoldService $holds,
        private readonly FinancialStateTransitionService $transitions,
        private readonly WithdrawalApprovalService $approvalService,
        private readonly WithdrawalCompletionService $completionService,
    ) {
    }

    /**
     * Disburse an approved withdrawal to an external payment provider.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function disburse(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        // -------------------------------------------------------------
        // PHASE 1: DB Transaction - State Preparation & Mark Processing
        // -------------------------------------------------------------
        $preparation = DB::transaction(function () use ($withdrawal): array {
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);

            if ($current->status === WithdrawalStatus::Completed) {
                throw WithdrawalException::alreadyCompleted(
                    (int) $current->getKey(),
                    $current->financial_transaction_id,
                );
            }

            if ($current->status === WithdrawalStatus::Pending || $current->status === WithdrawalStatus::UnderReview) {
                // If manual approval is required, disallow direct dispatch
                if ((bool) config('finance.withdrawal.require_manual_approval', true)) {
                    throw WithdrawalException::notCompletable($current->id, $current->status);
                }

                // Auto-approve if permitted
                $this->approvalService->approve($current);
                $current->refresh();
            }

            if ($current->status === WithdrawalStatus::Approved) {
                $current = $this->approvalService->markProcessing($current);
            }

            if ($current->status !== WithdrawalStatus::Processing) {
                throw WithdrawalException::notCompletable($current->id, $current->status);
            }

            $driver = $this->gateways->forMethod($current->method);

            if (! $driver->supportsCurrency($current->currency)) {
                throw FinancialException::withCode(
                    'unsupported_gateway_currency',
                    sprintf('Gateway [%s] does not support currency [%s].', $driver->name(), $current->currency->value),
                    ['gateway' => $driver->name(), 'currency' => $current->currency->value],
                );
            }

            // Create or resolve Payment aggregate
            $payment = Payment::query()
                ->where('payable_type', Withdrawal::class)
                ->where('payable_id', $current->getKey())
                ->first();

            if (! $payment instanceof Payment) {
                $payment = new Payment();
                $payment->fill([
                    'reference_number' => 'PAY-WD-'.strtoupper(bin2hex(random_bytes(8))),
                    'user_id' => (int) $current->user_id,
                    'payable_type' => Withdrawal::class,
                    'payable_id' => $current->getKey(),
                    'method' => $current->method,
                    'status' => PaymentStatus::Pending,
                    'currency' => $current->currency,
                    'amount' => (string) $current->amount,
                    'fee' => (string) $current->fee,
                    'gateway' => $driver->name(),
                    'metadata' => [
                        'withdrawal_reference' => $current->reference_number,
                        'net_amount' => (string) $current->net_amount,
                    ],
                ]);
                $payment->save();
            }

            $this->recordAudit(
                action: AuditAction::Withdraw,
                auditable: $current,
                description: sprintf('Withdrawal %s dispatched for disbursement via %s.', $current->reference_number, $driver->name()),
                metadata: [
                    'withdrawal_id' => $current->getKey(),
                    'amount' => (string) $current->amount,
                    'net_amount' => (string) $current->net_amount,
                    'gateway' => $driver->name(),
                ],
            );

            return [
                'withdrawal' => $current,
                'payment' => $payment,
                'driver' => $driver,
            ];
        });

        /** @var Withdrawal $currentWithdrawal */
        $currentWithdrawal = $preparation['withdrawal'];
        /** @var Payment $payment */
        $payment = $preparation['payment'];
        /** @var \App\Services\Payment\Contracts\PaymentGatewayInterface $driver */
        $driver = $preparation['driver'];

        // -------------------------------------------------------------
        // PHASE 2: Gateway Call (NO DB Transaction / Locks held)
        // -------------------------------------------------------------
        try {
            $gatewayResponse = $driver->initiateWithdrawal($currentWithdrawal, $options);
        } catch (\Throwable $e) {
            Log::error('Outbound withdrawal disbursement failed at gateway', [
                'withdrawal_id' => $currentWithdrawal->id,
                'gateway' => $driver->name(),
                'exception' => $e->getMessage(),
            ]);

            $gatewayResponse = GatewayWithdrawalResponse::failed(
                'Gateway communication failure: '.$e->getMessage(),
                ['exception' => $e->getMessage()],
            );
        }

        // -------------------------------------------------------------
        // PHASE 3: DB Transaction - Result Finalization
        // -------------------------------------------------------------
        return DB::transaction(function () use ($currentWithdrawal, $payment, $driver, $gatewayResponse): GatewayWithdrawalResponse {
            $this->locks->lock((int) $currentWithdrawal->wallet_id);
            $lockedWithdrawal = $this->transitions->lockWithdrawal($currentWithdrawal);

            // Re-check state
            if ($lockedWithdrawal->status === WithdrawalStatus::Completed) {
                return $gatewayResponse;
            }

            if ($gatewayResponse->successful && ! $gatewayResponse->isPending) {
                // Immediate synchronous payout completion
                $payment->status = PaymentStatus::Captured;
                $payment->captured_at = Carbon::now();
                $payment->gateway_reference = $gatewayResponse->providerReference;
                $payment->gateway_response = $gatewayResponse->rawResponse;
                $payment->save();

                $this->completionService->complete($lockedWithdrawal, null, [
                    'provider' => $driver->name(),
                    'provider_reference' => $gatewayResponse->providerReference,
                    'description' => sprintf('Withdrawal %s disbursed via %s', $lockedWithdrawal->reference_number, $driver->name()),
                ]);

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $lockedWithdrawal,
                    description: sprintf('Withdrawal %s successfully disbursed and settled via %s.', $lockedWithdrawal->reference_number, $driver->name()),
                    metadata: [
                        'provider_reference' => $gatewayResponse->providerReference,
                        'gateway' => $driver->name(),
                    ],
                );
            } elseif ($gatewayResponse->successful && $gatewayResponse->isPending) {
                // Asynchronous pending disbursement (awaits webhook / payout completion)
                $payment->gateway_reference = $gatewayResponse->providerReference;
                $payment->gateway_response = $gatewayResponse->rawResponse;
                $payment->save();

                $lockedWithdrawal->provider = $driver->name();
                $lockedWithdrawal->provider_reference = $gatewayResponse->providerReference;
                $metadata = is_array($lockedWithdrawal->metadata) ? $lockedWithdrawal->metadata : [];
                $metadata['disbursed_pending_at'] = Carbon::now()->toIso8601String();
                $metadata['provider_reference'] = $gatewayResponse->providerReference;
                $lockedWithdrawal->metadata = $metadata;
                $lockedWithdrawal->save();

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $lockedWithdrawal,
                    description: sprintf('Withdrawal %s pending external provider confirmation from %s.', $lockedWithdrawal->reference_number, $driver->name()),
                    metadata: [
                        'provider_reference' => $gatewayResponse->providerReference,
                        'gateway' => $driver->name(),
                    ],
                );
            } else {
                // Provider declined or failed disbursement: release hold and mark failed
                $failureReason = $gatewayResponse->errorMessage ?? 'Withdrawal payout failed by provider.';

                $payment->status = PaymentStatus::Failed;
                $payment->failed_at = Carbon::now();
                $payment->failure_reason = $failureReason;
                $payment->gateway_response = $gatewayResponse->rawResponse;
                $payment->save();

                $this->approvalService->markFailed($lockedWithdrawal, $failureReason);

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $lockedWithdrawal,
                    description: sprintf('Withdrawal %s disbursement failed by %s: %s', $lockedWithdrawal->reference_number, $driver->name(), $failureReason),
                    oldValues: ['status' => 'processing'],
                    newValues: ['status' => 'failed'],
                    metadata: [
                        'gateway' => $driver->name(),
                        'reason' => $failureReason,
                    ],
                );
            }

            return $gatewayResponse;
        });
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
