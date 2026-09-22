<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Base exception for ticket verification.
 *
 * Verification is a READ-ONLY flow: it confirms whether a ticket number stands
 * for a real ticket and its broad state. These exceptions therefore only cover
 * unusable input, never money or state transitions, and they are safe to throw
 * anywhere because they touch nothing.
 *
 * PRIVACY CONTRACT
 * The public verdict enum (TicketVerificationStatus) is deliberately coarse.
 * An exception here must never add detail that would let a non-owner
 * distinguish "ticket belongs to someone else" from "ticket does not exist" —
 * both are NotFound.
 */
class TicketVerificationException extends BetDomainException
{
    /**
     * The submitted ticket number is not a plausible ticket identifier at all.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function invalidNumber(string $reason, array $context = []): static
    {
        return static::withCode(
            'ticket_verification_invalid_number',
            sprintf('The ticket number cannot be verified: %s.', $reason),
            $context,
        );
    }

    /**
     * Verification is switched off by configuration.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function disabled(array $context = []): static
    {
        return static::withCode(
            'ticket_verification_disabled',
            'Ticket verification is not enabled on this platform.',
            $context,
        );
    }
}
