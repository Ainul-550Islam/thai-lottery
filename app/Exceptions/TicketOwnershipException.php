<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused or broken TICKET-OWNERSHIP step.
 *
 * COVERAGE
 * --------
 * Everything the identity-binding lane may refuse: an attempted transfer
 * (ownership is non-transferable by design — the lawful act is a fresh
 * binding, never a moved one), a duplicate binding (one ticket, two
 * owners), an identity mismatch (the presenting user is not the bound
 * owner), a custody conflict (the ticket is Locked by an in-flight claim
 * or batch), or a binding anchor that does not reproduce.
 *
 * WHY THIS IS IT OWN TYPE
 * -----------------------
 * A ticket is the bearer-controller of prize money: mistaking its lawful
 * holder is mistaking whose money a prize is. Every refusal carries ids
 * and state names, never secrets (no verification codes, no hashes other
 * than the binding fingerprints — machine ids, not credentials).
 */
class TicketOwnershipException extends RuntimeException
{
    public const CODE_TRANSFER_FORBIDDEN = 'TICKET_OWNERSHIP_TRANSFER_FORBIDDEN';

    public const CODE_DUPLICATE_BINDING = 'TICKET_OWNERSHIP_DUPLICATE_BINDING';

    public const CODE_IDENTITY_MISMATCH = 'TICKET_OWNERSHIP_IDENTITY_MISMATCH';

    public const CODE_IN_CUSTODY = 'TICKET_OWNERSHIP_IN_CUSTODY';

    public const CODE_TERMINAL_BINDING = 'TICKET_OWNERSHIP_TERMINAL_BINDING';

    public const CODE_ANCHOR_MISMATCH = 'TICKET_OWNERSHIP_ANCHOR_MISMATCH';

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
     * Moving a ticket to another identity. Ownership is identity-BOUND:
     * changing it means tombstoning the old binding and minting a fresh
     * lawful one (with its own audit). A silent load-bearing transfer is
     * what credential theft would be if it were legal.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function transferForbidden(string $ticketReference, array $context = []): self
    {
        return new self(
            sprintf(
                'Ticket [%s]: transfer is not a lawful operation on an identity-bound ticket; only a fresh binding may name a new owner.',
                $ticketReference,
            ),
            self::CODE_TRANSFER_FORBIDDEN,
            $context + ['ticket_reference' => $ticketReference],
        );
    }

    /**
     * A second ACTIVE binding for the same ticket (different owner/fingerprint).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function duplicateBinding(string $ticketReference, array $context = []): self
    {
        return new self(
            sprintf(
                'Ticket [%s] already has an active identity binding; a second concurrent owner can never be minted.',
                $ticketReference,
            ),
            self::CODE_DUPLICATE_BINDING,
            $context + ['ticket_reference' => $ticketReference],
        );
    }

    /**
     * The presenting user is not the bound owner.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function identityMismatch(string $ticketReference, int $presentedUserId, array $context = []): self
    {
        return new self(
            sprintf(
                'Ticket [%s]: user #%d is not the bound owner; presentation without the bound identity proves nothing.',
                $ticketReference,
                $presentedUserId,
            ),
            self::CODE_IDENTITY_MISMATCH,
            $context + ['ticket_reference' => $ticketReference, 'presented_user_id' => $presentedUserId],
        );
    }

    /**
     * The ticket is Locked: a claim flight or batch execution cited it.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function inCustody(string $ticketReference, string $custodian, array $context = []): self
    {
        return new self(
            sprintf(
                'Ticket [%s] is in custody of %s; the binding cannot move while a live lane cites it.',
                $ticketReference,
                $custodian,
            ),
            self::CODE_IN_CUSTODY,
            $context + ['ticket_reference' => $ticketReference, 'custodian' => $custodian],
        );
    }

    /**
     * The binding reached a terminal state (Claimed/Expired).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function terminalBinding(string $ticketReference, string $status, array $context = []): self
    {
        return new self(
            sprintf(
                'Ticket [%s]: ownership is terminally %s; mutating what the audit counted as finished is refused.',
                $ticketReference,
                $status,
            ),
            self::CODE_TERMINAL_BINDING,
            $context + ['ticket_reference' => $ticketReference, 'status' => $status],
        );
    }

    /**
     * The re-derived fingerprint does not equal the stamped one.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function anchorMismatch(string $ticketReference, array $context = []): self
    {
        return new self(
            sprintf(
                'Ticket [%s]: re-derived ownership fingerprint stopped matching the stamped one; the binding\'s basis drifted and every dependent lane must stop.',
                $ticketReference,
            ),
            self::CODE_ANCHOR_MISMATCH,
            $context + ['ticket_reference' => $ticketReference],
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
}
