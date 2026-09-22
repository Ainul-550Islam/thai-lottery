<?php

declare(strict_types=1);

namespace App\DTOs\Retail;

/**
 * Immutable vendor allocation request.
 *
 * "Give vendor X quantity Y of product P for draw D" is one sentence, one
 * court, one fingerprint: the deterministic allocation key. Every retry,
 * every chunked fan-out, every reconciler replays this same key, so the
 * allocation lane cannot mint two rows for one conversation.
 *
 * WHAT'S NOT HERE — AND WHY
 * No money, no price: allocations are PAPER quota; the monetary truth of
 * each sale is complete at sale time in the settlement lanes, never
 * derivable from quota arithmetic upstream of it.
 */
final readonly class TicketAllocationData
{
    public function __construct(
        public int $vendorId,
        public int $ticketProductId,
        public int $drawId,
        public int $quantity,
        public string $allocationKey,
    ) {
    }

    /**
     * Build from raw input with the deterministic key derived.
     */
    public static function fromInput(
        int $vendorId,
        int $ticketProductId,
        int $drawId,
        int $quantity,
    ): self {
        return new self(
            vendorId: $vendorId,
            ticketProductId: $ticketProductId,
            drawId: $drawId,
            quantity: $quantity,
            allocationKey: self::deriveKey($vendorId, $ticketProductId, $drawId),
        );
    }

    /**
     * The deterministic key: one allocation conversation per
     * (vendor, product, draw), forever.
     */
    public static function deriveKey(int $vendorId, int $ticketProductId, int $drawId): string
    {
        return hash('sha256', sprintf('ticket-allocation:%d:%d:%d', $vendorId, $ticketProductId, $drawId));
    }

    /**
     * The quantity sanity rule, stated: an allocation must ask for at
     * least one unit.
     */
    public function asksForSomething(): bool
    {
        return $this->quantity > 0;
    }

    /**
     * @return array{vendor_id: int, ticket_product_id: int, draw_id: int, quantity: int, allocation_key: string}
     */
    public function toArray(): array
    {
        return [
            'vendor_id' => $this->vendorId,
            'ticket_product_id' => $this->ticketProductId,
            'draw_id' => $this->drawId,
            'quantity' => $this->quantity,
            'allocation_key' => $this->allocationKey,
        ];
    }
}
