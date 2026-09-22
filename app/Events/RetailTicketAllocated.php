<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A vendor allocation conversation completed its Allocated write.
 *
 * WHY AN EVENT
 * The allocating service and anything downstream of it (audit, stock
 * projections, ops dashboards) never touch each other — the watcher
 * reads/snapshots never inform the commitment. One well-shaped facts pack
 * moves through the seam: references and lifecycle stamps only.
 *
 * PRIVACY
 * Never the vendor's contact details, never staffing worker names.
 * The allocation is, by design, the single handle the listener needs; the
 * rider looks up addressbook traces itself if it harbours them.
 */
final class RetailTicketAllocated
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  string  $allocatedAt  ISO-8601 instant the allocation
     *                               conversation moved to Allocated.
     */
    public function __construct(
        public readonly string $allocationKey,
        public readonly int $vendorId,
        public readonly ?string $vendorCode,
        public readonly int $ticketProductId,
        public readonly int $drawId,
        public readonly int $quantity,
        public readonly string $allocatedAt,
    ) {}

    /**
     * The listener's dedupe anchor: allocation key per (allocation, act).
     */
    public function anchorFor(string $action): string
    {
        return hash('sha256', sprintf('retail-allocation:%s:%s', $this->allocationKey, $action));
    }

    /**
     * @return array<string, mixed>
     */
    public function logPayload(): array
    {
        return [
            'allocation_key' => substr($this->allocationKey, 0, 12),
            'vendor' => $this->vendorCode,
            'ticket_product_id' => $this->ticketProductId,
            'draw_id' => $this->drawId,
            'quantity' => $this->quantity,
            'allocated_at' => $this->allocatedAt,
        ];
    }
}
