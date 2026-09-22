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
 * Sweeps ALREADY-EXPIRED unclaimed prizes into the disposal ledger.
 *
 * WHERE THIS JOB SITS ON THE LADDER
 * ---------------------------------
 * Two separate sweeps keep both earlier trusts intact:
 *
 *   ExpireUnclaimedPrizesJob   (claim lifecycle)   claim rows whose window
 *                                                  lapses turn Expired — the
 *                                                  moment the prize LAPSES.
 *                                                  Same run enrolls the lane
 *                                                  UnclaimedPrizeStatus=Expired.
 *   THIS job                   (disposal view)     every lane record at
 *                                                  Expired turns Swept — the
 *                                                  moment the lapse ENTERS
 *                                                  disposal accounting.
 *
 * CHUNKED, REPLAY-SAFE, TRANSACTION-AWARE
 * ---------------------------------------
 * Chunking lives in the service (chunkById with a configurable size): a
 * backlog writes in steady batches instead of one giant mutation. Replay
 * safety comes from the selector (only Expired lanes match) plus the
 * disposal key anchored at expiry time, so a scheduler retry never mints
 * a new disposal identity. Transaction awareness: each row's stamp is the
 * service's own one-write mutation; the job itself takes no aggregate
 * transaction lock and thus can never hold the queue against a backpressure
 * of already-committed rows.
 *
 * OBSERVABILITY
 * -------------
 * Per-row audits come from the service; the run closes with exactly one
 * summary audit line naming swept count and total lapsed money.
 */
class SweepUnclaimedPrizesJob implements ShouldBeUnique, ShouldQueue
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
    public int $timeout = 120;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    /**
     * @param  int|null  $chunkSize  Rows per sweep chunk (bounded for
     *                               backlog catch-up runs).
     */
    public function __construct(
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::Reconciliation->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for the concurrency lock: one sweep per day.
     */
    public function uniqueId(): string
    {
        return 'sweep_unclaimed_prizes_'.now()->format('Y-m-d');
    }

    /**
     * Run the disposal sweep.
     *
     * @return array{swept_count: int, total_swept_amount: string, disposal_keys: list<string>, executed_at: string}
     */
    public function handle(UnclaimedPrizeService $service): array
    {
        Log::info('SweepUnclaimedPrizesJob: starting disposal sweep', [
            'attempt' => $this->attempts(),
        ]);

        $summary = $service->sweepExpired($this->chunkSize);

        Log::info('SweepUnclaimedPrizesJob: sweep complete', [
            'swept_count' => $summary['swept_count'],
            'total_swept_amount' => $summary['total_swept_amount'],
        ]);

        if ($summary['swept_count'] > 0) {
            $this->recordSummary($summary);
        }

        return $summary;
    }

    /**
     * A failing sweep is loud in the log and quiet in the queue: the next
     * scheduled run retries the selector idempotently.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('SweepUnclaimedPrizesJob: sweep run failed', [
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * The run's summary audit line: null user (scheduler), action Update.
     *
     * @param  array{swept_count: int, total_swept_amount: string}  $summary
     */
    private function recordSummary(array $summary): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => 'unclaimed_prize_disposal',
            'auditable_id' => 0,
            'description' => sprintf(
                'Unclaimed-prize disposal sweep enrolled %d lapsed prize(s) totalling %s.',
                $summary['swept_count'],
                $summary['total_swept_amount'],
            ),
            'metadata' => [
                'swept_count' => $summary['swept_count'],
                'total_swept_amount' => $summary['total_swept_amount'],
                'action' => 'unclaimed_prizes_swept',
            ],
        ]);

        $log->save();
    }
}
