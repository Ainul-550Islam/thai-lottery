<?php

declare(strict_types=1);

namespace App\Services\Retail;

use App\DTOs\Retail\TicketAllocationData;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\TicketAllocationStatus;
use App\Enums\TicketInventoryStatus;
use App\Events\RetailTicketAllocated;
use App\Exceptions\TicketAllocationException;
use App\Models\AuditLog;
use App\Models\TicketAllocation;
use App\Models\TicketInventoryItem;
use App\Models\TicketProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Allocates ticket quota to eligible retail vendors.
 *
 * THE INVARIANT STACK THIS SERVICE IS THE COURT OF (in refusal order):
 *
 *   1. REPLAY GEOMETRY   one (vendor, product, draw) = one allocation row,
 *                        keyed deterministically. The same ask replayed
 *                        re-serves; a DIFFERENT ask against the same triple
 *                        is a fork and refused.
 *   2. ELIGIBILITY       vendor lifecycle + capacity, judged once by
 *                        RetailVendorService::assertCanReceive under the
 *                        same transaction the allocation row is written in.
 *   3. CAPACITY          the sum of free+fresh units the vendor would hold
 *                        after this allocation never exceeds its capacity.
 *   4. BOUND BINDING     the units named into the allocation are moved
 *                        through a LOCKED, UPSERT-free UPDATE that asserts
 *                        they're (still) Available + unbound at write time,
 *                        in the single statement — the attempted bind count
 *                        becomes the committed quantity.
 *
 * Membership truth flow: allocated-count is always projected from units'
 * member stamps, never from the allocation row's own arithmetic — this
 * agreement is a structural invariant, and the reconciler job in batch-8
 * [18] exists specifically to catch any code that stops honoring it.
 */
final class TicketAllocationService
{
    public function __construct(
        private readonly RetailVendorService $vendors,
    ) {
    }

    /* --------------------------------------------------- allocate --- */

    /**
     * The vendor-allocation judgment call.
     *
     * @return array{allocation: TicketAllocation, bound: int, replayed: bool}
     *
     * @throws TicketAllocationException
     */
    public function allocate(TicketAllocationData $data): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->allocateWithin($data);
        }

        return DB::transaction(fn (): array => $this->allocateWithin($data));
    }

    /**
     * @return array{allocation: TicketAllocation, bound: int, replayed: bool}
     */
    private function allocateWithin(TicketAllocationData $data): array
    {
        if (!$data->asksForSomething()) {
            throw TicketAllocationException::vendorIneligible(
                $data->vendorId,
                'empty-ask',
                'allocate zero units',
            );
        }

        // LOCK THE ALLOCATION FIRST — its key is the court's own anchor.
        $allocation = TicketAllocation::query()
            ->lockForUpdate()
            ->where('allocation_key', $data->allocationKey)
            ->first();

        if ($allocation instanceof TicketAllocation) {
            // Replay branch: the same ask re-serves; a different ask forks.
            if ((int) $allocation->quantity !== $data->quantity) {
                throw TicketAllocationException::duplicate(
                    $data->vendorId,
                    $data->ticketProductId,
                    $data->drawId,
                    $data->allocationKey,
                );
            }

            $bound = (int) TicketInventoryItem::query()
                ->where('ticket_allocation_id', (int) $allocation->getKey())
                ->count();

            return [
                'allocation' => $allocation,
                'bound' => $bound,
                'replayed' => true,
            ];
        }

        // PRODUCT must exist and the draw must match the product's own
        // draw lane — allocations never span products across draws.
        $product = TicketProduct::query()->lockForUpdate()->find($data->ticketProductId);

        if (! $product instanceof TicketProduct) {
            throw TicketAllocationException::vendorIneligible(
                $data->vendorId,
                'product-missing',
                'allocate against missing product',
            );
        }

        if ((int) $product->draw_id !== $data->drawId) {
            throw TicketAllocationException::vendorIneligible(
                $data->vendorId,
                sprintf('product-draw-mismatch:%d', (int) $product->draw_id),
                'allocate outside the product\'s own draw lane',
            );
        }

        // ELIGIBILITY + CAPACITY, one locked answer.
        $vendor = $this->vendors->assertCanReceive(
            \App\Models\RetailVendor::query()->findOrFail($data->vendorId),
            $data->quantity,
        );

        $allocation = new TicketAllocation();
        $allocation->fill([
            'allocation_key' => $data->allocationKey,
            'vendor_id' => (int) $vendor->getKey(),
            'ticket_product_id' => $data->ticketProductId,
            'draw_id' => $data->drawId,
            'quantity' => $data->quantity,
            'metadata' => [],
        ]);
        $allocation->status = TicketAllocationStatus::Pending;
        $allocation->save();

        // BOUND BINDING: take exactly N freshest Available units of the
        // product+draw, still unbound, in one guarded UPDATE — the count
        // returned by the engine IS the committed count.
        $claimedSerials = TicketInventoryItem::query()
            ->where('ticket_product_id', $data->ticketProductId)
            ->where('draw_id', $data->drawId)
            ->where('status', TicketInventoryStatus::Available->value)
            ->whereNull('ticket_allocation_id')
            ->orderBy('id')
            ->limit($data->quantity)
            ->pluck('id')
            ->all();

        $bound = 0;

        if ($claimedSerials !== []) {
            $bound = (int) TicketInventoryItem::query()
                ->whereIn('id', $claimedSerials)
                ->where('status', TicketInventoryStatus::Available->value)
                ->whereNull('ticket_allocation_id')
                ->update(['ticket_allocation_id' => (int) $allocation->getKey()]);
        }

        if ($bound !== $data->quantity) {
            // Capacity shortfall at write time: pronounced, never invented.
            throw TicketAllocationException::quotaOverflow(
                $data->vendorId,
                $data->quantity,
                $bound,
                $data->quantity - $bound,
            );
        }

        $allocation->status = TicketAllocationStatus::Allocated;
        $allocation->save();

        $this->recordAudit($allocation, sprintf(
            'Allocated %d unit(s) of product #%d (draw #%d) to vendor #%d',
            $data->quantity,
            $data->ticketProductId,
            $data->drawId,
            $data->vendorId,
        ), RiskLevel::Medium);

        DB::afterCommit(function () use ($allocation, $data): void {
            event(new RetailTicketAllocated(
                allocationKey: $data->allocationKey,
                vendorId: (int) $allocation->vendor_id,
                vendorCode: (string) \App\Models\RetailVendor::query()->find((int) $allocation->vendor_id)?->vendor_code,
                ticketProductId: $data->ticketProductId,
                drawId: $data->drawId,
                quantity: $data->quantity,
                allocatedAt: now()->toIso8601String(),
            ));
        });

        return ['allocation' => $allocation, 'bound' => $bound, 'replayed' => false];
    }

    /* ----------------------------------------------------- accept --- */

    /**
     * The vendor acknowledges the paper conversation — the units held are
     * now saleable by its hands.
     *
     * @throws TicketAllocationException
     */
    public function accept(TicketAllocation $allocation): TicketAllocation
    {
        return DB::transaction(function () use ($allocation): TicketAllocation {
            /** @var TicketAllocation|null $locked */
            $locked = TicketAllocation::query()->lockForUpdate()->find((int) $allocation->getKey());

            if (! $locked instanceof TicketAllocation) {
                throw TicketAllocationException::notFound((string) $allocation->allocation_key);
            }

            if ($locked->status === TicketAllocationStatus::Accepted) {
                return $locked; // replay
            }

            if (!$locked->status->canTransitionTo(TicketAllocationStatus::Accepted)) {
                throw TicketAllocationException::stateForbids(
                    (string) $locked->allocation_key,
                    $locked->status->value,
                    TicketAllocationStatus::Accepted->value,
                );
            }

            $locked->status = TicketAllocationStatus::Accepted;
            $locked->save();

            $this->recordAudit($locked, 'Allocation accepted; units saleable', RiskLevel::Low);

            return $locked;
        });
    }

    /* ---------------------------------------------------- release --- */

    /**
     * Release the allocation back to the house — its still-Available
     * member units are unbound in the same transaction, so quota is fraud-
     * free at the permit boundary (never monopolized past the release).
     *
     * @return array{allocation: TicketAllocation, unbound: int}
     *
     * @throws TicketAllocationException
     */
    public function release(TicketAllocation $allocation, string $reason): array
    {
        return DB::transaction(function () use ($allocation, $reason): array {
            /** @var TicketAllocation|null $locked */
            $locked = TicketAllocation::query()->lockForUpdate()->find((int) $allocation->getKey());

            if (! $locked instanceof TicketAllocation) {
                throw TicketAllocationException::notFound((string) $allocation->allocation_key);
            }

            if ($locked->status === TicketAllocationStatus::Released) {
                $unboundReplay = 0;

                return ['allocation' => $locked, 'unbound' => $unboundReplay];
            }

            if (!$locked->status->canTransitionTo(TicketAllocationStatus::Released)) {
                throw TicketAllocationException::stateForbids(
                    (string) $locked->allocation_key,
                    $locked->status->value,
                    TicketAllocationStatus::Released->value,
                );
            }

            $unbound = (int) TicketInventoryItem::query()
                ->where('ticket_allocation_id', (int) $locked->getKey())
                ->where('status', TicketInventoryStatus::Available->value)
                ->update(['ticket_allocation_id' => null]);

            $locked->status = TicketAllocationStatus::Released;
            $locked->save();

            $this->recordAudit($locked, sprintf('Released to the house (%s); %d unit(s) unbound', $reason, $unbound), RiskLevel::Medium);

            return ['allocation' => $locked, 'unbound' => $unbound];
        });
    }

    /* ------------------------------------------------- read-side --- */

    /**
     * Projected allocated-unit count for this allocation: derived from
     * member stamps, never from the row itself.
     */
    public function projectedHeld(TicketAllocation $allocation): int
    {
        return (int) $allocation->items()->count();
    }

    /* ------------------------------------------------- internal --- */

    private function recordAudit(TicketAllocation $allocation, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => TicketAllocation::class,
            'auditable_id' => (int) $allocation->getKey(),
            'description' => sprintf('%s (allocation %s...)', $description, substr((string) $allocation->allocation_key, 0, 12)),
            'metadata' => [
                'ticket_allocation_id' => (int) $allocation->getKey(),
                'allocation_key' => (string) $allocation->allocation_key,
                'vendor_id' => (int) $allocation->vendor_id,
                'ticket_product_id' => (int) $allocation->ticket_product_id,
                'draw_id' => (int) $allocation->draw_id,
                'lane' => 'retail',
            ],
        ]);

        $log->save();
    }
}
