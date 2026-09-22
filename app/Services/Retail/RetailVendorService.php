<?php

declare(strict_types=1);

namespace App\Services\Retail;

use App\Enums\AuditAction;
use App\Enums\RetailVendorStatus;
use App\Enums\RiskLevel;
use App\Exceptions\TicketAllocationException;
use App\Models\AuditLog;
use App\Models\RetailVendor;
use Illuminate\Support\Facades\DB;

/**
 * Vendor eligibility and status management.
 *
 * THE COURT THIS SERVICE PRESIDES OVER
 * - WHO the channel is (registration replayed by vendor_code — the channel
 *   exists only once on the ledger).
 * - HOW MANY units of paper the channel may hold simultaneously — the
 *   capacity surface, guarded at allocation time with the row lock held so
 *   overscheduling never races past it.
 * - WHETHER the channel may operate today at all (RetailVendorStatus):
 *   receives only when Active; sells only when Active; Suspended/Closed
 *   boundaries refuse in writing (they stop commerce and decommission it.).
 *
 * All lifecycle moves are one-row transactions under the enum's own
 * transition map; the map refuses names no status may step to before
 * any row is touched.
 */
final class RetailVendorService
{
    /* -------------------------------------------------- registration - */

    /**
     * Register the channel, or re-serve its existing row by vendor_code.
     *
     * @return array{vendor: RetailVendor, replayed: bool}
     */
    public function register(string $vendorCode, string $name, int $quotaCapacity, ?string $contactEmail = null, ?string $contactPhone = null): array
    {
        $canonical = strtoupper(trim($vendorCode));

        return DB::transaction(function () use ($canonical, $name, $quotaCapacity, $contactEmail, $contactPhone): array {
            $existing = RetailVendor::query()->where('vendor_code', $canonical)->first();

            if ($existing instanceof RetailVendor) {
                return ['vendor' => $existing, 'replayed' => true];
            }

            $vendor = new RetailVendor();
            $vendor->fill([
                'vendor_code' => $canonical,
                'name' => $name,
                'contact_email' => $contactEmail,
                'contact_phone' => $contactPhone,
                'quota_capacity' => max(0, $quotaCapacity),
                'metadata' => [],
            ]);
            $vendor->status = RetailVendorStatus::Pending;
            $vendor->save();

            $this->recordAudit($vendor, sprintf('Registered with quota capacity %d; status Pending', $quotaCapacity), RiskLevel::Medium);

            return ['vendor' => $vendor, 'replayed' => false];
        });
    }

    /* ------------------------------------------------------ lifecycle - */

    /**
     * @throws \App\Exceptions\TicketAllocationException
     */
    public function activate(RetailVendor $vendor): RetailVendor
    {
        return $this->transitionTo($vendor, RetailVendorStatus::Active, 'activated');
    }

    /**
     * @throws \App\Exceptions\TicketAllocationException
     */
    public function suspend(RetailVendor $vendor, string $reason): RetailVendor
    {
        $out = $this->transitionTo($vendor, RetailVendorStatus::Suspended, sprintf('suspended: %s', $reason));

        return $out;
    }

    /**
     * @throws \App\Exceptions\TicketAllocationException
     */
    public function close(RetailVendor $vendor, string $reason): RetailVendor
    {
        return $this->transitionTo($vendor, RetailVendorStatus::Closed, sprintf('closed: %s', $reason));
    }

    /* --------------------------------------------------- eligibility - */

    /**
     * The full final test the allocation court asks: is THIS vendor
     * eligible to receive exactly $additional units RIGHT NOW?
     *
     * Lifecycle + capacity, one locked answer. Callers with fresh facts
     * may call this inside their own transactions (it locks the vendor
     * row itself); outside-transaction callers are wrapped cleanly.
     *
     * @throws \App\Exceptions\TicketAllocationException
     */
    public function assertCanReceive(RetailVendor $vendor, int $additionalUnits): RetailVendor
    {
        $lockFn = function () use ($vendor, $additionalUnits): RetailVendor {
            /** @var RetailVendor|null $locked */
            $locked = RetailVendor::query()->lockForUpdate()->find((int) $vendor->getKey());

            if (! $locked instanceof RetailVendor) {
                throw TicketAllocationException::vendorIneligible((int) $vendor->getKey(), 'missing', 'receive');
            }

            if (! $locked->status->mayReceiveAllocation()) {
                throw TicketAllocationException::vendorIneligible(
                    (int) $locked->getKey(),
                    $locked->status->value,
                    'receive allocation',
                );
            }

            $held = \App\Models\TicketInventoryItem::query()
                ->whereIn('ticket_allocation_id', function ($q) use ($locked): void {
                    $q->select('id')->from('ticket_allocations')->where('vendor_id', (int) $locked->getKey());
                })
                ->whereIn('status', [\App\Enums\TicketInventoryStatus::Available->value, \App\Enums\TicketInventoryStatus::Reserved->value])
                ->count();

            if ($held + $additionalUnits > (int) $locked->quota_capacity) {
                throw TicketAllocationException::quotaOverflow(
                    (int) $locked->getKey(),
                    (int) $locked->quota_capacity,
                    $held,
                    $additionalUnits,
                );
            }

            return $locked;
        };

        return $vendor->exists && DB::transactionLevel() > 0 ? $lockFn() : DB::transaction($lockFn);
    }

    /**
     * May this vendor sell today? Synchronous guard for the selling lanes
     * (they still lock the unit rows and assert their own preconditions;
     * this guard is fair and early so queries refuse cheaply).
     *
     * @throws \App\Exceptions\TicketAllocationException
     */
    public function assertCanSell(RetailVendor $vendor): RetailVendor
    {
        $locked = RetailVendor::query()->find((int) $vendor->getKey());

        if (! $locked instanceof RetailVendor) {
            throw TicketAllocationException::vendorIneligible((int) $vendor->getKey(), 'missing', 'sell');
        }

        if (! $locked->status->maySell()) {
            throw TicketAllocationException::vendorIneligible(
                (int) $locked->getKey(),
                $locked->status->value,
                'sell',
            );
        }

        return $locked;
    }

    /**
     * Vendors in the active lane, for chunked allocation fan-outs.
     *
     * @return \Illuminate\Support\Collection<int, RetailVendor>
     */
    public function eligibleVendors(): \Illuminate\Support\Collection
    {
        return RetailVendor::query()
            ->where('status', \App\Enums\RetailVendorStatus::Active->value)
            ->where('quota_capacity', '>', 0)
            ->orderBy('id')
            ->get();
    }

    /* ------------------------------------------------- internality —— */

    /**
     * One lifecycle step, the enum's map as the judge.
     *
     * @throws \App\Exceptions\TicketAllocationException
     */
    private function transitionTo(RetailVendor $vendor, RetailVendorStatus $target, string $note): RetailVendor
    {
        return DB::transaction(function () use ($vendor, $target, $note): RetailVendor {
            /** @var RetailVendor|null $locked */
            $locked = RetailVendor::query()->lockForUpdate()->find((int) $vendor->getKey());

            if (! $locked instanceof RetailVendor) {
                throw TicketAllocationException::vendorIneligible((int) $vendor->getKey(), 'missing', (string) $target->value);
            }

            if ($locked->status === $target) {
                return $locked; // replay
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw TicketAllocationException::stateForbids(
                    (string) $locked->vendor_code,
                    $locked->status->value,
                    $target->value,
                );
            }

            $from = $locked->status;
            $locked->status = $target;
            $locked->save();

            $this->recordAudit($locked, sprintf('%s → %s (%s)', $from->value, $target->value, $note), RiskLevel::Medium);

            return $locked;
        });
    }

    /**
     * Every vendor-court mutation leaves one scrubbed audit row: WHO the
     * channel was, WHICH verdict the court pronounced, never their contact
     * details in the audit trail.
     */
    private function recordAudit(RetailVendor $vendor, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => RetailVendor::class,
            'auditable_id' => (int) $vendor->getKey(),
            'description' => sprintf('%s on vendor [%s]', $description, (string) $vendor->vendor_code),
            'metadata' => [
                'retail_vendor_id' => (int) $vendor->getKey(),
                'vendor_code' => (string) $vendor->vendor_code,
                'status' => $vendor->status->value,
                'lane' => 'retail',
            ],
        ]);

        $log->save();
    }
}
