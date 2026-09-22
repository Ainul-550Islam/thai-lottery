<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * QR-court refusals.
 *
 * Every way the signed-ticket-QR verification can collapse, named so the
 * scanner never has to guess. Message content is deliberately OPERATORLY
 * STRONG (the scanner attendant sees exactly what went wrong) while
 * carrying no ledger material in the exception body (not even the first
 * twelve characters of a stored identity hash; the codes alone carry the
 * failure species).
 */
final class TicketQrVerificationException extends RuntimeException
{
    public const CODE_MALFORMED = 'TICKET_QR_MALFORMED';

    public const CODE_INVALID_SIGNATURE = 'TICKET_QR_INVALID_SIGNATURE';

    public const CODE_EXPIRED = 'TICKET_QR_EXPIRED';

    public const CODE_ALREADY_USED = 'TICKET_QR_ALREADY_USED';

    public const CODE_OWNERSHIP_MISMATCH = 'TICKET_QR_OWNERSHIP_MISMATCH';

    public const CODE_NOT_FOUND = 'TICKET_QR_NOT_FOUND';

    public const CODE_STATE_FORBIDS = 'TICKET_QR_STATE_FORBIDS';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The payload could not even be parsed into its own grammar — refused
     * before any ledger read.
     */
    public static function malformed(string $reason, array $context = []): self
    {
        return new self(
            sprintf('The QR payload is malformed: %s.', $reason),
            self::CODE_MALFORMED,
            $context,
        );
    }

    /**
     * The signature did not verify — the document in hand is not the
     * document the ledger signed. Never reveal the stored signature here.
     */
    public static function invalidSignature(string $ticketReference, array $context = []): self
    {
        return new self(
            sprintf('The QR signature does not verify for ticket [%s] — the document presented is not the document the ledger signed.', $ticketReference),
            self::CODE_INVALID_SIGNATURE,
            $context + ['ticket_reference' => $ticketReference],
        );
    }

    /**
     * The QR's own window has run out — the ticket may still carry value,
     * but the verification document at the reader's hand is dead.
     */
    public static function expired(string $ticketReference, string $expiryIso, array $context = []): self
    {
        return new self(
            sprintf('The QR for ticket [%s] expired at %s; a fresh verification anchored on the ticket is required.', $ticketReference, $expiryIso),
            self::CODE_EXPIRED,
            $context + ['ticket_reference' => $ticketReference, 'expired_at' => $expiryIso],
        );
    }

    /**
     * Replay: this credential was already consumed by an earlier
     * verification gesture; the second draft of the same QR is a refusal.
     */
    public static function alreadyUsed(string $ticketReference, array $context = []): self
    {
        return new self(
            sprintf('The QR for ticket [%s] was already consumed by an earlier verification; this replay is refused.', $ticketReference),
            self::CODE_ALREADY_USED,
            $context + ['ticket_reference' => $ticketReference],
        );
    }

    /**
     * The QR is genuine — but the presenting principal is not the ticket's
     * ledger owner. Genuine document, wrong hands.
     */
    public static function ownershipMismatch(string $ticketReference, array $context = []): self
    {
        return new self(
            sprintf('The QR for ticket [%s] verifies, but the presenting principal is not the ticket\'s ledger owner.', $ticketReference),
            self::CODE_OWNERSHIP_MISMATCH,
            $context + ['ticket_reference' => $ticketReference],
        );
    }

    /**
     * The reference rather checks out grammatically but names ticket paper
     * the ledger has never heard of.
     */
    public static function notFound(string $ticketReference, array $context = []): self
    {
        return new self(
            sprintf('The QR names ticket [%s], which the ledger has never carried.', $ticketReference),
            self::CODE_NOT_FOUND,
            $context + ['ticket_reference' => $ticketReference],
        );
    }

    /**
     * The ticket's own status refuses the ledger-side verification: void,
     * expired, or cancelled paper cannot verify into value.
     */
    public static function stateForbids(string $ticketReference, string $status, array $context = []): self
    {
        return new self(
            sprintf('Ticket [%s] stands at [%s]; verification from that state is refused.', $ticketReference, $status),
            self::CODE_STATE_FORBIDS,
            $context + ['ticket_reference' => $ticketReference, 'status' => $status],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }
}
