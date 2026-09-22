<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Exceptions\PayoutDocumentException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Services\Payout\PayoutStatementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates payout statements for completed payouts that have none yet.
 *
 * ELIGIBILITY, IN ONE PREDICATE
 * -----------------------------
 * payout.status = Completed AND NO payout_documents row cites it. A payout
 * could in theory arrive whose document exists under a different key —
 * impossible by derivation (the key is the reference), so the selector
 * and the service's own document-key join agree bit for bit.
 *
 * DETERMINISM AND REPLAY SAFETY
 * -----------------------------
 * The document key derives from the payout reference; a queue redelivery
 * or scheduler rerun lands on the same document silently (service-side
 * replay by key; the job sees created=false). Running this job N times
 * costs the same as running it once.
 *
 * PER-ROW FAILURE ISOLATION
 * -------------------------
 * A payout whose ledger equation fails (gross ≠ tax + net) stops that
 * ROW with moneyInconsistent, collected in the failures list with the
 * payout's id and the exception code; every other candidate still gets
 * its statement. A run that refuses everything still closes loudly with
 * the counts, never silently.
 */
class GeneratePayoutStatementJob implements ShouldBeUnique, ShouldQueue
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
    public array $backoff = [30, 120];

    /**
     * Execution timeout in seconds.
     */
    public int $timeout = 120;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    /**
     * @param  int|null  $chunkSize  Candidates per run (bounded catch-up).
     */
    public function __construct(
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::Reconciliation->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier: one generation run per hour.
     */
    public function uniqueId(): string
    {
        return 'generate_payout_statement_'.now()->format('Y-m-d-H');
    }

    /**
     * Run the generation pass.
     *
     * @return array{eligible: int, generated: int, replayed: int, failed: int, failures: list<array<string, mixed>>, executed_at: string}
     */
    public function handle(PayoutStatementService $service): array
    {
        Log::info('GeneratePayoutStatementJob: starting statement generation pass', [
            'attempt' => $this->attempts(),
        ]);

        $eligible = 0;
        $generated = 0;
        $replayed = 0;
        $failed = 0;
        $failures = [];

        $query = Payout::query()
            ->whereNull('deleted_at')
            ->where('status', PayoutStatus::Completed->value)
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('payout_documents')
                    ->whereColumn('payout_documents.payout_id', 'payouts.id')
                    ->whereNull('payout_documents.deleted_at');
            })
            ->orderBy('id');

        if (is_int($this->chunkSize) && $this->chunkSize > 0) {
            $query->limit($this->chunkSize);
        }

        foreach ($query->get() as $payout) {
            $eligible++;

            try {
                $result = $service->generate($payout);

                if ($result['created'] === true) {
                    $generated++;
                } else {
                    $replayed++;
                }
            } catch (PayoutDocumentException $e) {
                $failed++;
                $failures[] = [
                    'payout_id' => (int) $payout->getKey(),
                    'payout_reference' => (string) $payout->reference_number,
                    'code' => $e->errorCode(),
                ];

                Log::warning('GeneratePayoutStatementJob: row refused by statement service', [
                    'payout_id' => (int) $payout->getKey(),
                    'code' => $e->errorCode(),
                ]);
            }
        }

        $summary = [
            'eligible' => $eligible,
            'generated' => $generated,
            'replayed' => $replayed,
            'failed' => $failed,
            'failures' => $failures,
            'executed_at' => now()->toIso8601String(),
        ];

        Log::info('GeneratePayoutStatementJob: pass complete', [
            'eligible' => $eligible,
            'generated' => $generated,
            'replayed' => $replayed,
            'failed' => $failed,
        ]);

        if ($eligible > 0) {
            $this->recordSummary($summary);
        }

        return $summary;
    }

    /**
     * A thrown pass is loud in the log; per-row transactions kept the
     * ledger consistent, and the next run re-selects idempotently.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('GeneratePayoutStatementJob: pass failed', [
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * @param  array{eligible: int, generated: int, replayed: int, failed: int}  $summary
     */
    private function recordSummary(array $summary): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => 'payout_statement_generation',
            'auditable_id' => 0,
            'description' => sprintf(
                'Payout statement generation pass: %d eligible, %d generated, %d replayed, %d failed.',
                $summary['eligible'],
                $summary['generated'],
                $summary['replayed'],
                $summary['failed'],
            ),
            'metadata' => [
                'eligible' => $summary['eligible'],
                'generated' => $summary['generated'],
                'replayed' => $summary['replayed'],
                'failed' => $summary['failed'],
                'action' => 'payout_statements_generated',
            ],
        ]);

        $log->save();
    }
}
