<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Exceptions\BetPurchaseException;
use App\Enums\LedgerEntryType;
use App\Exceptions\FinancialException;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the double-entry ledger for a bet debit. It does NOT post it.
 *
 * WHY THIS CLASS DELIBERATELY DOES NOT POST ANYTHING
 * The ledger for a bet debit is already posted, inside the same database transaction,
 * by Phase 2.1's App\Services\Finance\FinancialTransactionService::execute(): it locks
 * the ledger accounts, writes the debit and credit legs through
 * App\Services\Finance\LedgerPostingService::post() and then asserts the transaction
 * balances before it will complete. Posting again here would be a genuine accounting
 * bug, not a harmless duplicate - the stake would appear twice in the ledger, the
 * account balances would double-count it, and every report built on the ledger would be
 * wrong. Phase 2.1's posting service also refuses to post twice for one transaction, so
 * a second attempt would abort the purchase outright.
 *
 * So the purchase's ledger obligation is not "write the entries". It is "prove the
 * entries the money engine wrote are there, are linked to this bet, and balance" - and
 * to refuse to commit if they are not. That is what this class does.
 *
 * WHY VERIFICATION IS WORTH A CLASS OF ITS OWN
 * A purchase that commits a wallet debit with no ledger movement, or with an unbalanced
 * one, is silent financial corruption: the money has moved and the books do not say so.
 * Because these assertions run INSIDE the purchase transaction, a failure rolls the
 * debit, the bet, the item, the ticket and the risk reservation back together. The
 * invariant is enforced structurally rather than trusted.
 *
 * WHY THE ENTRIES DO NOT NEED A REFERENCE WRITTEN ONTO THEM
 * Phase 2.1's LedgerPostingService copies reference_type and reference_id from the
 * financial transaction onto every entry it writes. The purchase sets those on the
 * transaction to the Bet, so the entries already carry the link, and Bet::ledgerEntries()
 * resolves without this phase updating a single ledger row. No ledger row is ever
 * mutated by Phase 4.3.
 */
final class BetPurchaseLedgerService
{
    /**
     * A double-entry posting has at least two legs. Fewer means the posting is
     * incomplete, whatever the amounts say.
     */
    public const MINIMUM_ENTRIES = 2;

    public function __construct(
        private readonly LedgerPostingService $posting,
        private readonly LedgerBalanceValidator $balances,
    ) {
    }

    /**
     * Assert the ledger for a bet debit exists, is linked to the bet and balances.
     *
     * @return list<int> the ledger entry ids, for reporting
     *
     * @throws BetPurchaseException
     */
    public function assertPostedForBet(FinancialTransaction $transaction, Bet $bet): array
    {
        $this->assertInsideTransaction();

        $transactionId = (int) $transaction->getKey();

        if (! $this->posting->hasPosting($transaction)) {
            throw BetPurchaseException::ledgerNotBalanced(
                $transactionId,
                'the wallet debit committed no ledger entries at all',
                ['bet_id' => (int) $bet->getKey()],
            );
        }

        $entries = $this->posting->postedEntriesFor($transaction);
        $count = $entries->count();

        if ($count < self::MINIMUM_ENTRIES) {
            throw BetPurchaseException::ledgerNotBalanced(
                $transactionId,
                sprintf(
                    'the wallet debit posted %d ledger entr%s, and a double-entry posting requires at least %d',
                    $count,
                    $count === 1 ? 'y' : 'ies',
                    self::MINIMUM_ENTRIES,
                ),
                ['bet_id' => (int) $bet->getKey(), 'ledger_entry_count' => $count],
            );
        }

        // Balance is re-validated from the database rather than from anything this
        // process holds in memory, so the assertion cannot be satisfied by a stale or
        // optimistic in-memory view of the entries.
        try {
            $this->balances->assertTransactionBalanced($transaction);
        } catch (FinancialException $exception) {
            throw BetPurchaseException::ledgerNotBalanced(
                $transactionId,
                sprintf('the ledger for the wallet debit does not balance: %s', $exception->getMessage()),
                ['bet_id' => (int) $bet->getKey(), 'ledger_entry_count' => $count],
                $exception,
            );
        }

        $this->assertLinkedToBet($entries, $bet, $transactionId);

        return $entries
            ->map(static fn (LedgerEntry $entry): int => (int) $entry->getKey())
            ->values()
            ->all();
    }

    /**
     * The debit and credit totals of a transaction's posting, read back from the rows
     * that were actually written, for reporting.
     *
     * Summation is bcmath at the currency's own scale. No float is involved, so the two
     * sides can be compared for exact equality rather than for approximate closeness.
     *
     * @return array<string, string>
     */
    public function totalsFor(FinancialTransaction $transaction): array
    {
        $currency = $transaction->currency instanceof \App\Enums\Currency
            ? $transaction->currency
            : \App\Enums\Currency::from((string) $transaction->currency);

        $scale = $currency->scale();
        $debit = '0';
        $credit = '0';

        foreach ($this->posting->postedEntriesFor($transaction) as $entry) {
            if (! $entry instanceof LedgerEntry) {
                continue;
            }

            $amount = (string) $entry->amount;
            $type = $entry->type instanceof LedgerEntryType
                ? $entry->type
                : LedgerEntryType::tryFrom((string) $entry->type);

            if ($type === LedgerEntryType::Debit) {
                $debit = bcadd($debit, $amount, $scale);

                continue;
            }

            if ($type === LedgerEntryType::Credit) {
                $credit = bcadd($credit, $amount, $scale);
            }
        }

        return [
            'debit' => bcadd($debit, '0', $scale),
            'credit' => bcadd($credit, '0', $scale),
            'balanced' => bccomp($debit, $credit, $scale) === 0 ? 'yes' : 'no',
        ];
    }

    /**
     * The ledger entries already posted for a transaction, for replay reporting.
     *
     * @return list<int>
     */
    public function entryIdsFor(FinancialTransaction $transaction): array
    {
        return $this->posting->postedEntriesFor($transaction)
            ->map(static fn (LedgerEntry $entry): int => (int) $entry->getKey())
            ->values()
            ->all();
    }

    /**
     * Every entry must point back at this bet through the polymorphic reference it
     * inherited from the transaction. An entry that does not is a posting attached to
     * the wrong domain record, which would make the bet's own ledger history wrong.
     *
     * @param  \Illuminate\Support\Collection<int, LedgerEntry>  $entries
     *
     * @throws BetPurchaseException
     */
    private function assertLinkedToBet(mixed $entries, Bet $bet, int $transactionId): void
    {
        $betId = (int) $bet->getKey();

        foreach ($entries as $entry) {
            if (! $entry instanceof LedgerEntry) {
                continue;
            }

            $referenceType = $entry->reference_type;
            $referenceId = $entry->reference_id;

            if ($referenceType === null && $referenceId === null) {
                // Phase 2.1 inherits the reference from the transaction; an entry with
                // none is reported rather than repaired, because repairing a ledger row
                // is a ledger mutation and this phase performs none.
                throw BetPurchaseException::ledgerNotBalanced(
                    $transactionId,
                    sprintf(
                        'ledger entry %d carries no domain reference, so the posting cannot be proven to belong to bet %d',
                        (int) $entry->getKey(),
                        $betId,
                    ),
                    ['bet_id' => $betId, 'ledger_entry_id' => (int) $entry->getKey()],
                );
            }

            if ($referenceType !== null && $referenceType !== Bet::class) {
                throw BetPurchaseException::ledgerNotBalanced(
                    $transactionId,
                    sprintf(
                        'ledger entry %d references "%s" instead of the bet that was purchased',
                        (int) $entry->getKey(),
                        (string) $referenceType,
                    ),
                    ['bet_id' => $betId, 'ledger_entry_id' => (int) $entry->getKey()],
                );
            }

            if ($referenceId !== null && (int) $referenceId !== $betId) {
                throw BetPurchaseException::ledgerNotBalanced(
                    $transactionId,
                    sprintf(
                        'ledger entry %d references bet %d instead of bet %d',
                        (int) $entry->getKey(),
                        (int) $referenceId,
                        $betId,
                    ),
                    ['bet_id' => $betId, 'ledger_entry_id' => (int) $entry->getKey()],
                );
            }
        }
    }

    /**
     * @throws BetPurchaseException
     */
    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw BetPurchaseException::outsideTransaction('verify the ledger for a bet debit');
        }
    }
}
