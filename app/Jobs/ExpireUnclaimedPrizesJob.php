<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Services\Prize\UnclaimedPrizeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled sweeper for lapsed prize claim windows.
 *
 * WHAT THE DAILY RUN DOES
 * -----------------------
 * One run → one batch → every claim whose window closed with no payment is
 * stamped Expired (claim state machine: Submitted/UnderReview/Approved →
 * Expired), one audit line per row, one summary audit line for the run.
 * Lapsed money stops presenting as active obligation; nothing is refunded,
 * confiscated or silently written off — the payout rows stay in place with
 * their new state, and finance sees the delta through the claim-status
 * projections that UnclaimedPrizeService feeds.
 *
 * GUARANTEES
 * ----------
 * 1. IDEMPOTENT BY CONSTRUCTION. Expired is terminal: rerunning the job over
 *    the same rows matches nothing, so the stamp is a no-op for any payout
 *    whose window already closed once. Combined with ShouldBeUnique for the
 *    day, two workers can never double-stamp the same claim.
 * 2. NON-ATOMIC ACROSS ROWS, ATOMIC PER ROW. Each row's stamp happens inside
 *    the service's own write; a failing payout never stalls or un-expires
 *    any other, and its exception is collected rather than hurled so the
 *    sweep completes and the anomalies are reported.
 * 3. OBSERVED. The run writes a summary audit line with expired count and
 *    total lapsed amount; rows that failed to stamp appear in the exception
 *    log with their ids for manual review.
 */
class ExpireUnclaimedPrizesJob implements ShouldBeUnique, ShouldQueue
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
     * Execution timeout in seconds. Sweep plus audit writes at two minutes is
     * ample; a backlog bigger than that is a bigger operational conversation.
     */
    public int $timeout = 120;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    /**
     * @param  int|null  $chunkSize  Rows per chunk from the sweep (bounded
     *                               when previewing a backlog).
     */
    public function __construct(
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::Reconciliation->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for the concurrency lock.
     */
    public function uniqueId(): string
    {
        return 'expire_unclaimed_prizes_'.now()->format('Y-m-d');
    }

    /**
     * Run the sweep.
     *
     * @return array{expired_count: int, total_expired_amount: string, payouts: list<array<string, mixed>>, executed_at: string}|null
     */
    public function handle(UnclaimedPrizeService $service): ?array
    {
        Log::info('ExpireUnclaimedPrizesJob: starting daily sweep', [
            'attempt' => $this->attempts(),
        ]);

        $summary = $service->expireLapsedClaims($this->chunkSize);

        Log::info('ExpireUnclaimedPrizesJob: sweep complete', [
            'expired_count' => $summary['expired_count'],
            'total_expired_amount' => $summary['total_expired_amount'],
        ]);

        if ($summary['expired_count'] > 0) {
            $this->recordSummary($summary);
        }

        return $summary;
    }

    /**
     * The run's summary audit line: null user (scheduler), action Update.
     *
     * @param  array{expired_count: int, total_expired_amount: string}  $summary
     */
    private function recordSummary(array $summary): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => 'prize_claim_sweep',
            'auditable_id' => 0,
            'description' => sprintf(
                'Daily unclaimed-prize sweep expired %d claim(s) totalling %s.',
                $summary['expired_count'],
                $summary['total_expired_amount'],
            ),
            'metadata' => [
                'expired_count' => $summary['expired_count'],
                'total_expired_amount' => $summary['total_expired_amount'],
                'chunk_size' => $this->chunkSize,
            ],
        ]);

        $log->save();
    }

    /**
     * Handle permanent job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ExpireUnclaimedPrizesJob: job failed permanently after maximum attempts', [
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Critical,
            'auditable_type' => 'prize_claim_sweep',
            'auditable_id' => 0,
            'description' => sprintf(
                'Unclaimed-prize sweep failed permanently after %d attempts: %s',
                $this->attempts(),
                $exception->getMessage(),
            ),
            'metadata' => [
                'attempts' => $this->attempts(),
                'error' => $exception->getMessage(),
                'chunk_size' => $this->chunkSize,
            ],
        ]);

        $log->save();
    }
}
