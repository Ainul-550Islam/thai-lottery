<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Currency;
use Throwable;

/**
 * Base exception for every failure inside the Phase 4.3 atomic purchase pipeline.
 *
 * WHY IT EXTENDS BetDomainException
 * A purchase failure is a betting-domain failure, and Phase 4.1 already declares
 * App\Exceptions\BetDomainException as the single type that covers the betting
 * domain. Introducing a second, unrelated root would mean a caller that already
 * catches BetDomainException to unwind a bet would silently miss purchase faults.
 * Every purchase exception is therefore catchable both as BetDomainException (the
 * whole betting domain) and as BetPurchaseException (only the purchase pipeline).
 *
 * WHY IT DOES NOT EXTEND FinancialException OR RiskException
 * A purchase can fail because of money (insufficient balance), because of risk
 * (number limit exhausted) or because of the domain (closed draw). Those three
 * concerns already own their own exception roots in Phases 2.1 and 3.1. If this
 * class extended either of them, code written to unwind a wallet operation would
 * start swallowing risk refusals, and code written to react to a risk verdict
 * would start swallowing money faults. Instead, the underlying exception is always
 * preserved as $previous, so the original financial or risk fault is never lost.
 *
 * ROLLBACK CONTRACT
 * Throwing any subclass of this exception from inside the purchase transaction is
 * the ONLY mechanism the pipeline uses to abort. The exception performs no
 * cleanup, issues no compensating write and touches no model, because the
 * surrounding DB transaction rollback already discards every mutation - the bet,
 * the bet item, the ticket, the wallet debit, the ledger entries and the number
 * limit reservation. Any attempt to "undo" work here would be a second mutation
 * on a doomed transaction and could leave the very partial state this phase must
 * make impossible.
 *
 * SAFE TO THROW UNDER A ROW LOCK
 * These exceptions never query, never load a model and never write, so they are
 * safe to throw from inside a SELECT ... FOR UPDATE critical section held on the
 * wallets row or on the number_limits row.
 *
 * SECURITY
 * The context array carries diagnostic identifiers only - user id, draw id, wallet
 * id, market key, canonical number, amounts, currency codes, error codes. It never
 * carries credentials, tokens, raw request payloads or personal data, because
 * context is written to logs.
 */
class BetPurchaseException extends BetDomainException
{
    /**
     * A purchase that could not proceed because the request identified no usable
     * player.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function userUnavailable(int $userId, string $reason, array $context = []): static
    {
        return static::withCode(
            'bet_purchase_user_unavailable',
            sprintf('User %d cannot place a bet: %s.', $userId, $reason),
            array_merge(['user_id' => $userId], $context),
        );
    }

    /**
     * No spendable wallet could be derived server-side for the player.
     *
     * The wallet is never taken from the request, so this failure means the player
     * genuinely has no active wallet in the betting currency - not that the client
     * sent a wrong wallet id.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function walletUnavailable(int $userId, Currency $currency, string $reason, array $context = []): static
    {
        return static::withCode(
            'bet_purchase_wallet_unavailable',
            sprintf(
                'No spendable %s wallet could be resolved for user %d: %s.',
                $currency->value,
                $userId,
                $reason,
            ),
            array_merge([
                'user_id' => $userId,
                'currency' => $currency->value,
            ], $context),
        );
    }

    /**
     * The locked wallet did not hold enough available balance for the stake.
     *
     * Raised from INSIDE the wallet row lock, using the balance read from the
     * locked row, never from an earlier unlocked read.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function insufficientBalance(
        int $walletId,
        string $required,
        string $available,
        Currency $currency,
        array $context = [],
        ?Throwable $previous = null,
    ): static {
        return new static(
            sprintf(
                'Wallet %d has %s %s available, which is less than the required stake of %s %s.',
                $walletId,
                $available,
                $currency->value,
                $required,
                $currency->value,
            ),
            'bet_purchase_insufficient_balance',
            array_merge([
                'wallet_id' => $walletId,
                'required' => $required,
                'available' => $available,
                'currency' => $currency->value,
            ], $context),
            $previous,
        );
    }

    /**
     * The Phase 3.1 risk engine refused to reserve capacity for the selection.
     *
     * There is deliberately no counterpart factory for "risk bypassed": the
     * pipeline exposes no bypass, no override and no force flag, so no such state
     * is representable.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function riskRejected(string $reason, array $context = [], ?Throwable $previous = null): static
    {
        return new static(
            sprintf('The risk engine refused this selection: %s.', $reason),
            'bet_purchase_risk_rejected',
            $context,
            $previous,
        );
    }

    /**
     * The draw named by the request cannot accept a purchase.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function drawUnavailable(int $drawId, string $reason, array $context = []): static
    {
        return static::withCode(
            'bet_purchase_draw_unavailable',
            sprintf('Draw %d cannot accept bets: %s.', $drawId, $reason),
            array_merge(['draw_id' => $drawId], $context),
        );
    }

    /**
     * The resolved payout multiplier cannot be persisted in the existing
     * bet_items.payout_multiplier column.
     *
     * The column is an unsignedInteger in the Phase 1 schema. A fractional rate is
     * reported here rather than being rounded, because rounding a rate would
     * silently change every future payout for that market. Migrations are out of
     * scope for this phase, so this is a reported schema limitation and never a
     * repaired one.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function multiplierNotStorable(string $multiplier, string $marketKey, array $context = []): static
    {
        return static::withCode(
            'bet_purchase_multiplier_not_storable',
            sprintf(
                'Market "%s" resolves to payout multiplier %s, which is not a whole number and '
                .'therefore cannot be stored in the existing unsignedInteger column '
                .'bet_items.payout_multiplier. SCHEMA CHANGE REQUIRED: widen '
                .'bet_items.payout_multiplier to a decimal column before selling this market. '
                .'Refusing to round the rate, because rounding would alter every payout.',
                $marketKey,
                $multiplier,
            ),
            array_merge([
                'market' => $marketKey,
                'payout_multiplier' => $multiplier,
            ], $context),
        );
    }

    /**
     * The double-entry posting produced by the Phase 2.1 engine did not satisfy
     * the ledger invariant after the debit.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function ledgerNotBalanced(int $transactionId, string $reason, array $context = [], ?Throwable $previous = null): static
    {
        return new static(
            sprintf(
                'The ledger posting for financial transaction %d is not acceptable: %s. '
                .'The purchase is being rolled back so no unbalanced movement can commit.',
                $transactionId,
                $reason,
            ),
            'bet_purchase_ledger_not_balanced',
            array_merge(['financial_transaction_id' => $transactionId], $context),
            $previous,
        );
    }

    /**
     * A row that must exist after its creating call did not.
     *
     * Used as a hard internal invariant check - for example a bet that reports no
     * primary key after save(). It exists so that such a condition aborts the
     * transaction instead of letting a later step write an orphan row.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function invariantViolated(string $invariant, array $context = []): static
    {
        return static::withCode(
            'bet_purchase_invariant_violated',
            sprintf('Aborting the purchase: %s.', $invariant),
            $context,
        );
    }

    /**
     * The pipeline was asked to mutate money outside a database transaction.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function outsideTransaction(string $operation, array $context = []): static
    {
        return static::withCode(
            'bet_purchase_outside_transaction',
            sprintf(
                'Refusing to %s outside a database transaction: the bet, ticket, wallet debit, '
                .'ledger posting and risk reservation must commit or roll back together.',
                $operation,
            ),
            $context,
        );
    }
}
