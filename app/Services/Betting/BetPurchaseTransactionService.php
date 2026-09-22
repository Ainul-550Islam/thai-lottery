<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseContext;
use App\DTOs\BetPurchaseResult;
use App\Exceptions\BetPurchaseConcurrencyException;
use App\Exceptions\BetPurchaseException;
use App\Models\Bet;
use App\Services\Agent\AgentCommissionAccrualService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owns the single database transaction in which a bet purchase becomes real.
 *
 * WHY ONE TRANSACTION, OWNED IN ONE PLACE
 * Atomicity is not something each collaborator can contribute a share of. Either every
 * mutation of a purchase is inside one transaction or the guarantee does not exist. So
 * exactly one class opens a transaction for a purchase, and it is this one. The wallet,
 * risk, bet, item, ticket and ledger services all ASSERT they are inside a transaction
 * and refuse to run otherwise, which means no collaborator can be misused into mutating
 * anything outside this boundary.
 *
 * THE ORDER, AND WHY EACH STEP IS WHERE IT IS
 *   1. LOCK THE WALLET. First, always, on every path. Fixing the lock order at the
 *      wallet is what makes a lock-order deadlock between two purchases impossible: two
 *      purchases can never hold each other's next lock, because both want the wallet
 *      before they want a number limit.
 *   2. RE-CHECK IDEMPOTENCY UNDER THE LOCK. The pre-transaction check happened without
 *      a lock, so a concurrent identical request may have committed since. Re-reading
 *      here, holding the wallet lock, closes that window for two requests from the same
 *      player. The insert's UNIQUE constraint closes it for every other case.
 *   3. CHECK THE BALANCE AGAINST THE LOCKED ROW. Before any row is created and before
 *      any capacity is reserved, so an unaffordable bet consumes nothing at all.
 *   4. RESERVE RISK CAPACITY. Second lock. Under SELECT ... FOR UPDATE on the
 *      number_limits row, decided by Phase 3.1 from counters it re-read under that lock.
 *   5. CREATE THE BET. Needs to exist before the debit, because the debit's polymorphic
 *      reference points at it.
 *   6. CREATE THE ITEM. One line, one charge.
 *   7. CREATE THE TICKET AND LINK IT. The Phase 1 foreign key is bets.ticket_id, so the
 *      ticket must exist before the bet can point at it.
 *   8. DEBIT THE WALLET. Phase 2.1 performs the debit, the double-entry posting and its
 *      own balance assertion as one unit, in a nested transaction - a SAVEPOINT inside
 *      this one - so an outer rollback still discards all of it.
 *   9. VERIFY THE LEDGER. Proven present, linked to this bet and balanced, before the
 *      commit is allowed. A purchase may not commit money that the books do not record.
 *  10. PROMOTE THE BET AND THE TICKET. Only paid-for, balanced purchases become Active
 *      and Confirmed.
 *
 * WHY THERE IS NO COMPENSATION OR CLEANUP CODE
 * Rollback is the database's job and it does it correctly. Any failure at any step
 * aborts the closure, Laravel rolls the transaction back, and every mutation above -
 * the counter increment, the bet, the item, the ticket, the wallet balance, the
 * financial transaction, the ledger entries - is discarded together. Hand-written
 * compensation (release the reservation, credit the wallet back, delete the ticket) is
 * how partial states are CREATED: it runs after the rollback has already undone the
 * thing it is compensating for, and so double-undoes it. There is deliberately none.
 *
 * WHY QueryException IS CLASSIFIED RATHER THAN SWALLOWED
 * A duplicate key on bets.idempotency_key means a concurrent identical request won the
 * race, which is a REPLAY and not an error the caller should see. A deadlock or lock
 * timeout is a transient contention failure worth retrying. Every other database error
 * is a real failure and is re-thrown. Nothing is retried blindly.
 */
final class BetPurchaseTransactionService
{
    /**
     * Attempts for the whole purchase closure when it fails on lock contention.
     */
    public const DEFAULT_ATTEMPTS = 3;

    public function __construct(
        private readonly BetPurchaseWalletService $wallets,
        private readonly BetPurchaseRiskService $risk,
        private readonly BetPurchaseBetService $bets,
        private readonly BetPurchaseItemService $items,
        private readonly BetPurchaseTicketService $tickets,
        private readonly BetPurchaseLedgerService $ledger,
        private readonly BetPurchaseIdempotencyService $idempotency,
        private readonly AgentCommissionAccrualService $commissionAccrual,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Execute the whole purchase atomically.
     *
     * @throws BetPurchaseConcurrencyException when a concurrent identical request won,
     *                                         or when contention could not be resolved
     * @throws BetPurchaseException on any refusal; nothing is persisted
     */
    public function execute(BetPurchaseContext $context): BetPurchaseResult
    {
        $this->assertNotAlreadyInTransaction();

        try {
            return DB::transaction(
                fn (): BetPurchaseResult => $this->purchase($context),
                $this->attempts(),
            );
        } catch (QueryException $exception) {
            // Reached when the retries are exhausted, or when the failure is not
            // retryable. Nothing is persisted either way: the transaction is closed.
            throw $this->classify($exception, $context);
        }
    }

    /**
     * The purchase body. Runs entirely inside the transaction.
     *
     * @throws BetPurchaseConcurrencyException
     * @throws BetPurchaseException
     */
    private function purchase(BetPurchaseContext $context): BetPurchaseResult
    {
        // 1. Wallet row lock. First lock on every path.
        $wallet = $this->wallets->lockForDebit($context->walletId(), $context->currency());

        // 2. Idempotency, re-checked while holding the lock.
        $existing = $this->bets->lockByIdempotencyKey($context->betIdempotencyKey);

        if ($existing instanceof Bet) {
            // Abort rather than continue. Throwing unwinds cleanly and the caller
            // replays the committed bet, so the retry never becomes a second purchase.
            throw BetPurchaseConcurrencyException::idempotentRaceLost(
                $context->betIdempotencyKey,
                $context->userId(),
                (int) $existing->getKey(),
            );
        }

        // 3. Affordability, against the locked row.
        $this->wallets->assertSufficientBalance($wallet, $context->stakeMoney());

        // 4. Risk capacity, under the number_limits row lock. No bypass exists.
        $reservation = $this->risk->reserve($context);

        // 5. The bet.
        $bet = $this->bets->create($context);

        // 6. The one item line.
        $item = $this->items->create($bet, $context);

        // 7. The ticket, then the link.
        $ticket = $this->tickets->create($context);
        $bet = $this->bets->attachTicket($bet, $ticket);

        // 8. The money. Phase 2.1 debits, posts the ledger and asserts its own balance.
        $transaction = $this->wallets->debitForBet(
            lockedWallet: $wallet,
            stake: $context->stakeMoney(),
            bet: $bet,
            idempotencyKey: $context->financialIdempotencyKey,
            metadata: [
                'bet_id' => (int) $bet->getKey(),
                'bet_number' => (string) $bet->bet_number,
                'ticket_id' => (int) $ticket->getKey(),
                'ticket_number' => (string) $ticket->ticket_number,
                'draw_id' => $context->drawId(),
                'market' => $context->marketKey,
                'number' => $context->canonicalNumber(),
                'bet_idempotency_key' => $context->betIdempotencyKey,
            ],
        );

        // 9. The books, proven before the commit is allowed.
        $ledgerEntryIds = $this->ledger->assertPostedForBet($transaction, $bet);

        // 10. Promotion. Only now is the purchase paid for and recorded.
        $bet = $this->bets->markPlaced($bet);
        $ticket = $this->tickets->confirm($ticket);

        $this->assertExactlyOneItem($bet, $context);

        // 11. Agent commissions accrue on real, paid turnover — never on an
        // unpaid, unposted or half-promoted purchase. Accruing here, inside the
        // same atomic unit, means a purchase that later cannot commit takes its
        // commission rows down with it, and a committed purchase can never
        // have existed without its commissions being written. The accrual
        // service itself resolves the referral chain (an unattributed player
        // yields no rows) and skips ineligible agents.
        $this->commissionAccrual->accrueForBet($bet);

        return BetPurchaseResult::purchased(
            bet: $bet,
            item: $item,
            ticket: $ticket,
            transaction: $transaction,
            stake: $context->stake,
            potentialPayout: $context->potentialPayout,
            idempotencyKey: $context->betIdempotencyKey,
            ledgerEntryIds: $ledgerEntryIds,
            riskReservation: $reservation,
            context: [
                'market' => $context->marketKey,
                'number' => $context->canonicalNumber(),
                'covered_number_count' => $context->permutationCount(),
                'ledger_totals' => $this->ledger->totalsFor($transaction),
                'wallet_available_after' => $this->wallets
                    ->availableBalance($wallet->refresh())
                    ->toString(),
            ],
        );
    }

    /**
     * One purchase creates one charge. Asserted from the database, inside the
     * transaction, so a purchase that somehow produced two lines rolls back instead of
     * committing a double charge.
     *
     * @throws BetPurchaseException
     */
    private function assertExactlyOneItem(Bet $bet, BetPurchaseContext $context): void
    {
        $count = $this->items->itemsFor($bet)->count();

        if ($count !== 1) {
            throw BetPurchaseException::invariantViolated(
                sprintf(
                    'the purchase created %d bet item lines for one selection on market %s, and one selection is one charge',
                    $count,
                    $context->marketKey,
                ),
                ['bet_id' => (int) $bet->getKey(), 'bet_item_count' => $count],
            );
        }
    }

    /**
     * Turn a database error into purchase vocabulary, preserving the original.
     */
    private function classify(QueryException $exception, BetPurchaseContext $context): Throwable
    {
        if ($this->idempotency->isDuplicateKeyViolation($exception)) {
            // A concurrent identical request inserted first. This is a replay signal.
            return BetPurchaseConcurrencyException::idempotentRaceLost(
                $context->betIdempotencyKey,
                $context->userId(),
                0,
                $exception,
            );
        }

        if (BetPurchaseConcurrencyException::isUniqueViolation($exception)) {
            return BetPurchaseConcurrencyException::uniqueViolation(
                'a unique constraint refused a row created by this purchase',
                $exception,
            );
        }

        if (BetPurchaseConcurrencyException::isLockFailure($exception)) {
            return BetPurchaseConcurrencyException::deadlock(
                sprintf(
                    'the purchase could not obtain its locks within %d attempts; nothing was persisted',
                    $this->attempts(),
                ),
                $exception,
            );
        }

        return $exception;
    }

    /**
     * Retry budget for the whole closure, from the Phase 2.1 locking configuration so
     * the money engine and the purchase agree on how much contention is tolerated.
     */
    public function attempts(): int
    {
        $configured = $this->config->get('finance.locking.max_retries');

        if (is_int($configured) && $configured >= 1) {
            return $configured;
        }

        if (is_string($configured) && ctype_digit($configured) && (int) $configured >= 1) {
            return (int) $configured;
        }

        return self::DEFAULT_ATTEMPTS;
    }

    /**
     * A purchase must own its transaction. Running inside somebody else's would mean a
     * failure could be caught and the surrounding transaction committed anyway, which
     * would defeat the atomicity guarantee entirely.
     *
     * @throws BetPurchaseException
     */
    private function assertNotAlreadyInTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            throw BetPurchaseException::invariantViolated(
                'a bet purchase must own its own database transaction, but one is already open. '
                .'Nesting it would let a caller commit a purchase that failed.',
                ['transaction_level' => DB::transactionLevel()],
            );
        }
    }
}
