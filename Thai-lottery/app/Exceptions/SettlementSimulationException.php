<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused simulated settlement run.
 *
 * Thrown when the run cannot be completed correctly and must roll back whole:
 * a selection whose market cannot be resolved, a recorded market that contradicts
 * the market derived from the bet type and position, a configured multiplier that
 * cannot be stored exactly in the existing column, a caller supplied prize amount,
 * or a lock that could not be acquired.
 *
 * WHY A NEW CLASS AND NOT AN EXISTING ONE
 * ---------------------------------------
 * App\Exceptions\PayoutException (if reached for) describes a REAL MONEY payout
 * failure and is caught by finance code that then compensates a wallet. A simulated
 * settlement has nothing to compensate, and must never be routed into a code path
 * that touches a balance.
 *
 * App\Exceptions\BetDomainException carries App\Enums\BetValidationCode, whose cases
 * describe a bet being PURCHASED. Settlement happens long after purchase and its
 * failures have no BetValidationCode.
 *
 * ROLLBACK CONTRACT
 * Every factory here names a condition detected INSIDE the settlement transaction,
 * while the draw row is held with SELECT ... FOR UPDATE. Throwing therefore unwinds
 * the whole run: no bet_items row keeps a partial is_winner, no bet keeps a partial
 * status, and the draw stays in result_published rather than becoming settled. This
 * class holds no models and issues no queries, so throwing it is always safe from
 * inside that transaction.
 *
 * NON-MONETARY
 * No factory refers to a wallet, a balance, a ledger account or a financial
 * transaction, because a simulated settlement never touches one. simulatedOnly() is
 * the explicit guard for the case where something asks settlement to move money.
 *
 * SECURITY
 * Context carries ids and market keys. No stack trace, SQL, credential or personal
 * datum is ever placed in it.
 */
class SettlementSimulationException extends RuntimeException
{
    public const CODE_MARKET_UNRESOLVED = 'SETTLEMENT_MARKET_UNRESOLVED';

    public const CODE_MARKET_MISMATCH = 'SETTLEMENT_MARKET_MISMATCH';

    public const CODE_MULTIPLIER_UNSTORABLE = 'SETTLEMENT_MULTIPLIER_UNSTORABLE';

    public const CODE_PAYOUT_UNSTORABLE = 'SETTLEMENT_PAYOUT_UNSTORABLE';

    public const CODE_CLIENT_SUPPLIED_PAYOUT = 'SETTLEMENT_CLIENT_SUPPLIED_PAYOUT';

    public const CODE_SIMULATED_ONLY = 'SETTLEMENT_SIMULATED_ONLY';

    public const CODE_LOCK_UNAVAILABLE = 'SETTLEMENT_LOCK_UNAVAILABLE';

    public const CODE_ALREADY_RUNNING = 'SETTLEMENT_ALREADY_RUNNING';

    public const CODE_SELECTION_UNREADABLE = 'SETTLEMENT_SELECTION_UNREADABLE';

    public const CODE_RESULT_TAMPERED = 'SETTLEMENT_RESULT_TAMPERED';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A selection carries no resolvable market.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function marketUnresolved(
        int $betItemId,
        ?string $attempted,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'Selection %d has no resolvable market (attempted key: %s), so the settlement run is '
                .'refused rather than guessing one.',
                $betItemId,
                $attempted === null || $attempted === '' ? 'none recorded' : '"'.$attempted.'"',
            ),
            self::CODE_MARKET_UNRESOLVED,
            $context + [
                'bet_item_id' => $betItemId,
                'attempted_market' => $attempted,
            ],
            $previous,
        );
    }

    /**
     * The market recorded on a selection contradicts the market derived from its
     * stored bet type and position.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function marketMismatch(
        int $betItemId,
        string $recorded,
        string $derived,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Selection %d records market "%s" but its stored bet type and position derive market '
                .'"%s". The run is refused because settling under either market could be wrong.',
                $betItemId,
                $recorded,
                $derived,
            ),
            self::CODE_MARKET_MISMATCH,
            $context + [
                'bet_item_id' => $betItemId,
                'recorded_market' => $recorded,
                'derived_market' => $derived,
            ],
        );
    }

    /**
     * The configured multiplier cannot be written to the integer column without loss.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function multiplierUnstorable(
        int $betItemId,
        string $market,
        string $multiplier,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'The configured multiplier %s for market "%s" cannot be stored exactly in the integer '
                .'payout_multiplier column of selection %d. The run is refused rather than rounding '
                .'or truncating it.',
                $multiplier,
                $market,
                $betItemId,
            ),
            self::CODE_MULTIPLIER_UNSTORABLE,
            $context + [
                'bet_item_id' => $betItemId,
                'market' => $market,
                'multiplier' => $multiplier,
            ],
            $previous,
        );
    }

    /**
     * The simulated prize does not fit the decimal(20,2) column exactly.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function payoutUnstorable(
        int $betItemId,
        string $amount,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'The calculated simulated prize %s for selection %d cannot be stored exactly in a '
                .'decimal(20,2) column. The run is refused rather than rounding it.',
                $amount,
                $betItemId,
            ),
            self::CODE_PAYOUT_UNSTORABLE,
            $context + [
                'bet_item_id' => $betItemId,
                'amount' => $amount,
            ],
            $previous,
        );
    }

    /**
     * A caller tried to dictate a prize amount, a winner or a multiplier.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function clientSuppliedPayout(string $field, array $context = []): self
    {
        return new self(
            sprintf(
                'The field "%s" was supplied to the settlement run. Winning status, multipliers and '
                .'simulated prize amounts are computed server side from the published result, the '
                .'verified market rules and configuration. Caller supplied values are refused '
                .'outright and there is no override, force or bypass parameter.',
                $field,
            ),
            self::CODE_CLIENT_SUPPLIED_PAYOUT,
            $context + ['field' => $field],
        );
    }

    /**
     * Something asked settlement to perform a real money effect.
     *
     * A defensive guard. Phase 5.1 contains no code path that credits a wallet,
     * writes a ledger entry, creates a financial transaction or calls a payment
     * gateway, so this should be unreachable; it exists so that a future edit which
     * tries to add one fails loudly.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function simulatedOnly(string $attemptedEffect, array $context = []): self
    {
        return new self(
            sprintf(
                'Refused: "%s". Draw settlement is a NON-MONETARY SIMULATION. It records a simulated '
                .'prize amount for audit and never credits or debits a wallet, writes a ledger entry, '
                .'creates a financial transaction, creates a payouts row or calls a payment gateway.',
                $attemptedEffect,
            ),
            self::CODE_SIMULATED_ONLY,
            $context + ['attempted_effect' => $attemptedEffect],
        );
    }

    /**
     * The draw row lock could not be acquired.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function lockUnavailable(int $drawId, array $context = [], ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Could not acquire the settlement lock on draw %d. A concurrent settlement run is '
                .'likely in progress; this run is refused and wrote nothing.',
                $drawId,
            ),
            self::CODE_LOCK_UNAVAILABLE,
            $context + ['draw_id' => $drawId],
            $previous,
        );
    }

    /**
     * Settlement was invoked while another database transaction was already open.
     *
     * Mirrors the guard in App\Services\Betting\BetPurchaseTransactionService: a
     * settlement run must own its own transaction, or its rollback guarantee would
     * belong to an outer caller instead.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyRunning(int $drawId, int $transactionLevel, array $context = []): self
    {
        return new self(
            sprintf(
                'Simulated settlement of draw %d was invoked while a database transaction was already '
                .'open (level %d). Settlement must own its transaction so that a failure rolls the '
                .'whole run back, so this call is refused.',
                $drawId,
                $transactionLevel,
            ),
            self::CODE_ALREADY_RUNNING,
            $context + [
                'draw_id' => $drawId,
                'transaction_level' => $transactionLevel,
            ],
        );
    }

    /**
     * A selection is missing data settlement needs and cannot be read.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function selectionUnreadable(int $betItemId, string $reason, array $context = []): self
    {
        return new self(
            sprintf('Selection %d cannot be settled: %s. The run is refused.', $betItemId, $reason),
            self::CODE_SELECTION_UNREADABLE,
            $context + [
                'bet_item_id' => $betItemId,
                'reason' => $reason,
            ],
        );
    }

    /**
     * The published result of a draw no longer agrees with itself.
     *
     * The authoritative result of a draw is stored twice by design: the raw six
     * digit first prize plus the bottom two live on draw_results, and the derived
     * per market winning value of each configured market lives on winning_numbers.
     * Publication writes both inside one transaction, so immediately after
     * publication the two agree by construction.
     *
     * Neither table has a database level immutability constraint, so a later
     * UPDATE, a soft delete of a winning_numbers row, or an edit of the bottom two
     * inside draw_results.metadata can make the pair disagree. Settling against a
     * result in that condition would decide winners from a number that the
     * published record does not actually contain, so the run is refused instead.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function resultTampered(int $drawId, string $reason, array $context = []): self
    {
        return new self(
            sprintf(
                'The published result of draw %d is not self consistent: %s. The stored result and the '
                .'stored winning numbers must agree before settlement can decide any winner, so this '
                .'run is refused and wrote nothing.',
                $drawId,
                $reason,
            ),
            self::CODE_RESULT_TAMPERED,
            $context + [
                'draw_id' => $drawId,
                'reason' => $reason,
            ],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function contextValue(string $key): string|int|float|bool|null
    {
        return $this->context[$key] ?? null;
    }

    /**
     * @return array{type: string, error_code: string, message: string, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'type' => static::class,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
