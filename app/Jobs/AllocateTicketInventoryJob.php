<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Enums\TicketProductStatus;
use App\Models\AuditLog;
use App\Models\TicketProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Allocates available ticket-inventory units to fixed-ticket products.
 *
 * WHAT ALLOCATION MEANS HERE
 * --------------------------
 * Passing a quota of print units into a product's IN SCOPE stock: the
 * product's units_allocated counter advances toward its units_total cap,
 * atomically. Nothing retail is minted — retail tickets are player-bound
 * issuance and this lane never creates them. Inventory allocation is the
 * supply-side commit "these N units may be sold under this product".
 *
 * ATOMIC ALLOCATION, QUOTA GUARD
 * ------------------------------
 * The counter advance happens as ONE guarded UPDATE:
 *
 *   UPDATE ticket_products SET units_allocated = units_allocated + :n
 *   WHERE id = :id AND units_allocated + :n <= units_total
 *
 * — no read-then-write race; the row-level lock of the UPDATE itself is
 * the guard. When the quota exceeds the remaining headroom, the whole
 * advance of this invocation is refused (allocation may never overshoot
 * the print cap: tickets no one can pay for are obligations to explain).
 *
 * REPLAY-SAFE BY TAGGED IDEMPOTENCY
 * ---------------------------------
 * Each allocation carries an idempotency tag (product + caller-provided
 * tag or derived run date) stamped into the product's metadata:
 * re-running with the same tag re-serves the recorded advance instead
 * of advancing twice — worker redelivery and operator double-clicks
 * collapse onto one advance.
 *
 * ELIGIBILITY
 * -----------
 * Allocation may only advance against products in Draft or Active —
 * never Suspended/Closed/Withdrawn lanes (a suspended line first needs
 * its incident resolved before more stock joins it).
 */
class AllocateTicketInventoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum retry attempts before failing permanently.
     */
    public int $tries = 2;

    /**
     * Exponential backoff delays in seconds.
     *
     * @var list<int>
     */
    public array $backoff = [30, 90];

    /**
     * Execution timeout in seconds.
     */
    public int $timeout = 60;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $ticketProductId,
        public readonly int $units,
        public readonly ?string $allocationTag = null,
    ) {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier: one allocation per (product, tag/day).
     */
    public function uniqueId(): string
    {
        return 'allocate_ticket_inventory_'.$this->ticketProductId.'_'.($this->allocationTag ?? now()->format('Y-m-d'));
    }

    /**
     * The deterministic idempotency tag for this allocation.
     */
    public function resolvedTag(): string
    {
        return $this->allocationTag
            ?? hash('sha256', sprintf('allocate:%d:%d:%s', $this->ticketProductId, $this->units, now()->format('Y-m-d')));
    }

    /**
     * Advance the allocation counter.
     *
     * @return array{product_id: int, allocated: int, units_allocated: int, units_total: int, replayed: bool}
     */
    public function handle(): array
    {
        Log::info('AllocateTicketInventoryJob: advancing allocation', [
            'product_id' => $this->ticketProductId,
            'units' => $this->units,
            'attempt' => $this->attempts(),
        ]);

        if ($this->units <= 0) {
            Log::warning('AllocateTicketInventoryJob: non-positive allocation refused', [
                'product_id' => $this->ticketProductId,
                'units' => $this->units,
            ]);

            return [
                'product_id' => $this->ticketProductId,
                'allocated' => 0,
                'units_allocated' => 0,
                'units_total' => 0,
                'replayed' => true,
            ];
        }

        $result = DB::transaction(function (): array {
            /** @var TicketProduct|null $product */
            $product = TicketProduct::query()->lockForUpdate()->find($this->ticketProductId);

            if (! $product instanceof TicketProduct) {
                throw new \RuntimeException(sprintf('AllocateTicketInventoryJob: product #%d not found.', $this->ticketProductId));
            }

            // REPLAY BY TAG: the same advance tag already stamped → re-serve.
            $metadata = is_array($product->metadata) ? $product->metadata : [];
            $ledger = is_array($metadata['allocation_ledger'] ?? null) ? $metadata['allocation_ledger'] : [];
            $tag = $this->resolvedTag();

            if (isset($ledger[$tag])) {
                return [
                    'product_id' => $this->ticketProductId,
                    'allocated' => (int) ($ledger[$tag]['allocated'] ?? 0),
                    'units_allocated' => (int) $product->units_allocated,
                    'units_total' => (int) $product->units_total,
                    'replayed' => true,
                ];
            }

            // ELIGIBILITY.
            if (! in_array($product->status, [TicketProductStatus::Draft, TicketProductStatus::Active], true)) {
                throw new \RuntimeException(sprintf(
                    'AllocateTicketInventoryJob: product [%s] is %s; allocations may only advance Draft/Active lanes.',
                    $product->product_code,
                    $product->status->value,
                ));
            }

            // QUOTA GUARD + ATOMIC ADVANCE — one guarded write, no race.
            if ((int) $product->units_allocated + $this->units > (int) $product->units_total) {
                throw new \RuntimeException(sprintf(
                    'AllocateTicketInventoryJob: product [%s] has %d units remaining; an advance of %d would overshoot the print cap of %d.',
                    $product->product_code,
                    $product->remainingUnits(),
                    $this->units,
                    (int) $product->units_total,
                ));
            }

            $product->units_allocated = (int) $product->units_allocated + $this->units;
            $product->save();

            $ledger[$tag] = [
                'allocated' => $this->units,
                'at' => now()->toIso8601String(),
            ];
            $metadata['allocation_ledger'] = $ledger;
            $product->metadata = $metadata;
            $product->save();

            $this->recordAudit($product, $this->units);

            return [
                'product_id' => $this->ticketProductId,
                'allocated' => $this->units,
                'units_allocated' => (int) $product->units_allocated,
                'units_total' => (int) $product->units_total,
                'replayed' => false,
            ];
        });

        Log::info('AllocateTicketInventoryJob: advance complete', [
            'product_id' => $this->ticketProductId,
            'allocated' => $result['allocated'],
            'replayed' => $result['replayed'],
        ]);

        return $result;
    }

    /**
     * A thrown run is best handled by pixels, not money: the advance is
     * atomic; the retry re-derives the same tag and joins or re-tries.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('AllocateTicketInventoryJob: allocation run failed', [
            'product_id' => $this->ticketProductId,
            'units' => $this->units,
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }

    private function recordAudit(TicketProduct $product, int $units): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => TicketProduct::class,
            'auditable_id' => $product->getKey(),
            'description' => sprintf(
                'Ticket product [%s] inventory allocation advanced by %d units (%d/%d allocated).',
                $product->product_code,
                $units,
                (int) $product->units_allocated,
                (int) $product->units_total,
            ),
            'metadata' => [
                'product_key' => $product->product_key,
                'product_code' => $product->product_code,
                'allocation_tag' => $this->resolvedTag(),
                'allocated' => $units,
                'units_allocated' => (int) $product->units_allocated,
                'units_total' => (int) $product->units_total,
                'action' => 'ticket_inventory_allocated',
            ],
        ]);

        $log->save();
    }
}
