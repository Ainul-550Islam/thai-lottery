<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

/**
 * The immutable input of a ticket verification request.
 *
 * ONE FIELD, SEVERAL SPELLINGS
 * The checkbox input is the printed ticket number exactly as shown on the slip.
 * Whitespace edges are trimmed; everything else is left to the verification
 * service's strict format check, which refuses (rather than repairs) anything
 * that is not the platform's ticket-number shape. No owner id is taken from the
 * request: for the owner-scoped check the user id arrives from the auth context,
 * exactly like the purchase and cancellation flows.
 */
final readonly class TicketVerificationData
{
    public function __construct(
        public string $ticketNumber,
        public ?int $ownerUserId,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromRequestArray(?int $ownerUserId, array $payload): self
    {
        return new self(
            ticketNumber: trim((string) ($payload['ticket_number'] ?? '')),
            ownerUserId: $ownerUserId,
        );
    }

    /**
     * Whether the requester authenticated and asked for the owner-scoped detail
     * level. Public verification gets the coarse verdict only.
     */
    public function isOwnerScoped(): bool
    {
        return $this->ownerUserId !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ticket_number' => $this->ticketNumber,
            'owner_scoped' => $this->isOwnerScoped(),
        ];
    }
}
