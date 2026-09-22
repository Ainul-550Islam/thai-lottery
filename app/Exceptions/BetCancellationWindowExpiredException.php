<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The player-facing cancellation window (config('lottery.cancellation.window_minutes'))
 * has elapsed for this bet.
 *
 * A dedicated subclass so HTTP mapping can answer 422 with a stable code clients
 * can branch on to grey out the cancel button, distinct from a status refusal.
 */
class BetCancellationWindowExpiredException extends BetCancellationException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function forBet(int $betId, string $placedAt, int $windowMinutes, array $context = []): static
    {
        return static::withCode(
            'bet_cancellation_window_expired',
            sprintf(
                'Bet %d was placed at %s, which is outside the %d-minute cancellation window.',
                $betId,
                $placedAt,
                $windowMinutes,
            ),
            array_merge([
                'bet_id' => $betId,
                'placed_at' => $placedAt,
                'window_minutes' => $windowMinutes,
            ], $context),
        );
    }
}
