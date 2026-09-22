<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PayoutTransferData;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizePayoutMethod;
use App\Enums\RiskLevel;
use App\Exceptions\PayoutTransferException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The OUTBOUND leg: executes and records payout transfers through the
 * codebase's existing payment abstraction.
 *
 * WHAT "THE EXISTING PAYMENT ABSTRACTION" MEANS HERE
 * --------------------------------------------------
 * A transfer is not a new kind of financial record: it is a PAYMENT row
 * carrying (method, gateway, amount, currency) polymorphically attached to
 * the payout it settles ((payable_type, payable_id) = Payout). Deposits,
 * withdrawals and payout transfers therefore project through one analytics
 * view, and failure/failure-reason/captured_at live in their canonical
 * columns rather than in a bespoke transfer table.
 *
 * TRANSFER VS. WALLET LANE
 * ------------------------
 * The wallet-method leg of a prize never comes here: crediting the player's
 * in-system wallet is the batch executor's own per-payout ledger write,
 * because that wallet movement has no gateway. This lane accepts the two
 * EXTERNAL methods:
 *
 *   BankTransfer  records a payment whose gateway is the bank-transfer
 *                 driver name; execution completion arrives later through
 *                 banks/backoffice (complete() with the gateway reference)
 *   Cheque        records the physical instrument lane ('manual' gateway);
 *                 complete() is the drawer-operator's confirmation gesture
 *
 * IDEMPOTENCY, AGAINST THE ONLY RETRY THAT MATTERS
 * ------------------------------------------------
 * The retry that matters is the one after the money may already have
 * moved: the worker died, the network cut after the bank accepted, the
 * operator clicked twice. The payout's metadata.transfer stamp carries the
 * idempotency anchor; a transfer invoked with a key already stamped is a
 * REPLAY and re-serves the stored record, never a new execution. A second
 * payment row for one anchor is impossible by this check + the payout
 * lock.
 *
 * AMOUNT DRIFT
 * ------------
 * The approved amount and the transfer amount must be the same decimal
 * STRING at bcmath equality. Drift is refused regardless of direction:
 * over-pay is embezzlement-shaped; under-pay is a short-pay the player
 * discovers at the branch.
 *
 * STATE GUARDS
 * ------------
 * Only payouts the execution lane can still pay (Pending / Processing)
 * admit a transfer; Completed handles replay, Refunded/Reversed/Cancelled
 * forbid. Money never moves without the payout's own state agreeing.
 */
class PayoutTransferService
{
    /**
     * Metadata lane recording the transfer per payout.
     */
    public const METADATA_KEY = 'transfer';

    /**
     * Execute (open) a transfer lane for a payout.
     *
     * @return array<string, mixed>  The transfer record (payment reference,
     *                               lane status, anchor), identical on
     *                               replay by anchor.
     *
     * @throws PayoutTransferException
     */
    public function transfer(PayoutTransferData $data): array
    {
        if (DB::transactionLevel() > 0) {
            throw PayoutTransferException::alreadyRunning((int) DB::transactionLevel());
        }

        // Method gate first — a malformed lane refuses before it touches a row.
        if ($data->method === PrizePayoutMethod::Wallet) {
            throw PayoutTransferException::gatewayRefused(
                $data->payoutReference,
                'wallet-method payouts settle on the internal wallet lane, never on the external transfer lane',
            );
        }

        if (! $data->amountIsWellFormed()) {
            throw PayoutTransferException::gatewayRefused(
                $data->payoutReference,
                sprintf('transfer amount [%s] is not a well-formed 2-decimal string', $data->amount),
            );
        }

        return DB::transaction(function () use ($data): array {
            $payout = Payout::query()->lockForUpdate()
                ->where('reference_number', $data->payoutReference)
                ->first();

            if (! $payout instanceof Payout) {
                throw PayoutTransferException::missingPayout($data->payoutReference);
            }

            // REPLAY BY ANCHOR: already stamped with THIS key → re-serve.
            $existing = $this->recordFor($payout);

            if (is_array($existing) && ($existing['idempotency_key'] ?? null) === $data->idempotencyKey) {
                return $existing;
            }

            // A transfer stamped with a DIFFERENT anchor on the same payout:
            // the same obligation being spent a second time. Refusing is the
            // only legal answer. (A legitimate retry always reuses its key.)
            if (is_array($existing) && ($existing['lane_status'] ?? null) !== 'failed') {
                throw PayoutTransferException::duplicateTransfer($data->idempotencyKey);
            }

            // AMOUNT DRIFT.
            if (bccomp(bcadd($data->amount, '0.00', 2), (string) $payout->amount, 2) !== 0) {
                throw PayoutTransferException::amountDrift(
                    $data->payoutReference,
                    (string) $payout->amount,
                    bcadd($data->amount, '0.00', 2),
                );
            }

            // STATE GUARD.
            if (! in_array($payout->status, [PayoutStatus::Pending, PayoutStatus::Processing], true)) {
                throw PayoutTransferException::payoutStateForbids(
                    $data->payoutReference,
                    $payout->status->value,
                );
            }

            $method = $this->methodOf($data->method);
            $gateway = $this->gatewayOf($data->method);

            // The payment row: the canonical financial record of the leg.
            $payment = new Payment();
            $payment->fill([
                'reference_number' => $this->derivePaymentReference($data->idempotencyKey),
                'user_id' => $data->beneficiaryUserId,
                'payable_type' => Payout::class,
                'payable_id' => (int) $payout->getKey(),
                'method' => $method->value,
                'amount' => bcadd($data->amount, '0.00', 2),
                'fee' => '0.00',
                'gateway' => $gateway,
                'gateway_reference' => null,
                'metadata' => [
                    'payout_transfer' => true,
                    'batch_key' => $data->batchKey,
                    'idempotency_key' => $data->idempotencyKey,
                    'context' => $data->context,
                ],
            ]);
            $payment->currency = $data->currency;
            $payment->status = PaymentStatus::Pending;
            $payment->save();

            $record = [
                'idempotency_key' => $data->idempotencyKey,
                'batch_key' => $data->batchKey,
                'payout_reference' => $data->payoutReference,
                'payment_reference' => (string) $payment->reference_number,
                'method' => $data->method->value,
                'amount' => bcadd($data->amount, '0.00', 2),
                'currency' => $data->currency->value,
                'lane_status' => 'open',
                'opened_at' => Carbon::now()->toIso8601String(),
                'completed_at' => null,
            ];

            $this->writeRecord($payout, $record);

            $this->recordAudit($payout, sprintf(
                'Payout transfer opened: %s %s via %s (payment %s, batch %s).',
                bcadd($data->amount, '0.00', 2),
                $data->currency->value,
                $data->method->value,
                $payment->reference_number,
                $data->batchKey,
            ), RiskLevel::High);

            return $record;
        });
    }

    /**
     * Confirm a transfer's money landed: gateway/backoffice/operator
     * completion gesture. Payment turns Captured; payout completes.
     *
     * Idempotent by anchor: completing twice re-serves the completed record.
     *
     * @return array<string, mixed>
     *
     * @throws PayoutTransferException
     */
    public function complete(PayoutTransferData $data, string $gatewayReference): array
    {
        return DB::transaction(function () use ($data, $gatewayReference): array {
            $payout = Payout::query()->lockForUpdate()
                ->where('reference_number', $data->payoutReference)
                ->first();

            if (! $payout instanceof Payout) {
                throw PayoutTransferException::missingPayout($data->payoutReference);
            }

            $record = $this->recordFor($payout);

            if (! is_array($record) || ($record['idempotency_key'] ?? null) !== $data->idempotencyKey) {
                throw PayoutTransferException::gatewayRefused(
                    $data->payoutReference,
                    'no open transfer lane with this anchor exists; completion has nothing to land on',
                );
            }

            if (($record['lane_status'] ?? null) === 'completed') {
                return $record; // replay-safe
            }

            if (($record['lane_status'] ?? null) !== 'open') {
                throw PayoutTransferException::gatewayRefused(
                    $data->payoutReference,
                    sprintf('transfer lane is %s; only an open lane completes', (string) ($record['lane_status'] ?? 'unknown')),
                );
            }

            if (bccomp(bcadd($data->amount, '0.00', 2), (string) ($record['amount'] ?? ''), 2) !== 0) {
                throw PayoutTransferException::amountDrift(
                    $data->payoutReference,
                    (string) ($record['amount'] ?? ''),
                    bcadd($data->amount, '0.00', 2),
                );
            }

            // Payment: captured with the external reference named.
            $payment = Payment::query()->lockForUpdate()
                ->where('reference_number', (string) $record['payment_reference'])
                ->first();

            if ($payment instanceof Payment) {
                $payment->status = PaymentStatus::Captured;
                $payment->gateway_reference = $gatewayReference;
                $payment->captured_at = Carbon::now();
                $payment->save();
            }

            // Payout: completed, money moved.
            $payout->status = PayoutStatus::Completed;
            $payout->processed_at = Carbon::now();
            $payout->save();

            $record = array_merge($record, [
                'lane_status' => 'completed',
                'gateway_reference' => $gatewayReference,
                'completed_at' => Carbon::now()->toIso8601String(),
            ]);
            $this->writeRecord($payout, $record);

            $this->recordAudit($payout, sprintf(
                'Payout transfer COMPLETED: %s %s settled to beneficiary #%d (gateway ref %s).',
                (string) ($record['amount'] ?? ''),
                (string) ($record['currency'] ?? ''),
                $data->beneficiaryUserId,
                $gatewayReference,
            ), RiskLevel::High);

            return $record;
        });
    }

    /**
     * Mark a transfer failed: the refusal came back from the bank, or the
     * drawer voided the cheque. Payout turns Failed so ops sees it; the
     * lane stamp permits a FRESH anchor (a corrected transfer) to open.
     *
     * @return array<string, mixed>
     */
    public function fail(PayoutTransferData $data, string $reason): array
    {
        return DB::transaction(function () use ($data, $reason): array {
            $payout = Payout::query()->lockForUpdate()
                ->where('reference_number', $data->payoutReference)
                ->first();

            if (! $payout instanceof Payout) {
                throw PayoutTransferException::missingPayout($data->payoutReference);
            }

            $record = $this->recordFor($payout);

            if (! is_array($record) || ($record['idempotency_key'] ?? null) !== $data->idempotencyKey) {
                throw PayoutTransferException::gatewayRefused(
                    $data->payoutReference,
                    'no open transfer lane with this anchor exists; nothing to fail',
                );
            }

            if (($record['lane_status'] ?? null) === 'failed') {
                return $record; // replay-safe
            }

            if (($record['lane_status'] ?? null) === 'completed') {
                throw PayoutTransferException::gatewayRefused(
                    $data->payoutReference,
                    'a completed transfer cannot be failed; reversals own money coming back',
                );
            }

            $payment = Payment::query()->lockForUpdate()
                ->where('reference_number', (string) $record['payment_reference'])
                ->first();

            if ($payment instanceof Payment) {
                $payment->status = PaymentStatus::Failed;
                $payment->failed_at = Carbon::now();
                $payment->failure_reason = $reason;
                $payment->save();
            }

            $payout->status = PayoutStatus::Failed;
            $payout->failed_at = Carbon::now();
            $payout->save();

            $record = array_merge($record, [
                'lane_status' => 'failed',
                'failure_reason' => $reason,
                'completed_at' => null,
            ]);
            $this->writeRecord($payout, $record);

            $this->recordAudit($payout, sprintf(
                'Payout transfer FAILED: %s %s (%s).',
                (string) ($record['amount'] ?? ''),
                (string) ($record['currency'] ?? ''),
                $reason,
            ), RiskLevel::High);

            return $record;
        });
    }

    /**
     * The transfer lane record for a payout, unshaped or null.
     *
     * @return array<string, mixed>|null
     */
    public function recordFor(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * Is there an open (uncompleted, unfailed) transfer lane on the payout?
     */
    public function hasOpenLane(Payout $payout): bool
    {
        $record = $this->recordFor($payout);

        return is_array($record) && ($record['lane_status'] ?? null) === 'open';
    }

    /**
     * Map the domain method onto the abstraction's payment method.
     */
    private function methodOf(PrizePayoutMethod $method): PaymentMethod
    {
        return match ($method) {
            PrizePayoutMethod::BankTransfer => PaymentMethod::BankTransfer,
            PrizePayoutMethod::Cheque => PaymentMethod::Manual,
            default => throw PayoutTransferException::gatewayRefused(
                'unknown',
                sprintf('payout transfer lane cannot execute method [%s]', $method->value),
            ),
        };
    }

    /**
     * The gateway name the payment row cites.
     */
    private function gatewayOf(PrizePayoutMethod $method): string
    {
        return match ($method) {
            PrizePayoutMethod::BankTransfer => 'bank_transfer',
            PrizePayoutMethod::Cheque => 'manual',
            default => 'manual',
        };
    }

    /**
     * Payment reference derives from the anchor: replays derive the same
     * row-name, never a fresh one.
     */
    private function derivePaymentReference(string $idempotencyKey): string
    {
        return 'PT-'.strtoupper(substr($idempotencyKey, 0, 20));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeRecord(Payout $payout, array $record): void
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $metadata[self::METADATA_KEY] = $record;

        $payout->metadata = $metadata;
        $payout->save();
    }

    private function recordAudit(Payout $payout, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => $riskLevel,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => $description,
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'lane' => self::METADATA_KEY,
                'action' => 'payout_transfer',
            ],
        ]);

        $log->save();
    }
}
