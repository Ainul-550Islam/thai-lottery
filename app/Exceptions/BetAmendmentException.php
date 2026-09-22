<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Base exception for every refusal inside the bet-amendment flow.
 *
 * MONEY CONSISTENCY CONTRACT
 * An amendment is a refund of the old bet followed by the purchase of the new
 * bet, performed as two committed steps rather than one transaction (the
 * purchase pipeline refuses to run inside an outer transaction by design). The
 * ordering is what keeps the player's money safe: the old stake is back in the
 * wallet BEFORE the new purchase is attempted, so a refused replacement never
 * strands the stake — the player is exactly where they were before the
 * amendment, their original bet cancelled and fully refunded, and the amendment
 * row records Failed with the refusal reason.
 *
 * SAFE TO THROW INSIDE TRANSACTIONS
 * No subclass queries, loads models or writes anything.
 */
class BetAmendmentException extends BetDomainException
{
    /**
     * The bet could not be found within the caller's ownership scope.
     *
     * Same enumeration-safe shape as the cancellation flow: one message for
     * "missing" and "not yours".
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function betUnavailable(string $identifier, array $context = []): static
    {
        return static::withCode(
            'bet_amendment_unavailable',
            'The bet could not be found or cannot be amended by this account.',
            array_merge(['identifier' => $identifier], $context),
        );
    }

    /**
     * The bet's status or the draw's state does not allow amendment.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notAmendable(int $betId, string $reason, array $context = []): static
    {
        return static::withCode(
            'bet_amendment_not_amendable',
            sprintf('Bet %d cannot be amended: %s.', $betId, $reason),
            array_merge(['bet_id' => $betId], $context),
        );
    }

    /**
     * The amendment window has elapsed.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function windowExpired(int $betId, int $windowMinutes, array $context = []): static
    {
        return static::withCode(
            'bet_amendment_window_expired',
            sprintf('Bet %d is outside the %d-minute amendment window.', $betId, $windowMinutes),
            array_merge(['bet_id' => $betId, 'window_minutes' => $windowMinutes], $context),
        );
    }

    /**
     * Amendment is switched off by configuration.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function disabled(array $context = []): static
    {
        return static::withCode(
            'bet_amendment_disabled',
            'Bet amendment is not enabled on this platform.',
            $context,
        );
    }

    /**
     * The replacement purchase was refused AFTER the old bet was cancelled and
     * refunded. The wallet is already whole; this records the terminal Failed
     * state on the amendment row, not a money loss.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function replacementRefused(int $amendmentId, string $reason, array $context = [], ?Throwable $previous = null): static
    {
        return static::withCode(
            'bet_amendment_replacement_refused',
            sprintf(
                'Amendment %d cancelled and refunded the original bet, but the replacement bet was refused: %s.',
                $amendmentId,
                $reason,
            ),
            array_merge(['amendment_id' => $amendmentId], $context),
            $previous,
        );
    }
}
