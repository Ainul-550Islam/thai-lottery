<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Enums\DepositStatus;
use App\Enums\DrawStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Enums\WithdrawalStatus;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Withdrawal;
use App\Services\Queue\QueueHealthService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Production Financial & Operational Metrics Collector.
 *
 * Collects, aggregates, and exports production telemetry across queues, payments,
 * withdrawals, prize settlements, reconciliation runs, and error rates.
 * Supports standard JSON and OpenMetrics/Prometheus formats.
 */
class FinancialMetricsCollector
{
    public function __construct(
        private readonly QueueHealthService $queueHealth,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Collect all real-time operational and financial metrics.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $collectedAt = Carbon::now();

        return [
            'timestamp' => $collectedAt->toIso8601String(),
            'app_env' => (string) $this->config->get('app.env', 'production'),
            'queue' => $this->collectQueueMetrics(),
            'payments' => $this->collectPaymentMetrics(),
            'withdrawals' => $this->collectWithdrawalMetrics(),
            'settlement' => $this->collectSettlementMetrics(),
            'reconciliation' => $this->collectReconciliationMetrics(),
        ];
    }

    /**
     * Format collected metrics in Prometheus / OpenMetrics line text format.
     */
    public function toPrometheusFormat(): string
    {
        $metrics = $this->collect();
        $lines = [];

        $lines[] = '# HELP thai_lottery_up Application liveness gauge (1 = healthy)';
        $lines[] = '# TYPE thai_lottery_up gauge';
        $lines[] = 'thai_lottery_up 1';

        // Queue metrics
        $lines[] = '# HELP thai_lottery_queue_pending_jobs Total pending jobs in queue';
        $lines[] = '# TYPE thai_lottery_queue_pending_jobs gauge';
        $lines[] = sprintf('thai_lottery_queue_pending_jobs %d', $metrics['queue']['total_pending']);

        $lines[] = '# HELP thai_lottery_queue_pending_by_priority Pending jobs per priority queue';
        $lines[] = '# TYPE thai_lottery_queue_pending_by_priority gauge';
        foreach ($metrics['queue']['pending_by_queue'] as $q => $count) {
            $lines[] = sprintf('thai_lottery_queue_pending_by_priority{queue="%s"} %d', $q, $count);
        }

        $lines[] = '# HELP thai_lottery_queue_failed_jobs Total failed jobs count';
        $lines[] = '# TYPE thai_lottery_queue_failed_jobs gauge';
        $lines[] = sprintf('thai_lottery_queue_failed_jobs %d', $metrics['queue']['failed_count']);

        $lines[] = '# HELP thai_lottery_queue_stuck_jobs Total stuck reserved jobs';
        $lines[] = '# TYPE thai_lottery_queue_stuck_jobs gauge';
        $lines[] = sprintf('thai_lottery_queue_stuck_jobs %d', $metrics['queue']['stuck_count']);

        // Payment metrics
        $lines[] = '# HELP thai_lottery_deposits_total Total deposits count by status';
        $lines[] = '# TYPE thai_lottery_deposits_total gauge';
        foreach ($metrics['payments']['deposits_by_status'] as $status => $count) {
            $lines[] = sprintf('thai_lottery_deposits_total{status="%s"} %d', $status, $count);
        }

        $lines[] = '# HELP thai_lottery_deposits_confirmed_amount_sum Sum of confirmed deposits';
        $lines[] = '# TYPE thai_lottery_deposits_confirmed_amount_sum counter';
        $lines[] = sprintf('thai_lottery_deposits_confirmed_amount_sum %s', $metrics['payments']['confirmed_amount_sum']);

        // Withdrawal metrics
        $lines[] = '# HELP thai_lottery_withdrawals_total Total withdrawals count by status';
        $lines[] = '# TYPE thai_lottery_withdrawals_total gauge';
        foreach ($metrics['withdrawals']['by_status'] as $status => $count) {
            $lines[] = sprintf('thai_lottery_withdrawals_total{status="%s"} %d', $status, $count);
        }

        $lines[] = '# HELP thai_lottery_withdrawals_completed_amount_sum Sum of completed withdrawals';
        $lines[] = '# TYPE thai_lottery_withdrawals_completed_amount_sum counter';
        $lines[] = sprintf('thai_lottery_withdrawals_completed_amount_sum %s', $metrics['withdrawals']['completed_amount_sum']);

        // Settlement metrics
        $lines[] = '# HELP thai_lottery_payouts_total Total prize payouts created';
        $lines[] = '# TYPE thai_lottery_payouts_total gauge';
        $lines[] = sprintf('thai_lottery_payouts_total %d', $metrics['settlement']['total_payouts_count']);

        $lines[] = '# HELP thai_lottery_payouts_amount_sum Total prize payouts amount disbursed';
        $lines[] = '# TYPE thai_lottery_payouts_amount_sum counter';
        $lines[] = sprintf('thai_lottery_payouts_amount_sum %s', $metrics['settlement']['total_payouts_amount']);

        // Reconciliation metrics
        $lines[] = '# HELP thai_lottery_reconciliation_staleness_hours Hours elapsed since last reconciliation run';
        $lines[] = '# TYPE thai_lottery_reconciliation_staleness_hours gauge';
        $lines[] = sprintf('thai_lottery_reconciliation_staleness_hours %s', $metrics['reconciliation']['staleness_hours'] ?? '0');

        $lines[] = '# HELP thai_lottery_reconciliation_last_anomalies Number of anomalies in last reconciliation run';
        $lines[] = '# TYPE thai_lottery_reconciliation_last_anomalies gauge';
        $lines[] = sprintf('thai_lottery_reconciliation_last_anomalies %d', $metrics['reconciliation']['last_anomalies_count']);

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function collectQueueMetrics(): array
    {
        $health = $this->queueHealth->check();

        $oldestJobAge = 0;
        try {
            if ($health->driver === 'database' && DB::getSchemaBuilder()->hasTable('jobs')) {
                $oldestTimestamp = DB::table('jobs')->min('created_at');
                if ($oldestTimestamp !== null) {
                    $oldestJobAge = max(0, Carbon::now()->getTimestamp() - (int) $oldestTimestamp);
                }
            }
        } catch (Throwable) {
            // Ignore if DB is busy/unreachable
        }

        return [
            'is_healthy' => $health->isHealthy,
            'driver' => $health->driver,
            'total_pending' => $health->totalPending,
            'pending_by_queue' => $health->pendingByQueue,
            'failed_count' => $health->failedCount,
            'stuck_count' => $health->stuckCount,
            'oldest_job_age_seconds' => $oldestJobAge,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectPaymentMetrics(): array
    {
        $depositsByStatus = [];
        $confirmedSum = '0.00';

        try {
            if (DB::getSchemaBuilder()->hasTable('deposits')) {
                foreach (DepositStatus::cases() as $case) {
                    $depositsByStatus[$case->value] = Deposit::query()->where('status', $case->value)->count();
                }
                $confirmedSum = (string) (Deposit::query()->where('status', DepositStatus::Confirmed->value)->sum('net_amount') ?? '0.00');
            }
        } catch (Throwable) {
            // Fallback
        }

        return [
            'deposits_by_status' => $depositsByStatus,
            'confirmed_amount_sum' => $confirmedSum,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectWithdrawalMetrics(): array
    {
        $byStatus = [];
        $completedSum = '0.00';

        try {
            if (DB::getSchemaBuilder()->hasTable('withdrawals')) {
                foreach (WithdrawalStatus::cases() as $case) {
                    $byStatus[$case->value] = Withdrawal::query()->where('status', $case->value)->count();
                }
                $completedSum = (string) (Withdrawal::query()->where('status', WithdrawalStatus::Completed->value)->sum('net_amount') ?? '0.00');
            }
        } catch (Throwable) {
            // Fallback
        }

        return [
            'by_status' => $byStatus,
            'completed_amount_sum' => $completedSum,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectSettlementMetrics(): array
    {
        $payoutsCount = 0;
        $payoutsAmount = '0.00';
        $drawsByStatus = [];

        try {
            if (DB::getSchemaBuilder()->hasTable('draws')) {
                foreach (DrawStatus::cases() as $case) {
                    $drawsByStatus[$case->value] = Draw::query()->where('status', $case->value)->count();
                }
            }

            if (DB::getSchemaBuilder()->hasTable('payouts')) {
                $payoutsCount = Payout::query()->where('status', PayoutStatus::Completed->value)->count();
                $payoutsAmount = (string) (Payout::query()->where('status', PayoutStatus::Completed->value)->sum('amount') ?? '0.00');
            }
        } catch (Throwable) {
            // Fallback
        }

        return [
            'draws_by_status' => $drawsByStatus,
            'total_payouts_count' => $payoutsCount,
            'total_payouts_amount' => $payoutsAmount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectReconciliationMetrics(): array
    {
        $lastRunAt = null;
        $lastStatus = 'unknown';
        $lastAnomalies = 0;
        $stalenessHours = null;

        try {
            if (DB::getSchemaBuilder()->hasTable('audit_logs')) {
                $lastAudit = AuditLog::query()
                    ->where('action', 'reconcile')
                    ->latest('id')
                    ->first();

                if ($lastAudit instanceof AuditLog && $lastAudit->created_at !== null) {
                    $lastRunAt = $lastAudit->created_at->toIso8601String();
                    $stalenessHours = round(abs(Carbon::now()->diffInMinutes($lastAudit->created_at)) / 60.0, 2);
                    $metadata = is_array($lastAudit->metadata) ? $lastAudit->metadata : [];
                    $lastStatus = (string) ($metadata['status'] ?? 'pass');
                    $lastAnomalies = (int) ($metadata['anomalies_count'] ?? 0);
                }
            }
        } catch (Throwable) {
            // Fallback
        }

        return [
            'last_run_at' => $lastRunAt,
            'last_status' => $lastStatus,
            'last_anomalies_count' => $lastAnomalies,
            'staleness_hours' => $stalenessHours,
        ];
    }
}
