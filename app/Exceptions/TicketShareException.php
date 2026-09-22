<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Base exception for the ticket share-link flow.
 *
 * Token safety rules enforced here and in the service:
 * - the raw token is returned exactly once, at creation, and never stored;
 * - lookup is by SHA-256 hash only, so a database read never yields a usable token;
 * - a revoked or lapsed share, a malformed token and a never-issued token are the
 *   SAME refusal, so the endpoint confirms nothing about which share links exist.
 */
class TicketShareException extends BetDomainException
{
    /**
     * The ticket could not be found within the caller's ownership scope.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function ticketUnavailable(string $identifier, array $context = []): static
    {
        return static::withCode(
            'ticket_share_ticket_unavailable',
            'The ticket could not be found or cannot be shared by this account.',
            array_merge(['identifier' => $identifier], $context),
        );
    }

    /**
     * The share token does not resolve. Covers malformed, unknown, revoked and
     * lapsed tokens with one message on purpose.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function shareUnavailable(array $context = []): static
    {
        return static::withCode(
            'ticket_share_unavailable',
            'This share link is invalid, revoked or has expired.',
            $context,
        );
    }

    /**
     * The caller tried to revoke a share that is not active.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notRevokable(int $shareId, string $status, array $context = []): static
    {
        return static::withCode(
            'ticket_share_not_revokable',
            sprintf('Share %d is %s and cannot be revoked.', $shareId, $status),
            array_merge(['share_id' => $shareId, 'status' => $status], $context),
        );
    }

    /**
     * Sharing is switched off by configuration.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function disabled(array $context = []): static
    {
        return static::withCode(
            'ticket_share_disabled',
            'Ticket sharing is not enabled on this platform.',
            $context,
        );
    }
}
