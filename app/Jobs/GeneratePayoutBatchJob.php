<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\Payout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled GENERATOR of payout batches: finds every payout that is both
 * checker-Approved in the approval lane and still Pending on the money side,
 * then dispatches one {@see ProcessPayoutBatchJob} per chunk of ids.
 *
 * THE SEPARATION, AND WHY
 * -----------------------
 *   THIS job (generator):        selection + fan-out. The only place the
 *                                "approved AND pending" predicate lives.
 *   ProcessPayoutBatchJob:       execution. Moves money per payout id,
 *                                compensates failures, already concurrency-
 *                                safe per row (SELECT ... FOR UPDATE) and
 *                                per batch tag (ShouldBeUnique).
 *
 * A payout whose approval lane is still Pending, Rejected, or never opened
 * is invisible to the batch executor — the four-eyes console names the only
 * door money can leave by. The payout row's own status may only be Pending
 * for selection: Completed/Failed/Reversed rows are never re-selected and
 * never re-credited.
 *
 * GUARANTEES
 * ----------
 * 1. CONCURRENCY SAFE. ShouldBeUnique keyed on the run minute; two schedulers
 *    landing in the same minute produce one generator run, and ProcessPayoutBatchJob's
 *    own tag-keyed uniqueness protects whatever chunks the fan-out names.
 * 2. IDEMPOTENT BY CONSTRUCTION. Selection is read-only; reruns that select
 *    the same ids re-dispatch executor jobs that replay against per-payout
 *    row state instead of crediting twice (executor's Completed-replay path).
 * 3. BOUNDED. Each executor job carries at most lottery.payouts.batch_size
 *    ids, so a backlog fans into many small executor jobs instead of one
 *    monolith holding the queue for minutes.
 * 4. OBSERVED. The run's summary (selected, dispatched, chunk plan) is one
 *    audit line; per-row money movement is the executor's own audit trail.
 */
class GeneratePayoutBatchJob implements ShouldBeUnique, ShouldQueue
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
     * Execution timeout in seconds. Selection + dispatch at two minutes is
     * ample: no wallet or ledger row moves in this job.
     */
    public int $timeout = 120;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    /**
     * @param  int|null  $chunkSize  Ids per dispatched executor batch. When
     *                               null the configured lottery.payouts.batch_size
     *                               is used; a smaller positive integer is for
     *                               draining a backlog in finer slices.
     */
    public function __construct(
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for the concurrency lock: one generator run per
     * minute at most.
     */
    public function uniqueId(): string
    {
        return 'generate_payout_batch_'.now()->format('Y-m-d-H-i');
    }

    /**
     * Select approved-and-pending payouts, chunk them, dispatch one executor
     * per chunk, record one summary audit line.
     *
     * @return array{selected: int, dispatched: int, chunk_size: int, payout_ids: list<int>, executed_at: string}
     */
    public function handle(): array
    {
        Log::info('GeneratePayoutBatchJob: selecting approved pending payouts', [
            'attempt' => $this->attempts(),
        ]);

        $chunkSize = $this->resolvedChunkSize();

        $payouts = $this->selectApprovedPending($chunkSize * 10);

        $ids = $payouts->map(fn (Payout $payout): int => (int) $payout->getKey())->all();

        $dispatched = 0;

        foreach (array_chunk($ids, $chunkSize) as $chunkIndex => $idChunk) {
            // ProcessPayoutBatchJob's tag is an integer (it keys its own
            // uniqueness lock). The tag names the run minute plus the chunk
            // ordinal, so every chunk of this run is distinctly unique.
            $batchTag = (int) sprintf('%s%02d', now()->format('YmdHi'), $chunkIndex + 1);

            ProcessPayoutBatchJob::dispatch(
                batchTag: $batchTag,
                payoutIds: $idChunk,
            );

            $dispatched++;
        }

        $summary = [
            'selected' => $payouts->count(),
            'dispatched' => $dispatched,
            'chunk_size' => $chunkSize,
            'payout_ids' => $ids,
            'executed_at' => now()->toIso8601String(),
        ];

        Log::info('GeneratePayoutBatchJob: fan-out complete', [
            'selected' => $summary['selected'],
            'dispatched' => $summary['dispatched'],
            'chunk_size' => $summary['chunk_size'],
        ]);

        if ($summary['selected'] > 0) {
            $this->recordSummary($summary);
        }

        return $summary;
    }

    /**
     * Outstanding dispatch failures collapse to a log: the next scheduled run
     * re-selects the same lane anyway, and per-payout state stayed untouched.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('GeneratePayoutBatchJob: run failed before completing fan-out', [
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * The selection predicate, in one place: payout row Pending AND the
     * approval-lane metadata stamp say Approved.
     *
     * json_extract is the sqlite-compatible raw where the other metadata
     * services use — no driver-specific JSON syntax.
     *
     * @return Collection<int, Payout>
     */
    private function selectApprovedPending(int $limit): Collection
    {
        return Payout::query()
            ->whereNull('deleted_at')
            ->where('status', PayoutStatus::Pending->value)
            ->whereRaw("json_extract(metadata, '$.approval.status') = ?", [PayoutApprovalStatus::Approved->value])
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Chunk sizing: explicit override when given, configuration otherwise;
     * never zero (that would call array_chunk on an empty size).
     */
    private function resolvedChunkSize(): int
    {
        if (is_int($this->chunkSize) && $this->chunkSize > 0) {
            return $this->chunkSize;
        }

        $configured = (int) config('lottery.payouts.batch_size', 200);

        return $configured > 0 ? $configured : 200;
    }

    /**
     * The run's summary audit line: null user (scheduler), action Update,
     * chunked plan in metadata.
     *
     * @param  array{selected: int, dispatched: int, chunk_size: int}  $summary
     */
    private function recordSummary(array $summary): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => 'payout_batch_generation',
            'auditable_id' => 0,
            'description' => sprintf(
                'Payout batch generation selected %d approved pending payout(s) and dispatched %d executor batch(es) of at most %d.',
                $summary['selected'],
                $summary['dispatched'],
                $summary['chunk_size'],
            ),
            'metadata' => [
                'selected' => $summary['selected'],
                'dispatched' => $summary['dispatched'],
                'chunk_size' => $summary['chunk_size'],
                'lane_gate' => 'approval.status = '.PayoutApprovalStatus::Approved->value,
                'row_gate' => 'status = '.PayoutStatus::Pending->value,
                'action' => 'payout_batch_generated',
            ],
        ]);

        $log->save();
    }
}
