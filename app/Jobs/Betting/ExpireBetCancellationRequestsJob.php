<?php

declare(strict_types=1);

namespace App\Jobs\Betting;

use App\Enums\BetAmendmentStatus;
use App\Enums\QueueName;
use App\Enums\TicketShareStatus;
use App\Models\BetAmendment;
use App\Models\TicketShare;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Periodic sweeper for time-boxed betting records.
 *
 * WHAT IT ACTUALLY EXPIRES
 * Cancellations themselves are synchronous and immediate in this codebase, so
 * there is no pending-cancellation queue to drain. What time can orphan:
 *   1. bet_amendments rows left Pending past their expires_at (e.g. a process
 *      died between refund and replacement purchase — the money is already
 *      safely back in the wallet, the row only needs its terminal stamp);
 *   2. ticket_shares rows still stored Active past their expiry (resolution is
 *      compute-on-read, so a lapsed link is already dead; this backfills the
 *      stored status so operators see Expired rather than stale Active).
 *
 * NEITHER SWEEP MOVES MONEY
 * Expiring an amendment writes a status and nothing else. A refunded-and-
 * unpurchased amendment is already the safe terminal money state.
 *
 * RUN SAFETY
 * ShouldBeUnique makes two scheduler instances running this job together a
 * no-op for the second one. Each sweep is bounded by SWEEP_BATCH so a large
 * backlog drains over several runs rather than one long lock-holding run.
 */
class ExpireBetCancellationRequestsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum rows each sweep stamps per entity per run.
     */
    private const SWEEP_BATCH = 200;

    /**
     * Seconds the job stays unique for — comfortably longer than any sweep.
     */
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value ?? 'default');
    }

    /**
     * The unique lock identity: one global sweeper, not per-user.
     */
    public function uniqueId(): string
    {
        return 'expire-bet-cancellation-requests';
    }

    public function handle(): void
    {
        $amendments = $this->expirePendingAmendments();
        $shares = $this->expireLapsedShares();

        if ($amendments > 0 || $shares > 0) {
            Log::info('Betting sweeper completed', [
                'amendments_expired' => $amendments,
                'shares_expired' => $shares,
            ]);
        }
    }

    /**
     * Stamp terminal Expired on pending amendments past their moment.
     *
     * @return int how many rows moved
     */
    private function expirePendingAmendments(): int
    {
        $ids = BetAmendment::query()
            ->expiredPending()
            ->limit(self::SWEEP_BATCH)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        // Guarded bulk update: only rows STILL pending move, so a concurrent
        // application of the same amendment wins and the sweeper is a no-op.
        return BetAmendment::query()
            ->whereIn('id', $ids)
            ->where('status', BetAmendmentStatus::Pending->value)
            ->update([
                'status' => BetAmendmentStatus::Expired->value,
                'updated_at' => now(),
            ]);
    }

    /**
     * Backfill the stored Expired status on lapsed share links.
     *
     * @return int how many rows moved
     */
    private function expireLapsedShares(): int
    {
        $ids = TicketShare::query()
            ->expiredActive()
            ->limit(self::SWEEP_BATCH)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return TicketShare::query()
            ->whereIn('id', $ids)
            ->where('status', TicketShareStatus::Active->value)
            ->update([
                'status' => TicketShareStatus::Expired->value,
                'updated_at' => now(),
            ]);
    }
}
