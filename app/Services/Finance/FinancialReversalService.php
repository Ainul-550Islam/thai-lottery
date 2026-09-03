<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reverses a completed financial transaction by compensation, never by erasure.
 *
 * THE RULE
 * A posted ledger entry is history. This service never updates and never deletes
 * an original entry, and never rewrites the original transaction's monetary
 * fields. It creates a NEW transaction of type reversal whose entries are the
 * exact mirror of the original - every debit becomes a credit of the same amount
 * against the same account, and vice versa - so both movements remain visible and
 * the ledger still balances when the two are summed.
 *
 * The only change made to the original record is its lifecycle marking:
 * status becomes `reversed`, and reversed_at / reversed_by are stamped. Those are
 * exactly the fields the audited schema provides for this purpose.
 *
 * GUARDS
 * - only a persisted, `completed` transaction may be reversed;
 * - a transaction may be reversed at most once;
 * - a reversal itself can never be reversed (FinancialTransactionType::Reversal
 *   is not reversible), so there is no way to build a chain of compensations
 *   that cancel each other into ambiguity.
 *
 * ATOMICITY AND IDEMPOTENCY
 * The whole reversal runs in one transaction under a row lock on the wallet. Its
 * idempotency key is derived deterministically from the original transaction id,
 * so a retried reversal collides with itself on the unique index and returns the
 * reversal that already exists rather than reversing twice.
 */
final class FinancialReversalService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly WalletLockService $locks,
        private readonly LedgerPostingService $ledger,
        private readonly WalletService $wallets,
        private readonly LedgerBalanceValidator $validator,
        private readonly FinancialTransactionService $transactions,
    ) {
    }

    /**
     * Reverse a completed transaction.
     *
     * @param  int|null  $actorUserId  the user recorded in reversed_by
     *
     * @throws FinancialException
     */
    public function reverse(
        FinancialTransaction $original,
        ?int $actorUserId = null,
        ?string $idempotencyKey = null,
        ?string $reason = null,
    ): FinancialTransaction {
        Money::assertExactArithmeticIsAvailable();

        $this->assertReversible($original);

        $key = $idempotencyKey ?? $this->reversalKeyFor($original);

        return $this->transactions->executeWithDeadlockRetry(
            fn (): FinancialTransaction => DB::transaction(
                fn (): FinancialTransaction => $this->performReversal($original, $actorUserId, $key, $reason),
            ),
        );
    }

    /**
     * Whether this transaction may be reversed right now.
     */
    public function canReverse(FinancialTransaction $transaction): bool
    {
        if (! $transaction->exists) {
            return false;
        }

        if ($transaction->status !== TransactionStatus::Completed) {
            return false;
        }

        if (! FinancialTransactionType::fromTransactionType($transaction->type)->isReversible()) {
            return false;
        }

        return ! $this->hasBeenReversed($transaction);
    }

    /**
     * The reversal that already compensates this transaction, if one exists.
     *
     * The audited schema has no reversal_of column, so the link is the
     * polymorphic reference: the reversal points at the original transaction
     * through reference_type / reference_id.
     */
    public function existingReversalFor(FinancialTransaction $original): ?FinancialTransaction
    {
        /** @var FinancialTransaction|null $reversal */
        $reversal = FinancialTransaction::query()
            ->where('reference_type', FinancialTransaction::class)
            ->where('reference_id', (int) $original->getKey())
            ->where('type', FinancialTransactionType::Reversal->toTransactionType())
            ->first();

        return $reversal;
    }

    /**
     * The deterministic idempotency key for reversing this transaction.
     */
    public function reversalKeyFor(FinancialTransaction $original): string
    {
        return $this->idempotency->deterministicKey('reversal', (string) $original->getKey());
    }

    /**
     * @throws FinancialException
     */
    public function assertReversible(FinancialTransaction $original): void
    {
        if (! $original->exists) {
            throw FinancialException::withCode(
                'reversal_transaction_not_persisted',
                'Cannot reverse a financial transaction that has not been persisted.',
            );
        }

        $engineType = FinancialTransactionType::fromTransactionType($original->type);

        if (! $engineType->isReversible()) {
            throw FinancialException::withCode(
                'reversal_not_permitted_for_type',
                sprintf(
                    'A %s transaction cannot be reversed; reversing a reversal would make '
                    .'the audit trail ambiguous.',
                    $original->type->value,
                ),
                [
                    'transaction_id' => (int) $original->getKey(),
                    'transaction_type' => $original->type->value,
                ],
            );
        }

        if ($original->status !== TransactionStatus::Completed) {
            throw FinancialException::withCode(
                'reversal_requires_completed_transaction',
                sprintf(
                    'Financial transaction %d is %s; only a completed transaction can be reversed.',
                    (int) $original->getKey(),
                    $original->status->value,
                ),
                [
                    'transaction_id' => (int) $original->getKey(),
                    'transaction_status' => $original->status->value,
                ],
            );
        }

        if ($this->hasBeenReversed($original)) {
            throw FinancialException::withCode(
                'reversal_already_exists',
                sprintf('Financial transaction %d has already been reversed.', (int) $original->getKey()),
                ['transaction_id' => (int) $original->getKey()],
            );
        }
    }

    /**
     * Body of the reversal, always inside a transaction.
     *
     * @throws FinancialException
     */
    private function performReversal(
        FinancialTransaction $original,
        ?int $actorUserId,
        string $key,
        ?string $reason,
    ): FinancialTransaction {
        // Re-read the original under a lock so a concurrent reversal of the same
        // transaction cannot slip between the check and the write.
        /** @var FinancialTransaction|null $locked */
        $locked = FinancialTransaction::query()
            ->whereKey($original->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof FinancialTransaction) {
            throw FinancialException::withCode(
                'reversal_transaction_not_found',
                sprintf('Financial transaction %d no longer exists.', (int) $original->getKey()),
                ['transaction_id' => (int) $original->getKey()],
            );
        }

        $this->assertReversible($locked);

        $originalEntries = $this->ledger->postedEntriesFor($locked);

        if ($originalEntries->isEmpty()) {
            throw FinancialException::withCode(
                'reversal_original_has_no_entries',
                sprintf(
                    'Financial transaction %d has no ledger entries to mirror; refusing to '
                    .'invent a reversal posting.',
                    (int) $locked->getKey(),
                ),
                ['transaction_id' => (int) $locked->getKey()],
            );
        }

        $wallet = $locked->wallet_id !== null
            ? $this->locks->lock((int) $locked->wallet_id)
            : null;

        $expectation = [
            'type' => FinancialTransactionType::Reversal,
            'amount' => (string) $locked->amount,
            'currency' => $locked->currency->value,
            'wallet_id' => $locked->wallet_id,
            'user_id' => $locked->user_id,
        ];

        $claim = $this->idempotency->claim(
            $key,
            $expectation,
            fn (string $claimedKey): FinancialTransaction => $this->createReversalTransaction(
                $locked,
                $claimedKey,
                $reason,
            ),
        );

        /** @var FinancialTransaction $reversal */
        $reversal = $claim['transaction'];

        if ($claim['replayed'] === true) {
            // The reversal already happened exactly once.
            return $reversal;
        }

        $this->transactions->transitionTo($reversal, TransactionStatus::Processing)->save();

        $walletSide = $wallet instanceof Wallet
            ? $this->walletSideOf($originalEntries, (int) $wallet->getKey())
            : null;

        $balanceAfter = null;

        if ($wallet instanceof Wallet && $walletSide !== null) {
            $balanceAfter = $this->wallets->applyReversal(
                $wallet,
                Money::fromDatabase((string) $locked->amount, $locked->currency),
                $walletSide,
            );
        }

        $mirrored = $this->mirrorEntries($originalEntries, $locked);

        $this->ledger->post(
            $reversal,
            $mirrored,
            $balanceAfter === null ? [] : [0 => $balanceAfter->toString()],
        );

        $this->validator->assertTransactionBalanced($reversal);

        $this->transactions->transitionTo($reversal, TransactionStatus::Completed);
        $reversal->processed_at = Carbon::now();
        $reversal->save();

        // Mark the original. Only lifecycle fields change: amount, fee, type,
        // currency and every original ledger entry are left byte-for-byte intact.
        $this->transactions->transitionTo($locked, TransactionStatus::Reversed);
        $locked->reversed_at = Carbon::now();
        $locked->reversed_by = $actorUserId;
        $locked->save();

        return $reversal->refresh();
    }

    /**
     * Create the compensating transaction record.
     *
     * reference_type / reference_id point back at the original, which is how the
     * pair is discoverable in both directions without a schema change.
     */
    private function createReversalTransaction(
        FinancialTransaction $original,
        string $key,
        ?string $reason,
    ): FinancialTransaction {
        $metadata = [
            'engine_type' => FinancialTransactionType::Reversal->value,
            'reversal_of_transaction_id' => (int) $original->getKey(),
            'reversal_of_reference_number' => $original->reference_number,
            'reversal_of_type' => $original->type->value,
        ];

        if ($reason !== null) {
            $metadata['reversal_reason'] = $reason;
        }

        $reversal = new FinancialTransaction();

        $reversal->fill([
            'reference_number' => $this->transactions->generateReferenceNumber(),
            'user_id' => $original->user_id,
            'wallet_id' => $original->wallet_id,
            'type' => FinancialTransactionType::Reversal->toTransactionType(),
            'currency' => $original->currency,
            'amount' => (string) $original->amount,
            'fee' => Money::zero($original->currency)->toString(),
            'description' => sprintf('Reversal of %s', $original->reference_number),
            'metadata' => $metadata,
            'idempotency_key' => $key,
            'reference_type' => FinancialTransaction::class,
            'reference_id' => (int) $original->getKey(),
        ]);

        $reversal->uuid = (string) Str::uuid();
        $reversal->status = TransactionStatus::Pending;

        $reversal->save();

        return $reversal;
    }

    /**
     * Build the mirror of the original posting.
     *
     * Each original entry produces one entry against the SAME account for the
     * SAME amount on the OPPOSITE side. Because the original set balanced, the
     * mirror balances too, and the two sets sum to zero per account.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, LedgerEntry>  $originalEntries
     * @return array<int, array<string, mixed>>
     */
    private function mirrorEntries($originalEntries, FinancialTransaction $original): array
    {
        $mirrored = [];

        foreach ($originalEntries as $entry) {
            $mirrored[] = [
                'ledger_account_id' => (int) $entry->ledger_account_id,
                'type' => $entry->type->opposite(),
                'amount' => Money::fromDatabase((string) $entry->amount, $original->currency),
                'wallet_id' => $entry->wallet_id === null ? null : (int) $entry->wallet_id,
                'description' => sprintf('Reversal of %s', $original->reference_number),
                'reference_type' => FinancialTransaction::class,
                'reference_id' => (int) $original->getKey(),
                'metadata' => ['reversal_of_ledger_entry_id' => (int) $entry->getKey()],
            ];
        }

        // Keep the wallet-facing entry first so a balance snapshot supplied by the
        // caller lines up with index 0, exactly as in a forward posting.
        usort($mirrored, static fn (array $a, array $b): int => ($b['wallet_id'] === null ? 0 : 1) <=> ($a['wallet_id'] === null ? 0 : 1));

        return $mirrored;
    }

    /**
     * Which side the original posting applied to the wallet.
     *
     * Read from the persisted entries rather than inferred from the type, so the
     * compensation always matches what actually happened.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, LedgerEntry>  $entries
     */
    private function walletSideOf($entries, int $walletId): ?LedgerEntryType
    {
        foreach ($entries as $entry) {
            if ((int) $entry->wallet_id === $walletId) {
                return $entry->type;
            }
        }

        return null;
    }

    /**
     * A transaction is considered reversed when it is marked as such or when a
     * compensating transaction already points at it. Both are checked, so a
     * partially recorded reversal can never be duplicated.
     */
    private function hasBeenReversed(FinancialTransaction $transaction): bool
    {
        if ($transaction->status === TransactionStatus::Reversed || $transaction->reversed_at !== null) {
            return true;
        }

        return $this->existingReversalFor($transaction) !== null;
    }
}
