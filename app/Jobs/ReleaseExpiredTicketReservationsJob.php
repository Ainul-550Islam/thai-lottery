<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Enums\TicketInventoryStatus;
use App\Exceptions\TicketInventoryException;
use App\Models\TicketInventoryItem;
use App\Services\Retail\TicketInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The reservation-court's scheduled sweeper.
 *
 * WHAT IT DOES, AND WHAT IT NEVER DOES
 * - releases ONLY lane-stale Reservations into Available, once instant at
 *   a time; sold/voided/expired paper is echoed as WHAT IT IS, not touched
 * - never releases "sold" or "claimed" lanes: reservation expiry is a
 *   wall-clock fact, not an authorization for state rewrite; the sweeper
 *   releases only units whose reservation lifecycle has extinguished.
 * - idempotent by hourly key; the per-unit service lane carries its own
 *   replay-safety independently.
 */
final class ReleaseExpiredTicketReservationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'release_expired_reservations_'.now()->format('Y-m-d-H');
    }

    /**
     * Release every stale reservation currently on the ledger.
     *
     * @return array{released: int, examined: int}
     */
    public function handle(TicketInventoryService $inventory): array
    {
        $now = now();
        $examined = 0;
        $released = 0;

        TicketInventoryItem::query()
            ->where('status', TicketInventoryStatus::Reserved->value)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($units) use ($inventory, &$released, &$examined): void {
                foreach ($units as $unit) {
                    $examined++;

                    try {
                        $inventory->release($unit);
                        $released++;
                    } catch (TicketInventoryException $e) {
                        // Honest refusal: the unit moved between the selector
                        // and our call (someone may have sold it). Echo it
                        // as what it is never rewrite it from here.
                        Log::info('ReleaseExpiredTicketReservationsJob: unit skipped', [
                            'serial' => (string) $unit->serial,
                            'reason' => $e->errorCode(),
                        ]);
                    }
                }
            });

        Log::info('ReleaseExpiredTicketReservationsJob: pass complete', [
            'examined' => $examined,
            'released' => $released,
        ]);

        return ['examined' => $examined, 'released' => $released];
    }
}
