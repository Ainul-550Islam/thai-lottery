<?php

declare(strict_types=1);

namespace App\DTOs\Ticket;

/**
 * QR verification payload.
 *
 * WHAT A QR SAY — stated one more time, because the boundary keeps being
 * tempting to blur: the QR is a signed self-description of the ticket. It
 * names the ticket by its reference, carries the signature that binds the
 * reference + draw + product context into one fingerprint, and is charged
 * anchored to the verification moment by a nonce. Nothing inside the QR
 * grants value by itself — value only ever answers FROM the ticket ledger
 * behind it.
 *
 * THE GRAMMAR, SO VERIFICATION FAILS EARLY
 * - ticket_reference: canonical uppercase / digits / hyphens.
 * - fingerprint: exactly 64 lowercase hex characters (a sha256).
 * - nonce: at least 16 characters (replay anchor).
 * - any client-supplied draw/product ids must be positive integers or
 *   absent — the service corroborates them against the book.
 */
final readonly class TicketQrVerificationData
{
    /**
     * @param  array<string, mixed>  $context  Safe diagnostic context
     *                                        (reader id, origin tag);
     *                                        never secrets.
     * @param  ?string  $expiresAt  Optional ISO-8601 expiry carried INSIDE
     *                              the signed surface: an issuer may bake
     *                              the URL-at-door lifetime into the QR so
     *                              the credential cannot be extended by its
     *                              holder. Null = no QR-side expiry (legacy
     *                              credentials keep verifying exactly as
     *                              before).
     */
    public function __construct(
        public string $ticketReference,
        public string $fingerprint,
        public string $nonce,
        public ?int $drawId,
        public ?int $ticketProductId,
        public array $context = [],
        public ?string $expiresAt = null,
    ) {
    }

    /**
     * Build from raw input with validation by form, not by hope. Throws a
     * shaped invalid-document sentence the QR service translates into its
     * own pronounced refusals — the DTO layer stays vocabulary-neutral.
     *
     * @throws \App\Exceptions\TicketQrVerificationException
     */
    public static function fromPayload(
        string $ticketReference,
        string $fingerprint,
        string $nonce,
        ?int $drawId = null,
        ?int $ticketProductId = null,
        array $context = [],
        ?string $expiresAt = null,
    ): self {
        $reference = strtoupper(trim($ticketReference));
        $fp = strtolower(trim($fingerprint));
        $n = trim($nonce);

        if (! preg_match('/^[A-Z0-9-]{1,64}$/', $reference)) {
            throw \App\Exceptions\TicketQrVerificationException::malformed(
                'the ticket reference is not canonical (uppercase ASCII, digits, hyphens)',
            );
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw \App\Exceptions\TicketQrVerificationException::malformed(
                'the fingerprint must be exactly 64 lowercase hex characters',
            );
        }

        if (mb_strlen($n) < 16) {
            throw \App\Exceptions\TicketQrVerificationException::malformed(
                'the nonce must be at least 16 characters',
            );
        }

        $expiry = null;

        if ($expiresAt !== null) {
            $candidate = trim($expiresAt);

            try {
                $expiry = \Illuminate\Support\Carbon::parse($candidate)->utc()->toIso8601String();
            } catch (\Throwable) {
                throw \App\Exceptions\TicketQrVerificationException::malformed(
                    'the expiry, if carried at all, must be an ISO-8601 instant',
                );
            }
        }

        return new self(
            ticketReference: $reference,
            fingerprint: $fp,
            nonce: $n,
            drawId: $drawId,
            ticketProductId: $ticketProductId,
            context: $context,
            expiresAt: $expiry,
        );
    }

    /**
     * The canonical signing input: the signature binds identity + draw +
     * product + nonce into one fingerprint, plus the expiry when one is
     * carried — a holder can neither extend its life (tamper) nor strip
     * it (simplify) without the signature breaking in both directions.
     * The service re-derives the same input from its OWN corroborated facts.
     */
    public function signingInput(int $drawId, ?int $ticketProductId): string
    {
        $base = sprintf(
            'ticket-qr:%s:%d:%s:%s',
            $this->ticketReference,
            $drawId,
            $ticketProductId !== null ? (string) $ticketProductId : '',
            $this->nonce,
        );

        return $this->expiresAt !== null
            ? $base.'|exp:'.$this->expiresAt
            : $base;
    }

    /**
     * @return array{ticket_reference: string, fingerprint: string, nonce: string, draw_id: ?int, ticket_product_id: ?int, expires_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'ticket_reference' => $this->ticketReference,
            'fingerprint' => $this->fingerprint,
            'nonce' => $this->nonce,
            'draw_id' => $this->drawId,
            'ticket_product_id' => $this->ticketProductId,
            'expires_at' => $this->expiresAt,
        ];
    }
}
