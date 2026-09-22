<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BetCancellationReason;
use Throwable;

/**
 * Base exception for every refusal inside the bet-cancellation flow.
 *
 * WHY IT EXTENDS BetDomainException
 * A cancellation refusal is a betting-domain refusal, and callers already catch
 * BetDomainException to unwind bet workflows. Every cancellation fault is
 * therefore catchable both as the domain root and as this narrower type, exactly
 * the same relationship BetPurchaseException has with the purchase pipeline.
 *
 * STATE CONTRACT
 * The cancellation service performs every mutation inside ONE database
 * transaction (bet status, ticket status, risk release, wallet refund, ledger
 * posting). Throwing from inside that transaction is the abort mechanism: the
 * rollback discards every partial change, so a cancelled bet without its refund
 * — or a refund on a bet that is still active — is unrepresentable. This
 * exception performs no cleanup itself.
 *
 * SECURITY
 * Context carries safe identifiers only: bet id, bet number, draw id, status
 * strings, amounts, currency, reason codes. Never request payloads, credentials
 * or personal data — context is logged.
 */
class BetCancellationException extends BetDomainException
{
    /**
     * The bet could not be found within the caller's ownership scope.
     *
     * Deliberately the same shape for "no such bet" and "someone else's bet":
     * distinguishing them would confirm that a bet number exists, which is an
     * enumeration leak.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function betUnavailable(string $identifier, array $context = []): static
    {
        return static::withCode(
            'bet_cancellation_unavailable',
            'The bet could not be found or cannot be cancelled by this account.',
            array_merge(['identifier' => $identifier], $context),
        );
    }

    /**
     * The bet's current status does not allow cancellation.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notCancellable(string $status, array $context = []): static
    {
        return static::withCode(
            'bet_cancellation_not_cancellable',
            sprintf('A bet in status "%s" cannot be cancelled.', $status),
            array_merge(['status' => $status], $context),
        );
    }

    /**
     * The draw the bet belongs to no longer accepts cancellations.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function drawUnavailable(int $drawId, string $reason, array $context = []): static
    {
        return static::withCode(
            'bet_cancellation_draw_unavailable',
            sprintf('Draw %d cannot accept cancellations: %s.', $drawId, $reason),
            array_merge(['draw_id' => $drawId], $context),
        );
    }

    /**
     * The submitted reason is not a persisted cancellation reason.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function invalidReason(string $reason, array $context = []): static
    {
        return static::withCode(
            'bet_cancellation_invalid_reason',
            sprintf('"%s" is not a valid cancellation reason.', $reason),
            array_merge(
                ['reason' => $reason, 'allowed' => implode(',', BetCancellationReason::values())],
                $context,
            ),
        );
    }

    /**
     * Cancellation is switched off by configuration.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function disabled(array $context = []): static
    {
        return static::withCode(
            'bet_cancellation_disabled',
            'Bet cancellation is not enabled on this platform.',
            $context,
        );
    }

    /**
     * The refund leg of the cancellation could not commit.
     *
     * Thrown from inside the cancellation transaction, so the status change and
     * the risk release roll back together with the refund — no compensation is
     * attempted here.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function refundFailed(int $betId, string $reason, array $context = [], ?Throwable $previous = null): static
    {
        return static::withCode(
            'bet_cancellation_refund_failed',
            sprintf('The refund for bet %d could not be completed: %s.', $betId, $reason),
            array_merge(['bet_id' => $betId], $context),
            $previous,
        );
    }

    /**
     * A cancellation was attempted with a different client key after the first
     * cancellation already committed — it is not an error, but callers that
     * distinguish replays from fresh work branch on this code.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyCancelled(int $betId, array $context = []): static
    {
        return static::withCode(
            'bet_cancellation_already_cancelled',
            sprintf('Bet %d is already cancelled.', $betId),
            array_merge(['bet_id' => $betId], $context),
        );
    }
}
