<?php

declare(strict_types=1);

namespace App\Jobs\Finance;

use App\DTOs\Finance\FinancialReconciliationReport;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\QueueName;
use App\Enums\ReconciliationStatus;
use App\Enums\RiskLevel;
use App\Jobs\Notification\SendFinancialAlertJob;
use App\Models\AuditLog;
use App\Services\Finance\FinancialReconciliationService;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Asynchronous Financial Reconciliation Job.
 *
 * GUARANTEES:
 * 1. Safe Read-Only Verification: Evaluates ledger balance, wallet parity, and accounting invariants without mutations.
 * 2. Overlap Prevention: ShouldBeUnique lock per currency scope prevents multiple concurrent runs.
 * 3. Immutable Execution Audit: Automatically logs report metadata to audit_logs without secrets.
 * 4. Automated Anomaly Escalation: Dispatches critical anomaly alerts to the notification queue.
 */
class ProcessFinancialReconciliationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum retry attempts (reconciliation is pure detection).
     */
    public int $tries = 1;

    /**
     * Execution timeout in seconds.
     */
    public int $timeout = 300;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly ?CarbonInterface $from = null,
        public readonly ?CarbonInterface $to = null,
        public readonly ?Currency $currency = null,
        public readonly string $initiatedBy = 'System:QueueWorker',
    ) {
        $this->onQueue(QueueName::Reconciliation->value);
        $this->afterCommit();
    }

    /**
     * Unique lock key to prevent overlapping executions for the same scope.
     */
    public function uniqueId(): string
    {
        return 'financial_reconciliation_'.($this->currency?->value ?? 'all');
    }

    /**
     * Execute financial reconciliation.
     */
    public function handle(FinancialReconciliationService $reconciliationService): FinancialReconciliationReport
    {
        Log::info('ProcessFinancialReconciliationJob: Starting reconciliation audit', [
            'from' => $this->from?->toIso8601String(),
            'to' => $this->to?->toIso8601String(),
            'currency' => $this->currency?->value,
            'initiated_by' => $this->initiatedBy,
        ]);

        $report = $reconciliationService->reconcile(
            from: $this->from,
            to: $this->to,
            currency: $this->currency,
            initiatedBy: $this->initiatedBy,
        );

        Log::info('ProcessFinancialReconciliationJob: Reconciliation finished', [
            'execution_id' => $report->executionId,
            'status' => $report->status->value,
            'total_anomalies' => $report->anomalyCount,
            'critical_anomalies' => $report->criticalAnomalyCount,
            'duration_seconds' => $report->durationSeconds,
        ]);

        // If Critical discrepancies detected, dispatch asynchronous alert
        if ($report->status === ReconciliationStatus::Critical) {
            Log::alert('ProcessFinancialReconciliationJob: CRITICAL financial discrepancies detected', [
                'execution_id' => $report->executionId,
                'critical_anomalies' => $report->criticalAnomalyCount,
                'discrepancies' => array_map(static fn ($d) => $d->toArray(), $report->discrepancies),
            ]);

            SendFinancialAlertJob::dispatch(
                title: 'CRITICAL: Financial Reconciliation Mismatch Detected',
                message: sprintf(
                    'Reconciliation run [%s] detected %d critical discrepancies (total %d anomalies). Immediate operator investigation required.',
                    $report->executionId,
                    $report->criticalAnomalyCount,
                    $report->anomalyCount,
                ),
                severity: RiskLevel::Critical,
                metadata: [
                    'execution_id' => $report->executionId,
                    'status' => $report->status->value,
                    'critical_anomalies' => $report->criticalAnomalyCount,
                ],
            );
        }

        return $report;
    }

    /**
     * Handle permanent job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ProcessFinancialReconciliationJob: Job failed unexpectedly', [
            'currency' => $this->currency?->value,
            'error' => $exception->getMessage(),
        ]);

        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Reconcile,
            'risk_level' => RiskLevel::Critical,
            'auditable_type' => AuditLog::class,
            'auditable_id' => 0,
            'description' => sprintf(
                'ProcessFinancialReconciliationJob failed unexpectedly for %s: %s',
                $this->currency?->value ?? 'ALL',
                $exception->getMessage(),
            ),
            'metadata' => [
                'currency' => $this->currency?->value,
                'error' => $exception->getMessage(),
            ],
        ]);
        $log->save();
    }
}
