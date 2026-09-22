<?php

declare(strict_types=1);

namespace App\DTOs\Ticket;

/**
 * Immutable identity-bound OWNERSHIP record of one issued ticket.
 *
 * WHAT ONE OBJECT NAMES
 * ---------------------
 * The binding of exactly ONE ticket (by its reference) to exactly ONE
 * owner (by user id), at exactly one moment (the binding stamp), proved
 * by exactly one fingerprint. It is illegal by construction for this
 * object to name less than all four.
 *
 * THE OWNERSHIP FINGERPRINT, AND WHY IT IS DERIVED
 * ------------------------------------------------
 * A ticket's rightful holder is proven by a sha256 over (ticket reference
 * + user id + binding stamp). Given the same three, every re-run produces
 * the SAME fingerprint — that's the idempotency story: re-binding the
 * same ticket to the same user over the same stamp is a replay and joins
 * the existing lane. A different (ticket, user, stamp) produces a
 * different fingerprint: THAT is the anomaly duplicate-prevention refuses
 * (one ticket, two active bindings).
 *
 * This record is therefore NOT a transferable bearer instrument: there
 * is deliberately no recipient side on it. Ownership CHANGE is a fresh
 * binding operation owned by TicketOwnershipService, tombstoning the old
 * one — never a mutation of this DTO's facts.
 *
 * SOURCE OF TRUTH
 * ---------------
 * The DTO projects the tickets row's own fields (the row's user_id IS
 * the ownership fact). The service composes it when the lane is read;
 * lifecycle facts (Locked/Claimed/Expired) live in the TicketOwnershipStatus
 * lane carried on ticket metadata, not in this object.
 */
class TicketOwnershipData
{
    /**
     * @param  string  $boundAt  ISO-8601 stamp of when the binding was
     *                          created (the ticket's issued_at). Dragging
     *                          this fact rather than re-deriving it keeps
     *                          the fingerprint stable across runs.
     * @param  array<string, mixed>  $context  Safe binding context:
     *                                        purchase channel, order id —
     *                                        never the ticket's secret
     *                                        verification code.
     */
    public function __construct(
        public readonly string $ticketReference,
        public readonly int $ownerUserId,
        public readonly string $boundAt,
        public readonly array $context = [],
    ) {
    }

    /**
     * The ownership fingerprint: replay-stable binding identity.
     */
    public static function deriveFingerprint(
        string $ticketReference,
        int $ownerUserId,
        string $boundAt,
    ): string {
        return hash('sha256', sprintf(
            'ticket-ownership:%s:%d:%s',
            $ticketReference,
            $ownerUserId,
            $boundAt,
        ));
    }

    public function fingerprint(): string
    {
        return self::deriveFingerprint(
            $this->ticketReference,
            $this->ownerUserId,
            $this->boundAt,
        );
    }

    /**
     * The possession question other lanes cheaply ask: is the given user
     * the owner this binding names?
     */
    public function ownedBy(int $userId): bool
    {
        return $this->ownerUserId === $userId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ticket_reference' => $this->ticketReference,
            'owner_user_id' => $this->ownerUserId,
            'bound_at' => $this->boundAt,
            'fingerprint' => $this->fingerprint(),
            'context' => $this->context,
        ];
    }
}
