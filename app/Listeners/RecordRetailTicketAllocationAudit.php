<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\RetailTicketAllocated;
use App\Models\AuditLog;
use App\Models\TicketAllocation;
use Illuminate\Support\Facades\Log;

/**
 * The allocation-court's audit scribe.
 *
 * CONTRACT
 * Exactly one audit row per (allocation_key, act) — never more, even when
 * the dispatcher runs retries: the anchor IS the idempotency. Sensitive
 * facts stay scrubbed: references and statuses, never contact lines.
 */
final class RecordRetailTicketAllocationAudit
{
    public function __construct() {}

    /**
     * Whether the court row for this anchor already exists.
     */
    public static function alreadyRecorded(TicketAllocation $allocation, string $action): bool
    {
        return AuditLog::query()
            ->where('auditable_type', TicketAllocation::class)
            ->where('auditable_id', (int) $allocation->getKey())
            ->where('action', AuditAction::Update->value)
            ->where('metadata->anchor', self::anchorFor($allocation, $action))
            ->exists();
    }

    /**
     * The deterministic anchor for idempotency anchoring.
     */
    public static function anchorFor(TicketAllocation $allocation, string $action): string
    {
        return hash('sha256', sprintf('retail-allocation-audit:%s:%s', (string) $allocation->allocation_key, $action));
    }

    public function handle(RetailTicketAllocated $event): void
    {
        $allocation = TicketAllocation::query()
            ->where('allocation_key', $event->allocationKey)
            ->first();

        if (! $allocation instanceof TicketAllocation) {
            // Event arrived for an allocation that isn't on the ledger:
            // pronounced, never swallowed — monitoring must be able to
            // act on the shape of such edges.
            Log::warning('RecordRetailTicketAllocationAudit: event for missing allocation', [
                'allocation_key' => substr($event->allocationKey, 0, 12),
            ]);

            return;
        }

        if (self::alreadyRecorded($allocation, 'allocated')) {
            return; // replay: never mint a duplicate audit row
        }

        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => TicketAllocation::class,
            'auditable_id' => (int) $allocation->getKey(),
            'description' => sprintf(
                'Allocation heard and recorded: %d unit(s) for [%s]',
                $event->quantity,
                $event->vendorCode,
            ),
            'metadata' => [
                'anchor' => self::anchorFor($allocation, 'allocated'),
                'ticket_allocation_id' => (int) $allocation->getKey(),
                'allocation_key' => substr($event->allocationKey, 0, 12),
                'vendor_id' => $event->vendorId,
                'vendor_code' => $event->vendorCode,
                'ticket_product_id' => $event->ticketProductId,
                'draw_id' => $event->drawId,
                'quantity' => $event->quantity,
                'allocated_at' => $event->allocatedAt,
                'lane' => 'retail',
            ],
        ]);

        $log->save();
    }
}
