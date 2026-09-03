<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\BetPurchaseResult;
use App\Exceptions\BetPurchaseConcurrencyException;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\BetPurchaseIdempotencyException;
use App\Exceptions\BetPurchaseValidationException;
use App\Models\Bet;
use App\Services\Finance\Money;

/**
 * The one public entry point for buying a bet.
 *
 * Everything else in Phase 4.3 is reachable only through here. A caller needs no
 * knowledge of wallets, ledgers, locks, risk counters or tickets: it hands over a
 * request and receives either a result or a refusal, and in the refusal case the
 * database is unchanged.
 *
 * THE PIPELINE
 *   request -> forbidden client fields stripped -> exact-arithmetic check ->
 *   idempotency schema check -> replay check -> validation (draw, market, number,
 *   stake, multiplier, payout, risk preview) -> ATOMIC TRANSACTION (wallet lock ->
 *   idempotency recheck -> balance -> risk reservation -> bet -> item -> ticket ->
 *   debit -> ledger verification -> promotion) -> commit
 *
 * WHY THE REPLAY CHECK HAPPENS BOTH BEFORE AND INSIDE THE TRANSACTION
 * Before, because a retry of an already-completed purchase should cost nothing: it does
 * no validation, takes no lock and reads no risk counter, it just returns what was
 * bought. Inside, because the outer check is unlocked and a concurrent identical request
 * may commit in between. And underneath both, the UNIQUE constraint on
 * bets.idempotency_key is the actual guarantee - a database constraint, not a cache
 * entry and not an in-memory flag. A cache-based scheme would fail exactly when it
 * matters most: a cache miss, a cache flush or a second application server would each
 * let the same request debit the player twice.
 *
 * WHAT "IDEMPOTENT" MEANS HERE, PRECISELY
 * Same player, same draw, same client key => the same purchase. The second request
 * creates no bet, no item, no ticket, no financial transaction and no ledger entry, and
 * debits nothing; it returns the first purchase with a Replayed status. Same player,
 * same draw, SAME NUMBER, DIFFERENT client key => a genuinely different purchase, which
 * is created normally. Repeating a number is a legitimate thing a player does; repeating
 * a REQUEST is not. Conflating the two would silently refuse real bets.
 *
 * A key that is reused with a DIFFERENT payload is rejected outright rather than
 * replayed, because returning the first purchase for a different request would tell the
 * caller it bought something it did not ask for.
 *
 * WHAT THIS CLASS REFUSES TO TRUST
 * Wallet, balance, payout rate, potential payout and risk decision are all derived
 * server-side. BetPurchaseData strips those keys from the incoming payload and records
 * that it did, so the attempt is visible rather than silent. There is no parameter
 * anywhere in this phase that lets a caller supply any of them, and no flag that lets a
 * caller skip risk, skip the balance check or force a reservation.
 *
 * WHAT THIS CLASS DOES NOT DO
 * It does not settle. It determines no result, creates no payout, credits no prize and
 * closes no draw. Selling a bet and paying a bet are different phases and share no code
 * path here.
 */
final class BetPurchaseService
{
    public function __construct(
        private readonly BetPurchaseValidator $validator,
        private readonly BetPurchaseTransactionService $transaction,
        private readonly BetPurchaseIdempotencyService $idempotency,
    ) {
    }

    /**
     * Buy a bet.
     *
     * @throws BetPurchaseValidationException when the request is refused by the rules
     * @throws BetPurchaseIdempotencyException when the key is unusable, or is reused
     *                                         with a different payload
     * @throws BetPurchaseConcurrencyException when contention could not be resolved
     * @throws BetPurchaseException on any other refusal
     */
    public function purchase(BetPurchaseData $data): BetPurchaseResult
    {
        // Exact decimal arithmetic is a precondition, not a preference. Without bcmath
        // every amount in the pipeline would silently become approximate, so the
        // purchase refuses to run at all rather than take money inexactly.
        Money::assertExactArithmeticIsAvailable();

        // The database must be able to enforce request-level idempotency. If it cannot,
        // the purchase stops here: a retry would debit twice and nothing would detect it.
        $this->idempotency->assertSchemaSupportsIdempotency();

        $idempotencyKey = $this->idempotency->betKeyFor($data);

        // A completed purchase is returned untouched. No validation, no lock, no risk
        // read, no money.
        $replay = $this->idempotency->resolve($idempotencyKey, $data);

        if ($replay instanceof Bet) {
            return $this->idempotency->replayResultFor($replay);
        }

        $context = $this->validator->validate($data);

        try {
            return $this->transaction->execute($context);
        } catch (BetPurchaseConcurrencyException $exception) {
            if (! $exception->isIdempotentRace()) {
                throw $exception;
            }

            // A concurrent identical request committed first. Its purchase is the one
            // that exists, so it is returned. Nothing this attempt did survived: the
            // transaction rolled back before any row was committed.
            return $this->replayAfterLostRace($idempotencyKey, $data, $exception);
        }
    }

    /**
     * Buy a bet from a raw request payload.
     *
     * The player id is passed separately and explicitly, so it can only come from the
     * authenticated session and never from the payload itself.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BetPurchaseValidationException
     * @throws BetPurchaseIdempotencyException
     * @throws BetPurchaseConcurrencyException
     * @throws BetPurchaseException
     */
    public function purchaseFromRequest(int $userId, array $payload): BetPurchaseResult
    {
        return $this->purchase(BetPurchaseData::fromRequestArray($userId, $payload));
    }

    /**
     * Whether a purchase already exists for a request, without attempting one.
     */
    public function existingPurchaseFor(BetPurchaseData $data): ?BetPurchaseResult
    {
        $bet = $this->idempotency->resolve($this->idempotency->betKeyFor($data), $data);

        return $bet instanceof Bet ? $this->idempotency->replayResultFor($bet) : null;
    }

    /**
     * The derived idempotency key for a request, for reporting and for callers that
     * want to correlate a retry with its original.
     */
    public function idempotencyKeyFor(BetPurchaseData $data): string
    {
        return $this->idempotency->betKeyFor($data);
    }

    /**
     * Return the winner of an idempotency race.
     *
     * @throws BetPurchaseConcurrencyException when the winner cannot be read back
     */
    private function replayAfterLostRace(
        string $idempotencyKey,
        BetPurchaseData $data,
        BetPurchaseConcurrencyException $exception,
    ): BetPurchaseResult {
        $winner = $this->idempotency->resolve($idempotencyKey, $data);

        if (! $winner instanceof Bet) {
            // The race was lost but no winner is visible. This is re-thrown rather than
            // retried: retrying would risk a second purchase, and inventing a result
            // would report a bet that may not exist.
            throw $exception;
        }

        return $this->idempotency->replayResultFor($winner);
    }
}
