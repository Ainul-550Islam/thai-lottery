<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\LedgerAccountStatus;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of ledger_entries.
 *
 * RESPONSIBILITY
 * Turn a validated set of entry drafts into immutable ledger rows for one
 * financial transaction, and roll the affected account balances forward in the
 * same atomic step.
 *
 * INVARIANTS ENFORCED HERE
 * 1. Runs only inside the caller's transaction. It never opens or commits one,
 *    because the account row locks it takes must live until the caller commits.
 * 2. Debits must equal credits (delegated to LedgerBalanceValidator) before any
 *    row is written, and re-verified from the persisted rows afterwards.
 * 3. A transaction may be posted exactly once. The audited schema has no unique
 *    constraint for this, so the guard is an application check performed while
 *    holding the account locks - see the schema note below.
 * 4. Accounts are locked in ascending id order, so two concurrent postings
 *    touching the same accounts always take them in the same sequence and cannot
 *    deadlock against each other.
 * 5. Posted entries are append-only. This class contains no update and no delete
 *    path for ledger_entries; a mistake is corrected by posting a reversal.
 *
 * SCHEMA NOTE (reported, not patched)
 * ledger_entries has no unique index on financial_transaction_id, so duplicate
 * posting is prevented by the check in assertNotAlreadyPosted() executed under
 * the account locks rather than by the database. Adding a partial unique index
 * would be a migration change and migrations are out of scope for this phase.
 */
final class LedgerPostingService
{
    public function __construct(
        private readonly LedgerBalanceValidator $validator,
    ) {
    }

    /**
     * Post a balanced double-entry set for the given transaction.
     *
     * @param  array<int, array<string, mixed>>  $entries  drafts as accepted by LedgerBalanceValidator
     * @param  array<int, string>  $balancesAfter  optional wallet balance snapshot keyed by entry index
     * @return list<LedgerEntry> the created rows, in the order supplied
     *
     * @throws FinancialException
     */
    public function post(FinancialTransaction $transaction, array $entries, array $balancesAfter = []): array
    {
        $this->assertInsideTransaction();
        $this->assertTransactionPostable($transaction);
        $this->assertNotAlreadyPosted($transaction);

        $normalised = $this->validator->validate($entries, $transaction->currency);

        $accounts = $this->lockAccounts($this->accountIdsFor($normalised), $transaction);

        $postedAt = Carbon::now();
        $created = [];

        foreach ($normalised as $index => $entry) {
            /** @var LedgerAccount $account */
            $account = $accounts[$entry['ledger_account_id']];

            $accountBalanceAfter = $this->applyToAccountBalance($account, $entry['type'], $entry['amount']);

            $created[] = $this->writeEntry(
                $transaction,
                $entry,
                $postedAt,
                $balancesAfter[$index] ?? $accountBalanceAfter->toString(),
            );
        }

        // Read the rows back out of the database and re-verify the invariant on
        // what was actually stored, not on what we intended to store. If the
        // stored rows do not balance, the exception rolls the caller back.
        $this->validator->assertTransactionBalanced($transaction->fresh() ?? $transaction);

        return $created;
    }

    /**
     * Look up a chart-of-accounts entry by its code.
     *
     * The chart of accounts is seeded outside this phase, so a missing account is
     * reported as an explicit, actionable failure rather than being created on
     * the fly. Auto-creating accounts would let a typo invent a real account and
     * silently absorb money.
     *
     * @throws FinancialException
     */
    public function resolveAccount(string $code): LedgerAccount
    {
        $account = LedgerAccount::query()->where('code', $code)->first();

        if (! $account instanceof LedgerAccount) {
            throw FinancialException::withCode(
                'ledger_account_missing',
                sprintf(
                    'Ledger account "%s" is not present in the chart of accounts. '
                    .'Seed the chart of accounts before posting financial transactions.',
                    $code,
                ),
                ['ledger_account_code' => $code],
            );
        }

        if (! $this->statusOf($account)->canPost()) {
            throw FinancialException::withCode(
                'ledger_account_not_postable',
                sprintf('Ledger account "%s" is %s and cannot receive postings.', $code, $this->statusOf($account)->value),
                ['ledger_account_code' => $code, 'ledger_account_status' => $this->statusOf($account)->value],
            );
        }

        return $account;
    }

    /**
     * Whether the transaction already has ledger entries.
     */
    public function hasPosting(FinancialTransaction $transaction): bool
    {
        return LedgerEntry::query()
            ->withTrashed()
            ->where('financial_transaction_id', $transaction->getKey())
            ->exists();
    }

    /**
     * The entries already posted for a transaction, oldest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, LedgerEntry>
     */
    public function postedEntriesFor(FinancialTransaction $transaction)
    {
        return LedgerEntry::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Derived status of an account, from the is_active flag and soft delete.
     *
     * The audited ledger_accounts table has no status column; this keeps the
     * posting rules expressed in one vocabulary without changing the schema.
     */
    public function statusOf(LedgerAccount $account): LedgerAccountStatus
    {
        return LedgerAccountStatus::fromFlags(
            (bool) $account->is_active,
            $account->trashed(),
        );
    }

    /**
     * @param  list<array{ledger_account_id: int}>  $entries
     * @return list<int>
     */
    private function accountIdsFor(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            $ids[$entry['ledger_account_id']] = true;
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Lock every affected account row in ascending id order.
     *
     * @param  list<int>  $accountIds
     * @return array<int, LedgerAccount> keyed by account id
     *
     * @throws FinancialException
     */
    private function lockAccounts(array $accountIds, FinancialTransaction $transaction): array
    {
        sort($accountIds, SORT_NUMERIC);

        /** @var array<int, LedgerAccount> $accounts */
        $accounts = LedgerAccount::query()
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($accountIds as $id) {
            if (! isset($accounts[$id])) {
                throw FinancialException::withCode(
                    'ledger_account_missing',
                    sprintf('Ledger account %d does not exist or has been deleted.', $id),
                    ['ledger_account_id' => $id],
                );
            }

            $account = $accounts[$id];

            if (! $this->statusOf($account)->canPost()) {
                throw FinancialException::withCode(
                    'ledger_account_not_postable',
                    sprintf(
                        'Ledger account %s (%d) is %s and cannot receive postings.',
                        $account->code,
                        $id,
                        $this->statusOf($account)->value,
                    ),
                    ['ledger_account_id' => $id, 'ledger_account_code' => $account->code],
                );
            }

            if ($account->currency !== $transaction->currency) {
                throw FinancialException::withCode(
                    'ledger_account_currency_mismatch',
                    sprintf(
                        'Ledger account %s is denominated in %s but the transaction is in %s.',
                        $account->code,
                        $account->currency->value,
                        $transaction->currency->value,
                    ),
                    [
                        'ledger_account_id' => $id,
                        'account_currency' => $account->currency->value,
                        'transaction_currency' => $transaction->currency->value,
                    ],
                );
            }
        }

        return $accounts;
    }

    /**
     * Roll an account's cached balance forward and persist it.
     *
     * The direction depends on the account's normal balance: a debit increases an
     * asset or expense account and decreases a liability, equity or revenue
     * account. Arithmetic is exact (bcmath via Money).
     *
     * @throws FinancialException
     */
    private function applyToAccountBalance(LedgerAccount $account, LedgerEntryType $side, Money $amount): Money
    {
        $current = Money::fromDatabase((string) $account->current_balance, $account->currency);

        $next = $account->type->increasesWith($side)
            ? $current->plus($amount)
            : $current->minus($amount);

        // current_balance is intentionally not mass assignable on the model, so
        // it is set directly here: this service is the only permitted writer.
        $account->current_balance = $next->toString();
        $account->save();

        return $next;
    }

    /**
     * Persist one immutable ledger row.
     *
     * @param  array{
     *     ledger_account_id: int,
     *     type: LedgerEntryType,
     *     amount: Money,
     *     wallet_id: int|null,
     *     description: string|null,
     *     reference_type: string|null,
     *     reference_id: int|null,
     *     metadata: array<string, mixed>|null
     * }  $entry
     */
    private function writeEntry(
        FinancialTransaction $transaction,
        array $entry,
        Carbon $postedAt,
        ?string $balanceAfter,
    ): LedgerEntry {
        $ledgerEntry = new LedgerEntry();

        $ledgerEntry->fill([
            'ledger_account_id' => $entry['ledger_account_id'],
            'financial_transaction_id' => (int) $transaction->getKey(),
            'wallet_id' => $entry['wallet_id'] ?? $transaction->wallet_id,
            'type' => $entry['type'],
            'amount' => $entry['amount']->toString(),
            'currency' => $transaction->currency,
            'description' => $entry['description'] ?? $transaction->description,
            'reference_type' => $entry['reference_type'] ?? $transaction->reference_type,
            'reference_id' => $entry['reference_id'] ?? $transaction->reference_id,
            'metadata' => $entry['metadata'],
            'posted_at' => $postedAt,
        ]);

        // balance_after is deliberately excluded from the model's $fillable so
        // that no request payload can reach it; only this service sets it.
        $ledgerEntry->balance_after = $balanceAfter;

        $ledgerEntry->save();

        return $ledgerEntry;
    }

    /**
     * @throws FinancialException
     */
    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw FinancialException::withCode(
                'ledger_posting_outside_transaction',
                'Refusing to post ledger entries outside a database transaction: entry '
                .'creation and account balance updates must commit or roll back together.',
            );
        }
    }

    /**
     * The transaction must be persisted and still in flight.
     *
     * @throws FinancialException
     */
    private function assertTransactionPostable(FinancialTransaction $transaction): void
    {
        if (! $transaction->exists) {
            throw FinancialException::withCode(
                'ledger_posting_unpersisted_transaction',
                'Cannot post ledger entries for a financial transaction that has not been persisted.',
            );
        }

        $allowed = [TransactionStatus::Pending, TransactionStatus::Processing];

        if (! in_array($transaction->status, $allowed, true)) {
            throw FinancialException::withCode(
                'ledger_posting_invalid_transaction_status',
                sprintf(
                    'Financial transaction %d is %s; ledger entries may only be posted '
                    .'while a transaction is pending or processing.',
                    (int) $transaction->getKey(),
                    $transaction->status->value,
                ),
                [
                    'transaction_id' => (int) $transaction->getKey(),
                    'transaction_status' => $transaction->status->value,
                ],
            );
        }
    }

    /**
     * @throws FinancialException
     */
    private function assertNotAlreadyPosted(FinancialTransaction $transaction): void
    {
        if ($this->hasPosting($transaction)) {
            throw FinancialException::withCode(
                'ledger_duplicate_posting',
                sprintf(
                    'Financial transaction %d already has ledger entries; posting twice '
                    .'would double the money movement.',
                    (int) $transaction->getKey(),
                ),
                ['transaction_id' => (int) $transaction->getKey()],
            );
        }
    }
}
