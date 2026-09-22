<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\AuditAction;
use App\Enums\DepositStatus;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Chargeback / refund of a CONFIRMED deposit.
 *
 * A refund is the operations-side unwind of money the platform already
 * accepted: the payment gateway has given the money back to the player's
 * source (card, wallet, bank), so the platform's books must undo the deposit
 * credit. The mechanism is never a partial edit of the original rows — it is
 * a compensating financial transaction posted through
 * FinancialReversalService, which creates the mirrored balanced ledger
 * posting, debits the wallet back, and marks the original transaction
 * Reversed. This service is the domain wrapper around that engine: it owns
 * the DEPOSIT-side invariants (which deposit may be refunded, when, how its
 * lifecycle stamp changes, what audit line is written).
 *
 * THE RULES
 * ---------
 * 1. ONLY a Confirmed deposit can be refunded. Pending/Approved deposits have
 *    no money behind them yet (they get rejected, not refunded); Failed,
 *    Cancelled and Rejected deposits never settled; an already Refunded
 *    deposit replays idempotently instead of refunding twice.
 * 2. THE CREDIT MUST BE REVERSIBLE — the wallet debit the reversal performs
 *    can legitimately fail with InsufficientBalanceException, and that
 *    propagates as-is: a refund operation never invents money to give back.
 * 3. WHOLE run, one transaction: lock deposit row → assert → reverse credit →
 *    stamp Refunded → audit. A failure anywhere rolls every step back.
 * 4. IDEMPOTENT by constructor deposit id: refunding the same deposit twice
 *    returns the recorded outcome; a reversal race loses on the reversal key.
 */
class RefundService
{
    public function __construct(
        private readonly FinancialReversalService $reversals,
    ) {
    }

    /**
     * Refund a confirmed deposit by reversing its settled credit.
     *
     * @return array{
     *     deposit_id: int,
     *     deposit_reference: string,
     *     original_transaction_id: int,
     *     reversal_transaction_id: int,
     *     amount: string,
     *     currency: string,
     *     status: string,
     *     already_refunded: bool,
     *     refunded_at: string
     * }
     *
     * @throws FinancialException
     */
    public function refundDeposit(int $depositId, ?int $actorUserId = null, ?string $reason = null): array
    {
        if (DB::transactionLevel() > 0) {
            // The rollback guarantee must belong to this refund. Inside a
            // caller's transaction a failure here could be swallowed and the
            // partial refund committed.
            throw FinancialException::withCode(
                'refund_already_running',
                sprintf('Refunds own their transaction boundary; caller is at transaction level %d.', DB::transactionLevel()),
                ['deposit_id' => $depositId, 'transaction_level' => DB::transactionLevel()],
            );
        }

        return DB::transaction(function () use ($depositId, $actorUserId, $reason): array {
            /** @var Deposit|null $deposit */
            $deposit = Deposit::query()->lockForUpdate()->find($depositId);

            if (! $deposit instanceof Deposit) {
                throw FinancialException::withCode(
                    'refund_deposit_not_found',
                    sprintf('Deposit #%d does not exist and cannot be refunded.', $depositId),
                    ['deposit_id' => $depositId],
                );
            }

            // Idempotent replay: the deposit was already refunded.
            if ($deposit->status === DepositStatus::Refunded) {
                $original = $deposit->financialTransaction;
                $reversal = $original instanceof FinancialTransaction
                    ? $this->reversals->existingReversalFor($original)
                    : null;

                return $this->result(
                    deposit: $deposit,
                    original: $original,
                    reversal: $reversal,
                    alreadyRefunded: true,
                );
            }

            if ($deposit->status !== DepositStatus::Confirmed) {
                throw FinancialException::withCode(
                    'refund_deposit_not_settled',
                    sprintf(
                        'Deposit #%d (%s) is %s — only a confirmed deposit can be refunded; pending ones are rejected, failed ones need nothing.',
                        $depositId,
                        (string) $deposit->reference_number,
                        $deposit->status->value,
                    ),
                    [
                        'deposit_id' => $depositId,
                        'deposit_reference' => (string) $deposit->reference_number,
                        'deposit_status' => $deposit->status->value,
                    ],
                );
            }

            $original = $deposit->financialTransaction;

            if (! $original instanceof FinancialTransaction) {
                // A confirmed deposit with no settled credit is a data-level
                // contradiction; refuse rather than invent a reversal amount.
                throw FinancialException::withCode(
                    'refund_missing_original_credit',
                    sprintf(
                        'Deposit #%d (%s) is Confirmed but carries no financial transaction; a refund would compensate nothing that exists.',
                        $depositId,
                        (string) $deposit->reference_number,
                    ),
                    [
                        'deposit_id' => $depositId,
                        'deposit_reference' => (string) $deposit->reference_number,
                    ],
                );
            }

            // Engine-side assert covers: type reversibility, prior reversal
            // consumed, and transaction status Completed.
            $this->reversals->assertReversible($original);

            $reversal = $this->reversals->reverse(
                original: $original,
                actorUserId: $actorUserId,
                idempotencyKey: sprintf('refund-deposit-%d', $depositId),
                reason: $reason ?? sprintf('Chargeback refund of deposit %s', (string) $deposit->reference_number),
            );

            // Lifecycle stamp: direct assignment, the fillable list excludes
            // status on purpose so no other flow can fake the refund.
            $deposit->status = DepositStatus::Refunded;
            $deposit->save();

            $this->recordAudit($deposit, $original, $reversal, $actorUserId, $reason);

            return $this->result(
                deposit: $deposit,
                original: $original,
                reversal: $reversal,
                alreadyRefunded: false,
            );
        });
    }

    /**
     * Whether this deposit could be refunded right now, without attempting it.
     */
    public function canRefund(Deposit $deposit): bool
    {
        if ($deposit->status !== DepositStatus::Confirmed) {
            return false;
        }

        $original = $deposit->financialTransaction;

        return $original instanceof FinancialTransaction
            && $this->reversals->canReverse($original);
    }

    /**
     * The canonical result row, identical whether the run executed or replayed.
     *
     * @return array{
     *     deposit_id: int,
     *     deposit_reference: string,
     *     original_transaction_id: int,
     *     reversal_transaction_id: int,
     *     amount: string,
     *     currency: string,
     *     status: string,
     *     already_refunded: bool,
     *     refunded_at: string
     * }
     */
    private function result(
        Deposit $deposit,
        ?FinancialTransaction $original,
        ?FinancialTransaction $reversal,
        bool $alreadyRefunded,
    ): array {
        return [
            'deposit_id' => (int) $deposit->getKey(),
            'deposit_reference' => (string) $deposit->reference_number,
            'original_transaction_id' => $original ? (int) $original->getKey() : 0,
            'reversal_transaction_id' => $reversal ? (int) $reversal->getKey() : 0,
            'amount' => (string) $deposit->net_amount,
            'currency' => $deposit->currency->value,
            'status' => $deposit->status->value,
            'already_refunded' => $alreadyRefunded,
            'refunded_at' => ($deposit->updated_at ?? $deposit->created_at)?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    /**
     * A refund is a high-risk money operation: the audit line names actor,
     * original, and compensating transaction so treasury can trace exactly
     * what left the platform and why.
     */
    private function recordAudit(
        Deposit $deposit,
        FinancialTransaction $original,
        FinancialTransaction $reversal,
        ?int $actorUserId,
        ?string $reason,
    ): void {
        $log = new AuditLog();

        $log->fill([
            'user_id' => $actorUserId,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::High,
            'auditable_type' => Deposit::class,
            'auditable_id' => $deposit->getKey(),
            'description' => sprintf(
                'Deposit %s refunded: original transaction #%d compensated by reversal transaction #%d for %s %s.',
                (string) $deposit->reference_number,
                (int) $original->getKey(),
                (int) $reversal->getKey(),
                (string) $deposit->net_amount,
                $deposit->currency->value,
            ),
            'metadata' => [
                'deposit_id' => (int) $deposit->getKey(),
                'deposit_reference' => (string) $deposit->reference_number,
                'original_transaction_id' => (int) $original->getKey(),
                'reversal_transaction_id' => (int) $reversal->getKey(),
                'amount' => (string) $deposit->net_amount,
                'currency' => $deposit->currency->value,
                'reason' => $reason,
            ],
        ]);

        $log->save();
    }
}
