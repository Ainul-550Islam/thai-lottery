<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\DepositStatus;
use App\Exceptions\DepositException;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The review decision on a deposit. Credits nothing.
 *
 * WHY APPROVAL AND CREDITING ARE SEPARATE
 * ---------------------------------------
 * An approval is a statement by a human that the request looks legitimate. It is
 * NOT proof that the money arrived. Crediting on approval would mean that a
 * reviewer's click creates balance out of nothing, and a mistaken click could
 * never be distinguished from a real payment. So the pipeline is:
 *
 *     pending --approve--> approved --complete--> confirmed (wallet credited)
 *
 * This class implements only the first arrow. It contains no call to
 * WalletService, no call to FinancialTransactionService, no ledger insertion and
 * no balance arithmetic. DepositCompletionService owns the credit.
 *
 * NEVER CREDIT TWICE
 * Approval refuses outright if the deposit already carries a financial
 * transaction or is already confirmed, so an already-settled deposit can never be
 * pushed back into the approved state to be settled a second time. The second,
 * stronger guarantee lives in DepositCompletionService, where the credit is keyed
 * by a deterministic idempotency key derived from the deposit id: even two
 * simultaneous completions produce one transaction.
 *
 * SCHEMA LIMITATION (reported, not patched): `deposits` has NO `approved_at`,
 * `rejected_at`, `reviewed_by` or `reviewed_at` column. It has only
 * `confirmed_at`, `failed_at` and `failure_reason`. So the approval trail
 * (timestamp, actor, note) is recorded in the existing `metadata` JSON column, and
 * a rejection additionally stamps `failed_at` / `failure_reason`, which are the
 * closest real columns available. No column was invented and no migration was
 * added in this phase. A queryable, indexed deposit approval trail would need a
 * migration adding `approved_at`, `rejected_at` and `reviewed_by`.
 *
 * LOCK ORDER: WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS. The wallet is locked
 * first even though no balance changes, so that this service can never be the one
 * that inverts the order against a concurrent completion.
 */
final class DepositApprovalService
{
    public function __construct(
        private readonly WalletLockService $locks,
        private readonly FinancialStateTransitionService $transitions,
    ) {}

    /**
     * Approve a pending deposit. No money moves.
     *
     * @return array{deposit: Deposit, wallet: Wallet}
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function approve(Deposit $deposit, ?int $reviewerUserId = null, ?string $note = null): array
    {
        return $this->withinTransaction(function () use ($deposit, $reviewerUserId, $note): array {
            $wallet = $this->locks->lock((int) $deposit->wallet_id);
            $current = $this->transitions->lockDeposit($deposit);

            $this->assertWalletMatches($current, $wallet);
            $this->assertNotAlreadyCredited($current);

            if (! $current->status->canApprove()) {
                throw DepositException::notApprovable($current->id, $current->status);
            }

            $approved = $this->transitions->transitionDeposit($current, DepositStatus::Approved, [
                'metadata' => $this->mergeMetadata($current, array_filter([
                    'approved_at' => Carbon::now()->toIso8601String(),
                    'approved_by' => $reviewerUserId,
                    'approval_note' => $note,
                ], static fn ($value): bool => $value !== null)),
            ]);

            return [
                'deposit' => $approved,
                'wallet' => $wallet,
            ];
        });
    }

    /**
     * Refuse the deposit. No money moves, and none ever did.
     *
     * A rejection is only possible while the deposit is pending or approved -
     * that is, while nothing has been credited. Refusing an already-confirmed
     * deposit would mean taking money back, which requires a new reversing
     * transaction through FinancialReversalService, not a status change.
     *
     * @return array{deposit: Deposit, wallet: Wallet}
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function reject(Deposit $deposit, string $reason, ?int $reviewerUserId = null): array
    {
        return $this->withinTransaction(function () use ($deposit, $reason, $reviewerUserId): array {
            $wallet = $this->locks->lock((int) $deposit->wallet_id);
            $current = $this->transitions->lockDeposit($deposit);

            $this->assertWalletMatches($current, $wallet);
            $this->assertNotAlreadyCredited($current);

            if (! $current->status->canReject()) {
                throw DepositException::notRejectable($current->id, $current->status);
            }

            $rejected = $this->transitions->transitionDeposit($current, DepositStatus::Rejected, [
                'failed_at' => Carbon::now(),
                'failure_reason' => $reason,
                'metadata' => $this->mergeMetadata($current, array_filter([
                    'rejected_at' => Carbon::now()->toIso8601String(),
                    'rejected_by' => $reviewerUserId,
                    'rejection_reason' => $reason,
                ], static fn ($value): bool => $value !== null)),
            ]);

            return [
                'deposit' => $rejected,
                'wallet' => $wallet,
            ];
        });
    }

    /**
     * Mark an approved deposit as being settled.
     *
     * Optional. It exists so a long-running settlement can be observed from the
     * outside; DepositCompletionService accepts both `approved` and `processing`.
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function markProcessing(Deposit $deposit): Deposit
    {
        return $this->withinTransaction(function () use ($deposit): Deposit {
            $this->locks->lock((int) $deposit->wallet_id);
            $current = $this->transitions->lockDeposit($deposit);

            $this->assertNotAlreadyCredited($current);

            if (! in_array($current->status, [DepositStatus::Pending, DepositStatus::Approved], true)) {
                throw DepositException::notCompletable($current->id, $current->status);
            }

            return $this->transitions->transitionDeposit($current, DepositStatus::Processing, [
                'metadata' => $this->mergeMetadata($current, [
                    'processing_started_at' => Carbon::now()->toIso8601String(),
                ]),
            ]);
        });
    }

    /**
     * Whether the deposit may be approved right now, without throwing.
     */
    public function canApprove(Deposit $deposit): bool
    {
        return ! $deposit->isCredited()
            && $deposit->financial_transaction_id === null
            && $deposit->status->canApprove();
    }

    /**
     * Whether the deposit may be rejected right now, without throwing.
     */
    public function canReject(Deposit $deposit): bool
    {
        return ! $deposit->isCredited()
            && $deposit->financial_transaction_id === null
            && $deposit->status->canReject();
    }

    /**
     * The approval trail, read back out of metadata.
     *
     * Returns null when no decision has been recorded. Reflects the schema
     * limitation documented above: this data is JSON, not indexed columns.
     *
     * @return array{decision: string, at: string|null, by: int|null, note: string|null}|null
     */
    public function decisionTrail(Deposit $deposit): ?array
    {
        $metadata = is_array($deposit->metadata) ? $deposit->metadata : [];

        if (isset($metadata['rejected_at'])) {
            return [
                'decision' => DepositStatus::Rejected->value,
                'at' => is_string($metadata['rejected_at']) ? $metadata['rejected_at'] : null,
                'by' => isset($metadata['rejected_by']) ? (int) $metadata['rejected_by'] : null,
                'note' => isset($metadata['rejection_reason']) && is_string($metadata['rejection_reason'])
                    ? $metadata['rejection_reason']
                    : null,
            ];
        }

        if (isset($metadata['approved_at'])) {
            return [
                'decision' => DepositStatus::Approved->value,
                'at' => is_string($metadata['approved_at']) ? $metadata['approved_at'] : null,
                'by' => isset($metadata['approved_by']) ? (int) $metadata['approved_by'] : null,
                'note' => isset($metadata['approval_note']) && is_string($metadata['approval_note'])
                    ? $metadata['approval_note']
                    : null,
            ];
        }

        return null;
    }

    /**
     * A deposit that has money behind it must never re-enter the review flow.
     *
     * @throws DepositException
     */
    private function assertNotAlreadyCredited(Deposit $deposit): void
    {
        if ($deposit->status === DepositStatus::Confirmed || $deposit->financial_transaction_id !== null) {
            throw DepositException::alreadyCredited(
                (int) $deposit->getKey(),
                $deposit->financial_transaction_id,
            );
        }
    }

    /**
     * @throws DepositException
     */
    private function assertWalletMatches(Deposit $deposit, Wallet $wallet): void
    {
        if ((int) $deposit->wallet_id === (int) $wallet->getKey()) {
            return;
        }

        throw DepositException::walletMismatch(
            (int) $deposit->getKey(),
            (int) $deposit->wallet_id,
            (int) $wallet->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $additions
     * @return array<string, mixed>
     */
    private function mergeMetadata(Deposit $deposit, array $additions): array
    {
        $existing = is_array($deposit->metadata) ? $deposit->metadata : [];

        return array_merge($existing, $additions);
    }

    private function withinTransaction(\Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback);
    }
}
