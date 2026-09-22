<?php

declare(strict_types=1);

namespace App\Services\Retail;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\TicketInventoryStatus;
use App\Exceptions\TicketInventoryException;
use App\Models\AuditLog;
use App\Models\TicketInventoryItem;
use App\Models\TicketProduct;
use Illuminate\Support\Facades\DB;

/**
 * Creates, reserves, releases, sells and voids ticket inventory units.
 *
 * THE CONVERSATION THIS SERVICE IS THE JUDGE OF
 * - MINT: a serialized unit enters the ledger with identity (product, draw,
 *   serial) exactly once. Same identity minted twice = "already exists"
 *   response (replay-safe), identity keys bound under DB unique index the
 *   moment of creation, so races can't mint duplicates.
 * - HOLD: a Reserved state with a wall-clock student_id the release lane
 *   can expire (the ReleaseExpiredTicketReservationsJob comes back when it
 *   falls off the rail).
 * - SALE: the only hop that may require money-collateral choreography —
 *   from Available or Recent-live Reservation, exactly once, never from a
 *   terminal state.
 * - DEATH: a unit dies by void or expiry; both are forever-states.
 *
 * LEDGER DISCIPLINE EVERY STEP SHARES
 * Every transition is: lock-for-update the unit row, assert every legal
 * precondition in CURRENT state (not the state any caller predicted), write
 * state + audit, commit. A gone-side assertion (a stale reserved_until, an
 * already-sold flag) is a REFUSAL to write, never an argument to mutate.
 */
final class TicketInventoryService
{
    /* ------------------------------------------------------------ mint - */

    /**
     * Mint a unit and return it (replays re-serve the existing row).
     *
     * @throws TicketInventoryException
     */
    public function mint(int $ticketProductId, int $drawId, string $serial): TicketInventoryItem
    {
        $canonical = strtoupper(trim($serial));

        $fingerprint = \App\DTOs\Retail\TicketInventoryData::deriveFingerprint($ticketProductId, $drawId, $canonical);

        return DB::transaction(function () use ($ticketProductId, $drawId, $canonical, $fingerprint): TicketInventoryItem {
            $product = TicketProduct::query()->lockForUpdate()->find($ticketProductId);

            if (! $product instanceof TicketProduct) {
                throw TicketInventoryException::notFound($canonical);
            }

            // The physical-print cap: minting cannot exceed the product's
            // catalog ceiling, no matter how many mint calls race.
            $currentCount = (int) TicketInventoryItem::query()
                ->where('ticket_product_id', $ticketProductId)
                ->where('draw_id', $drawId)
                ->count();

            if ($currentCount >= (int) $product->units_total) {
                throw TicketInventoryException::quotaExceeded(
                    $ticketProductId,
                    (int) $product->units_total,
                    $currentCount + 1,
                );
            }

            $existing = TicketInventoryItem::query()
                ->where('inventory_key', $fingerprint)
                ->first();

            if ($existing instanceof TicketInventoryItem) {
                // Replay: return the existing row without re-writing.
                return $existing;
            }

            $item = new TicketInventoryItem();
            $item->fill([
                'inventory_key' => $fingerprint,
                'serial' => $canonical,
                'ticket_product_id' => $ticketProductId,
                'draw_id' => $drawId,
                'fingerprint' => $fingerprint,
                'metadata' => [],
            ]);
            $item->status = TicketInventoryStatus::Available;
            $item->save();

            $this->recordAudit($item, sprintf('Inventory unit minted as Available for product #%d draw #%d', $ticketProductId, $drawId), RiskLevel::Low);

            return $item;
        });
    }

    /* -------------------------------------------------- reservation --- */

    /**
     * Hold a unit inside a named customer/vendor lane until the wall-clock
     * instant $expiresAt. The default hold duration is configuration-free —
     * the caller names the horizon it promises the consumer.
     *
     * @throws TicketInventoryException
     */
    public function reserve(TicketInventoryItem $item, int $vendorId, \DateTimeInterface $expiresAt): TicketInventoryItem
    {
        return DB::transaction(function () use ($item, $vendorId, $expiresAt): TicketInventoryItem {
            /** @var TicketInventoryItem|null $locked */
            $locked = TicketInventoryItem::query()->lockForUpdate()->find((int) $item->getKey());

            if (! $locked instanceof TicketInventoryItem) {
                throw TicketInventoryException::notFound((string) $item->serial);
            }

            $this->assertFingerprint($locked, (string) $item->fingerprint);

            if ($locked->status === TicketInventoryStatus::Reserved
                && (int) $locked->reserved_by_vendor_id === $vendorId) {
                // Same vendor re-asserting its live hold — replay, and the
                // horizon is NOT silently extended: the caller must issue a
                // new reservation for a fresh window.
                return $locked;
            }

            if ($locked->status !== TicketInventoryStatus::Available) {
                throw TicketInventoryException::notReservable((string) $locked->serial, $locked->status->value);
            }

            $locked->status = TicketInventoryStatus::Reserved;
            $locked->reserved_by_vendor_id = $vendorId;
            $locked->reserved_at = now();
            $locked->reserved_until = \Illuminate\Support\Carbon::instance($expiresAt);
            $locked->save();

            $this->recordAudit($locked, sprintf('Reserved for vendor #%d until %s', $vendorId, $expiresAt->format(\DateTimeInterface::ATOM)), RiskLevel::Low);

            return $locked;
        });
    }

    /**
     * Release a live reservation back to Available. Only the Reserved
     * lane answers to release — sold/voided/expired paper is refused, in
     * writing, and never touched.
     *
     * @throws TicketInventoryException
     */
    public function release(TicketInventoryItem $item): TicketInventoryItem
    {
        return DB::transaction(function () use ($item): TicketInventoryItem {
            /** @var TicketInventoryItem|null $locked */
            $locked = TicketInventoryItem::query()->lockForUpdate()->find((int) $item->getKey());

            if (! $locked instanceof TicketInventoryItem) {
                throw TicketInventoryException::notFound((string) $item->serial);
            }

            if ($locked->status !== TicketInventoryStatus::Reserved) {
                throw TicketInventoryException::notReleasable((string) $locked->serial, $locked->status->value);
            }

            $locked->status = TicketInventoryStatus::Available;
            $locked->reserved_by_vendor_id = null;
            $locked->reserved_at = null;
            $locked->reserved_until = null;
            $locked->save();

            $this->recordAudit($locked, 'Reservation released back to Available', RiskLevel::Low);

            return $locked;
        });
    }

    /* --------------------------------------------------------- sale --- */

    /**
     * Sell a unit: Available or Reservation-served paper, exactly once,
     * inside the caller's chosen transaction (or its own, if unwrapped).
     * The caller supplies the vendoring context (an allocated lane must
     * name this unit as its member before sale if voucher commerce demands
     * it) — the service asserts state, the caller asserts entitlement.
     *
     * @throws TicketInventoryException
     */
    public function sell(TicketInventoryItem $item): TicketInventoryItem
    {
        return DB::transaction(function () use ($item): TicketInventoryItem {
            /** @var TicketInventoryItem|null $locked */
            $locked = TicketInventoryItem::query()->lockForUpdate()->find((int) $item->getKey());

            if (! $locked instanceof TicketInventoryItem) {
                throw TicketInventoryException::notFound((string) $item->serial);
            }

            if (! in_array($locked->status, [TicketInventoryStatus::Available, TicketInventoryStatus::Reserved], true)) {
                throw TicketInventoryException::notSaleable((string) $locked->serial, $locked->status->value);
            }

            $locked->status = TicketInventoryStatus::Sold;
            $locked->reserved_at = null;
            $locked->reserved_until = null;
            $locked->save();

            $this->recordAudit($locked, 'Sold into the consumer lane', RiskLevel::Medium);

            return $locked;
        });
    }

    /* ------------------------------------------------------ terminal --- */

    /**
     * Void a unit (paper destroyed / written off / police-seized).
     *
     * @throws TicketInventoryException
     */
    public function void(TicketInventoryItem $item, string $reason): TicketInventoryItem
    {
        return DB::transaction(function () use ($item, $reason): TicketInventoryItem {
            /** @var TicketInventoryItem|null $locked */
            $locked = TicketInventoryItem::query()->lockForUpdate()->find((int) $item->getKey());

            if (! $locked instanceof TicketInventoryItem) {
                throw TicketInventoryException::notFound((string) $item->serial);
            }

            if ($locked->status === TicketInventoryStatus::Voided) {
                return $locked; // re-run replay
            }

            if (!$locked->status->canTransitionTo(TicketInventoryStatus::Voided)) {
                throw TicketInventoryException::stateForbids((string) $locked->serial, $locked->status->value, 'void');
            }

            $locked->status = TicketInventoryStatus::Voided;
            $locked->voided_reason = $reason;
            $locked->save();

            $this->recordAudit($locked, sprintf('Voided: %s', $reason), RiskLevel::High);

            return $locked;
        });
    }

    /**
     * Expire a unit its draw window has left behind (replay-safe).
     *
     * @throws TicketInventoryException
     */
    public function expire(TicketInventoryItem $item): TicketInventoryItem
    {
        return DB::transaction(function () use ($item): TicketInventoryItem {
            /** @var TicketInventoryItem|null $locked */
            $locked = TicketInventoryItem::query()->lockForUpdate()->find((int) $item->getKey());

            if (! $locked instanceof TicketInventoryItem) {
                throw TicketInventoryException::notFound((string) $item->serial);
            }

            if ($locked->status === TicketInventoryStatus::Expired) {
                return $locked;
            }

            if (!$locked->status->canTransitionTo(TicketInventoryStatus::Expired)) {
                throw TicketInventoryException::stateForbids((string) $locked->serial, $locked->status->value, 'expire');
            }

            $locked->status = TicketInventoryStatus::Expired;
            $locked->save();

            $this->recordAudit($locked, 'Expired past its draw window', RiskLevel::Medium);

            return $locked;
        });
    }

    /* ---------------------------------------------------- read-side --- */

    /**
     * All units held by the named allocation lane.
     *
     * @return list<TicketInventoryItem>
     */
    public function itemsForAllocation(int $allocationId): array
    {
        return TicketInventoryItem::query()
            ->where('ticket_allocation_id', $allocationId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Headroom honest count: units the product lane currently carries.
     */
    public function countFor(int $ticketProductId, int $drawId): int
    {
        return (int) TicketInventoryItem::query()
            ->where('ticket_product_id', $ticketProductId)
            ->where('draw_id', $drawId)
            ->count();
    }

    /* ------------------------------------------------- internality --- */

    /**
     * A identity-lane double-check running before any transition: the
     * ledger fingerprint the caller saw is what it's reprising operations
     * against. Staleness winds up refusing in writing.
     *
     * @throws TicketInventoryException
     */
    private function assertFingerprint(TicketInventoryItem $item, string $expected): void
    {
        if (! hash_equals((string) $item->fingerprint, $expected)) {
            throw TicketInventoryException::fingerprintMismatch(
                (string) $item->serial,
                (string) $item->fingerprint,
                $expected,
            );
        }
    }

    /**
     * Every lane mutation leaves one scrubbed audit row: WHAT happened to
     * WHICH unit, anchored on its serial and strip-mined identity.
     */
    private function recordAudit(TicketInventoryItem $item, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => TicketInventoryItem::class,
            'auditable_id' => (int) $item->getKey(),
            'description' => sprintf('%s on inventory unit [%s]', $description, (string) $item->serial),
            'metadata' => [
                'ticket_inventory_item_id' => (int) $item->getKey(),
                'serial' => (string) $item->serial,
                'ticket_product_id' => (int) $item->ticket_product_id,
                'draw_id' => (int) $item->draw_id,
                'lane' => 'inventory',
            ],
        ]);

        $log->save();
    }
}
