<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Enums\TicketInventoryStatus;
use App\Models\Ticket;
use App\Models\TicketAllocation;
use App\Models\TicketInventoryItem;
use App\Models\TicketProduct;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The reconciler: compares the retail commerce surface against its member
 * stamps and REFUSES SILENT DRIFT.
 *
 * WHAT IS COUNTED
 * For every ACTIVE+allocation-capable product × draw the house advertises:
 * - minted/available/reserved/sold/voided/expired unit counts from the
 *   inventory lane,
 * - per (vendor, product, draw): the allocation's quantity against the
 *   units that name it (member stamp),
 * - the product's own catalog wax (units_total) never over-circulated by
 *   the sum of units on the street,
 * - the SALE-RECORD BRIDGE: sold units that carry a ticket reference must
 *   resolve to that exact ticket (with a matching draw + product stamp),
 *   and sale-record tickets stamped into the product lane must resolve
 *   to stock that has actually moved (Sold or Voided) — because a sale
 *   without stock movement and stock movement without a sale are the two
 *   mirror images of the same theft. Both directions are honored-if-
 *   present: lanes without stamp writers report silence, never invent
 *   rows to compare against.
 *
 * WHAT THE JOB NEVER DOES: it never writes into the inventory, the
 * allocations, the tickets or their ownerships. Finds are LOGGED (drift
 * never silently normalized; a ledger heals upstream, its errors stay
 * visible downstream).
 */
final class VerifyRetailTicketInventoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly ?int $ticketProductId = null,
    ) {
        $this->onQueue(QueueName::Reconciliation->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'verify_retail_inventory_'.($this->ticketProductId ?? 'all').'_'.now()->format('Y-m-d-H');
    }

    /**
     * @return array{products_examined: int, drift_lines: int}
     */
    public function handle(): array
    {
        $window = Carbon::now();
        $products = TicketProduct::query()
            ->when($this->ticketProductId !== null, fn ($q) => $q->whereKey($this->ticketProductId))
            ->orderBy('id')
            ->get();

        $examined = 0;
        $drift = 0;

        foreach ($products as $product) {
            $examined++;

            try {
                $drift += $this->reconcileProduct($product);
            } catch (\Throwable $e) {
                // A reconciliation fault never brings the whole window down:
                // pronounced in the log, mirrored by the web console's own
                // alert rings.
                Log::warning('VerifyRetailTicketInventoryJob: product lane errored', [
                    'product_id' => (int) $product->getKey(),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        Log::info('VerifyRetailTicketInventoryJob: pass complete', [
            'examined' => $examined,
            'drift_lines' => $drift,
            'at' => $window->toIso8601String(),
        ]);

        return ['products_examined' => $examined, 'drift_lines' => $drift];
    }

    /**
     * @return int drift lines found (always >= 0)
     */
    private function reconcileProduct(TicketProduct $product): int
    {
        $productId = (int) $product->getKey();
        $drift = 0;

        $counts = TicketInventoryItem::query()
            ->select('status', DB::raw('count(*) as n'))
            ->where('ticket_product_id', $productId)
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        $onStreet = (int) array_sum(array_map('intval', $counts));

        // The product-row diag: catalog ceiling vs. street reality.
        if ($onStreet > (int) $product->units_total) {
            $drift++;

            Log::error('VerifyRetailTicketInventoryJob: circulation breach', [
                'product_id' => $productId,
                'units_total' => (int) $product->units_total,
                'units_on_street' => $onStreet,
            ]);
        }

        // Per-allocation membership equality: the row's quantity must equal
        // its bound units; disagreement is exactly the fork the batch-8
        // single-source-of-truth rule was designed to forbid.
        $allocations = TicketAllocation::query()
            ->where('ticket_product_id', $productId)
            ->get();

        foreach ($allocations as $allocation) {
            $bound = (int) TicketInventoryItem::query()
                ->where('ticket_allocation_id', (int) $allocation->getKey())
                ->count();

            $saleable = (int) TicketInventoryItem::query()
                ->where('ticket_allocation_id', (int) $allocation->getKey())
                ->whereIn('status', [TicketInventoryStatus::Sold->value])
                ->count();

            $currentBound = $bound - $saleable;

            // For Allocated/Accepted(current conversation) rows the bound
            // number must match the ask; terminal rows are history — drift
            // checks still apply, but the status informs the audience.
            if (! $allocation->status->isTerminal() && $currentBound !== (int) $allocation->quantity) {
                $drift++;

                Log::error('VerifyRetailTicketInventoryJob: allocation drift', [
                    'allocation_key' => substr((string) $allocation->allocation_key, 0, 12),
                    'status' => $allocation->status->value,
                    'quantity' => (int) $allocation->quantity,
                    'bound_current' => $currentBound,
                    'sold' => $saleable,
                ]);
            }

            // Even a terminal allocation must be able to point ALL five
            // Units the court once lent it at the ledger: membership is
            // history that must never drift silently either.
            if ($allocation->status->isTerminal()) {
                $releasedUnsold = (int) TicketInventoryItem::query()
                    ->where('ticket_allocation_id', (int) $allocation->getKey())
                    ->where('status', TicketInventoryStatus::Available->value)
                    ->count();

                if ($releasedUnsold > 0) {
                    $drift++;

                    Log::error('VerifyRetailTicketInventoryJob: terminal allocation still holds unsold paper', [
                        'allocation_key' => substr((string) $allocation->allocation_key, 0, 12),
                        'units_available' => $releasedUnsold,
                    ]);
                }
            }
        }

        $drift += $this->reconcileSalesRecords($product, (int) $product->draw_id);

        return $drift;
    }

    /**
     * The inventory↔ownership/sale bridge, walked in BOTH directions.
     * Fully read-only: an absent writer answers zero rows, never an
     * invented comparison.
     *
     * @return int drift lines found (always >= 0)
     */
    private function reconcileSalesRecords(TicketProduct $product, int $drawId): int
    {
        $productId = (int) $product->getKey();
        $drift = 0;

        // DIRECTION ONE: sold paper that NAMES its sale record must point
        // at a ticket that exists, in the same draw, and (when stamped)
        // in the same product lane. A dangling reference is stock sold
        // with no ownership landing — the classic "money moved, paper
        // didn't" fork.
        TicketInventoryItem::query()
            ->where('ticket_product_id', $productId)
            ->where('status', TicketInventoryStatus::Sold->value)
            ->whereNotNull('metadata->sale->ticket_reference')
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($productId, $drawId, &$drift): void {
                foreach ($items as $item) {
                    $reference = (string) data_get($item->metadata, 'sale.ticket_reference');

                    $ticket = Ticket::query()
                        ->where('ticket_number', $reference)
                        ->first();

                    $consistent = $ticket instanceof Ticket
                        && (int) $ticket->draw_id === $drawId
                        && ((int) data_get($ticket->metadata, 'product.ticket_product_id', 0) ?: $productId) === $productId;

                    if (! $consistent) {
                        $drift++;

                        Log::error('VerifyRetailTicketInventoryJob: sold unit with unresolved sale record', [
                            'serial' => (string) $item->serial,
                            'ticket_reference' => $reference,
                            'ticket_found' => $ticket instanceof Ticket,
                            'product_id' => $productId,
                        ]);
                    }
                }
            });

        // DIRECTION TWO: sale-record tickets stamped INTO this product
        // lane must name stock that has actually moved (Sold/Voided —
        // voided keeps an ownership trail visible without pretending a
        // sale survived). A serial-stamped lane ticket naming free paper
        // is a sale with no stock movement — the mirror fork.
        $laneSerials = Ticket::query()
            ->where('draw_id', $drawId)
            ->where('metadata->product->ticket_product_id', $productId)
            ->whereNotNull('metadata->product->serial')
            ->pluck('ticket_number', 'id')
            ->map(fn (string $n) => strtoupper($n))
            ->all();

        if ($laneSerials !== []) {
            $moved = TicketInventoryItem::query()
                ->where('ticket_product_id', $productId)
                ->whereIn('serial', array_values($laneSerials))
                ->whereIn('status', [
                    TicketInventoryStatus::Sold->value,
                    TicketInventoryStatus::Voided->value,
                ])
                ->pluck('serial')
                ->map(fn (string $serial) => strtoupper($serial))
                ->all();

            $missing = array_diff(array_values($laneSerials), $moved);

            if ($missing !== []) {
                $drift++;

                Log::error('VerifyRetailTicketInventoryJob: sale record without stock movement', [
                    'product_id' => $productId,
                    'serials_unmoved' => array_slice(array_values($missing), 0, 5),
                    'serials_unmoved_total' => count($missing),
                ]);
            }
        }

        return $drift;
    }
}
