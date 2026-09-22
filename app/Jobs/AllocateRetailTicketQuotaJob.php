<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Retail\TicketAllocationData;
use App\Enums\QueueName;
use App\Exceptions\TicketAllocationException;
use App\Services\Retail\RetailVendorService;
use App\Services\Retail\TicketAllocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Driver: allocate the configured quota on a (product, draw) to every
 * eligible vendor, chunked by vendor so a slow lane never stalls the
 * whole pass.
 *
 * DISCIPLINE
 * - Chunked: one chunk per vendor window, cheapest possible chunking
 *   (page offsets), and each vendor's decision is tried independently —
 *   an uneligible vendor delays nobody.
 * - Idempotent: the allocation lane itself keys deterministic identity;
 *   the job's job-uniqueness key is hourly, layered atop; both refuse to
 *   mint what they already minted.
 * - Never fabricates truth: a vendor that cannot hold is skipped in
 *   writing (per-vendor log), the row shape is always what the service
 *   pronounced, never invented on retry.
 */
final class AllocateRetailTicketQuotaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 3600;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $ticketProductId,
        public readonly int $drawId,
        public readonly int $quantityPerVendor,
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'allocate_retail_quota_'.$this->ticketProductId.'_'.$this->drawId.'_'.$this->quantityPerVendor.'_'.now()->format('Y-m-d-H');
    }

    /**
     * The balance of eligibility → allocations. Diagnostics in the log:
     * skipped vendors never silently uncached.
     *
     * @return array{vendors_examined: int, allocations_minted: int, replays: int, refusals: int}
     */
    public function handle(RetailVendorService $vendors, TicketAllocationService $allocations): array
    {
        if (! $this->quantityPerVendorPosed()) {
            Log::warning('AllocateRetailTicketQuotaJob: empty ask refused at boundary', [
                'quantity_per_vendor' => $this->quantityPerVendor,
            ]);

            return ['vendors_examined' => 0, 'allocations_minted' => 0, 'replays' => 0, 'refusals' => 0];
        }

        $examined = 0;
        $minted = 0;
        $replays = 0;
        $refusals = 0;

        $eligible = $vendors->eligibleVendors();
        $chunkSize = max(1, $this->chunkSize ?? 50);

        foreach ($eligible->chunk($chunkSize) as $vendorChunk) {
            foreach ($vendorChunk as $vendor) {
                $examined++;

                $data = TicketAllocationData::fromInput(
                    vendorId: (int) $vendor->getKey(),
                    ticketProductId: $this->ticketProductId,
                    drawId: $this->drawId,
                    quantity: $this->quantityPerVendor,
                );

                try {
                    $result = $allocations->allocate($data);

                    if ($result['replayed'] ?? false) {
                        $replays++;
                    } else {
                        $minted++;
                    }
                } catch (TicketAllocationException $e) {
                    $refusals++;

                    Log::info('AllocateRetailTicketQuotaJob: vendor skipped', [
                        'vendor_id' => (int) $vendor->getKey(),
                        'reason' => $e->errorCode(),
                    ]);
                }
            }
        }

        Log::info('AllocateRetailTicketQuotaJob: pass complete', [
            'product_id' => $this->ticketProductId,
            'draw_id' => $this->drawId,
            'examined' => $examined,
            'minted' => $minted,
            'replays' => $replays,
            'refusals' => $refusals,
        ]);

        return [
            'vendors_examined' => $examined,
            'allocations_minted' => $minted,
            'replays' => $replays,
            'refusals' => $refusals,
        ];
    }

    private function quantityPerVendorPosed(): bool
    {
        return $this->quantityPerVendor > 0;
    }
}
