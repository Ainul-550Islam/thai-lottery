<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Services\Prize\ClaimWindowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled sweeper for lapsed prize claim WINDOWS.
 *
 * WHAT THIS JOB IS — AND WHAT IT ISN'T
 * ------------------------------------
 * A thin scheduled trigger over {@see ClaimWindowService::closeExpired()}:
 * every payout whose claim window is stamped Open but whose deadline has
 * passed is turned Closed in one idempotent write, with a per-row audit
 * line from the service. The job owns the cadence; the service owns every
 * rule.
 *
 * It is NOT {@see ExpireUnclaimedPrizesJob}. That sibling sweep expires the
 * CLAIMS (prize-claim lifecycle, Submitted/UnderReview/Approved → Expired).
 * This one closes the WINDOWS (claim_window.metadata lane, derived Open →
 * Closed). The two run independently: a claim can expire while its window
 * stays open for a corrected claim, and a window can close while no claim
 * ever asserted itself against it.
 *
 * GUARANTEES
 * ----------
 * 1. IDEMPOTENT BY CONSTRUCTION. The sweep selector matches only Open +
 *    lapsed windows; a rerun over the same rows selects nothing, so stamping
 *    twice is impossible by selection alone. ShouldBeUnique for the day adds
 *    the same-day coworker protection every sweeper in this codebase carries.
 * 2. NON-ATOMIC ACROSS ROWS, ATOMIC PER ROW. Each window's close is the
 *    service's own one-write mutation; one failing write never un-closes
 *    or stalls another row.
 * 3. OBSERVED. Closed rows carry an audit line each (service side); the
 *    run closes with one summary audit line naming total closed and lapsed
 *    amount so the delta is visible from the audit face alone.
 */
class CloseExpiredClaimWindowsJob implements ShouldBeUnique, ShouldQueue
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
     * @param  int|null  $chunkSize  Rows per chunk handed to the service's
     *                               sweep (bounded when previewing a backlog).
     */
    public function __construct(
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::Reconciliation->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for the concurrency lock: one run per day.
     */
    public function uniqueId(): string
    {
        return 'close_expired_claim_windows_'.now()->format('Y-m-d');
    }

    /**
     * Run the sweep.
     *
     * @return array{closed_count: int, total_lapsed_amount: string, executed_at: string}
     */
    public function handle(ClaimWindowService $service): array
    {
        Log::info('CloseExpiredClaimWindowsJob: starting daily close sweep', [
            'attempt' => $this->attempts(),
        ]);

        $summary = $service->closeExpired($this->chunkSize) + ['executed_at' => now()->toIso8601String()];

        Log::info('CloseExpiredClaimWindowsJob: sweep complete', [
            'closed_count' => $summary['closed_count'],
            'total_lapsed_amount' => $summary['total_lapsed_amount'],
        ]);

        if ($summary['closed_count'] > 0) {
            $this->recordSummary($summary);
        }

        return $summary;
    }

    /**
     * A failing sweep is loud in the log and quiet in the queue: the next
     * scheduled run retries the selector from scratch idempotently.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('CloseExpiredClaimWindowsJob: sweep run failed', [
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * The run's summary audit line: null user (scheduler), action Update.
     *
     * @param  array{closed_count: int, total_lapsed_amount: string}  $summary
     */
    private function recordSummary(array $summary): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => 'claim_window_sweep',
            'auditable_id' => 0,
            'description' => sprintf(
                'Daily claim-window sweep closed %d lapsed window(s) totalling %s.',
                $summary['closed_count'],
                $summary['total_lapsed_amount'],
            ),
            'metadata' => [
                'closed_count' => $summary['closed_count'],
                'total_lapsed_amount' => $summary['total_lapsed_amount'],
                'action' => 'claim_windows_closed',
            ],
        ]);

        $log->save();
    }
}
