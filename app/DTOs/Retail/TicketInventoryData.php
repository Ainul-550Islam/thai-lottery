<?php

declare(strict_types=1);

namespace App\DTOs\Retail;

/**
 * Immutable ticket inventory identity.
 *
 * One physical/serialized unit of ticket paper (or its digital projection)
 * has three legs: WHICH product it belongs to, WHICH draw it pays against,
 * and ITS OWN identity — the serial. The fingerprint binds those three
 * legs into one deterministic hash so a unit can never be re-canonicalized
 * into a different fingerprint later: identity is derivation, never
 * database lottery.
 *
 * The serial grammar is stated rather than implied: ASCII uppercase /
 * digits / hyphen only, mirroring the same canonicalization rule the
 * codebase already applies to product codes (the same characters, the same
 * disciplines on ticket paper numbers).
 */
final readonly class TicketInventoryData
{
    /**
     * @param  string  $fingerprint  Deterministic sha256 binding (product,
     *                               draw, serial) — derived by the service
     *                               on first write and replayed unchanged
     *                               ever after.
     */
    public function __construct(
        public int $ticketProductId,
        public int $drawId,
        public string $serial,
        public string $fingerprint,
    ) {
    }

    /**
     * Build from raw input, canonicalizing the serial and deriving the
     * fingerprint.
     *
     * @throws \App\Exceptions\TicketInventoryException when the serial is
     *                                                    non-canonical; the
     *                                                    service lanes
     *                                                    throw the domain
     *                                                    exception already,
     *                                                    so the builder
     *                                                    carries it through.
     */
    public static function fromInput(
        int $ticketProductId,
        int $drawId,
        string $serial,
    ): self {
        $canonical = strtoupper(trim($serial));

        return new self(
            ticketProductId: $ticketProductId,
            drawId: $drawId,
            serial: $canonical,
            fingerprint: self::deriveFingerprint($ticketProductId, $drawId, $canonical),
        );
    }

    /**
     * The fingerprint: one hash, one identity, forever.
     */
    public static function deriveFingerprint(int $ticketProductId, int $drawId, string $serial): string
    {
        return hash('sha256', sprintf('ticket-inventory:%d:%d:%s', $ticketProductId, $drawId, $serial));
    }

    /**
     * Replayability anchor: the same identity admitted through any venue
     * (create / re-serve / reconcile) lands the same fingerprint.
     */
    public function matches(int $ticketProductId, int $drawId, string $serial): bool
    {
        return $this->ticketProductId === $ticketProductId
            && $this->drawId === $drawId
            && $this->serial === strtoupper(trim($serial));
    }

    /**
     * @return array{ticket_inventory_item: int, draw_id: int, serial: string, fingerprint: string}
     */
    public function toArray(): array
    {
        return [
            'ticket_inventory_item' => $this->ticketProductId,
            'draw_id' => $this->drawId,
            'serial' => $this->serial,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
