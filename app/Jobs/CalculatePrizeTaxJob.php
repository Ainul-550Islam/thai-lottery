<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Finance\TaxCalculationData;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Enums\TaxCalculationStatus;
use App\Exceptions\TaxCalculationException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Services\Finance\TaxCalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Computes prize tax for every eligible approved prize that has none yet.
 *
 * WHO IS ELIGIBLE
 * ---------------
 * A payout is eligible exactly when BOTH are true:
 *   • its payout row is Pending (money not yet moved; folding the amount is
 *     still meaningful), AND
 *   • its approval lane is stamped Approved (four-eyes already released the
 *     obligation — tax arithmetic never runs on money nobody approved),
 *   AND it carries NO tax lane record yet, or a lane still at Pending.
 *
 * DETERMINISTIC AND IDEMPOTENT
 * ----------------------------
 * For each candidate the job composes a TaxCalculationData whose basis is
 * (reference, amount, amount, currency) — {amount × 2} deliberately: until
 * a jurisdiction's exempt-portion rule lands, the taxable base IS the
 * prize. The basis key derives; a rerun over the same row re-serves the
 * stamped answer, so the job is safe under any number of executions.
 *
 * COMPUTE ONLY, APPLY SEPARATELY
 * ------------------------------
 * This job runs TaxCalculationService::compute — the arithmetic and the
 * stamping. Folding the answer into payout.amount (apply()) is an opted
 * operations gesture (config lottery.tax.auto_apply), so raw arithmetic
 * may be reviewed in the Calculated state before money faces are folded.
 *
 * RETRY BEHAVIOR
 * --------------
 * Per-row failures (missing rate configuration, drift, malformed basis)
 * are COLLECTED, not thrown: one bad row never stalls the computation of
 * others, and every failure carries its calculation identity for the log.
 * A run that collected failures returns them in the summary.
 */
class CalculatePrizeTaxJob implements ShouldBeUnique, ShouldQueue
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
     * @param  int|null  $chunkSize  Candidate rows per run (bounded for a
     *                               backlog catch-up, null = all eligible).
     */
    public function __construct(
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::Reconciliation->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for the concurrency lock: one computation run per
     * hour at most.
     */
    public function uniqueId(): string
    {
        return 'calculate_prize_tax_'.now()->format('Y-m-d-H');
    }

    /**
     * Run the computation pass.
     *
     * @return array{eligible: int, computed: int, waived: int, failed: int, failures: list<array<string, mixed>>, executed_at: string}
     */
    public function handle(TaxCalculationService $service): array
    {
        Log::info('CalculatePrizeTaxJob: starting computation pass', [
            'attempt' => $this->attempts(),
        ]);

        $eligible = 0;
        $computed = 0;
        $waived = 0;
        $failed = 0;
        $failures = [];

        $query = Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->where('status', PayoutStatus::Pending->value)
            ->whereRaw("json_extract(metadata, '$.approval.status') = ?", [PayoutApprovalStatus::Approved->value])
            ->where(function ($q): void {
                $q->whereRaw("json_extract(metadata, '$.tax.status') IS NULL")
                    ->orWhereRaw("json_extract(metadata, '$.tax.status') = ?", [TaxCalculationStatus::Pending->value]);
            })
            ->orderBy('id');

        if (is_int($this->chunkSize) && $this->chunkSize > 0) {
            $query->limit($this->chunkSize);
        }

        foreach ($query->get() as $payout) {
            $eligible++;

            $data = new TaxCalculationData(
                payoutReference: (string) $payout->reference_number,
                prizeAmount: (string) $payout->amount,
                taxableAmount: (string) $payout->amount,
                currency: $payout->currency,
                context: [
                    'source' => 'calculate-prize-tax-job',
                    'payout_id' => (int) $payout->getKey(),
                ],
            );

            try {
                $record = $service->compute($payout, $data);

                match ($record['status'] ?? null) {
                    TaxCalculationStatus::Waived->value => $waived++,
                    default => $computed++,
                };
            } catch (TaxCalculationException $e) {
                $failed++;
                $failures[] = [
                    'payout_id' => (int) $payout->getKey(),
                    'payout_reference' => (string) $payout->reference_number,
                    'code' => $e->errorCode(),
                ];

                Log::warning('CalculatePrizeTaxJob: row refused by calculator', [
                    'payout_id' => (int) $payout->getKey(),
                    'code' => $e->errorCode(),
                ]);
            }
        }

        $summary = [
            'eligible' => $eligible,
            'computed' => $computed,
            'waived' => $waived,
            'failed' => $failed,
            'failures' => $failures,
            'executed_at' => now()->toIso8601String(),
        ];

        Log::info('CalculatePrizeTaxJob: pass complete', [
            'eligible' => $eligible,
            'computed' => $computed,
            'waived' => $waived,
            'failed' => $failed,
        ]);

        if ($eligible > 0) {
            $this->recordSummary($summary);
        }

        return $summary;
    }

    /**
     * A thrown pass is loud in the log and cheap in the scheduler: the next
     * run recomputes from a clean selector state.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('CalculatePrizeTaxJob: pass failed', [
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * @param  array{eligible: int, computed: int, waived: int, failed: int}  $summary
     */
    private function recordSummary(array $summary): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => 'prize_tax_sweep',
            'auditable_id' => 0,
            'description' => sprintf(
                'Prize-tax computation pass: %d eligible, %d computed, %d waived, %d failed.',
                $summary['eligible'],
                $summary['computed'],
                $summary['waived'],
                $summary['failed'],
            ),
            'metadata' => [
                'eligible' => $summary['eligible'],
                'computed' => $summary['computed'],
                'waived' => $summary['waived'],
                'failed' => $summary['failed'],
                'action' => 'prize_tax_computed',
            ],
        ]);

        $log->save();
    }
}
