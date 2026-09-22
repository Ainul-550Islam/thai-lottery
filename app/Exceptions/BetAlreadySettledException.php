<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The bet has already been settled (won, lost, refunded or paid out) and can
 * therefore never be cancelled or amended.
 *
 * A dedicated subclass so HTTP mapping can answer 409 Conflict (the request
 * conflicts with the settled state) while generic validation failures stay 422.
 */
class BetAlreadySettledException extends BetCancellationException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function forBet(int $betId, string $status, array $context = []): static
    {
        return static::withCode(
            'bet_already_settled',
            sprintf('Bet %d is already settled ("%s") and can no longer be changed.', $betId, $status),
            array_merge(['bet_id' => $betId, 'status' => $status], $context),
        );
    }
}
