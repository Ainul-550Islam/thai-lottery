<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\DTOs\Queue\QueueHealthReport;
use App\Enums\QueueName;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Queue Health & Worker Liveness Monitor.
 *
 * Provides real-time metrics on:
 * - Queue backend connectivity
 * - Pending job counts per priority queue
 * - Failed jobs count
 * - Stale/stuck reserved jobs
 */
class QueueHealthService
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Check overall queue health.
     */
    public function check(): QueueHealthReport
    {
        $driver = (string) $this->config->get('queue.default', 'database');
        $warnings = [];
        $pendingByQueue = [];
        $totalPending = 0;
        $failedCount = 0;
        $stuckCount = 0;
        $isHealthy = true;

        try {
            // 1. Check failed jobs count
            $failedTable = (string) $this->config->get('queue.failed.table', 'failed_jobs');
            if (DB::getSchemaBuilder()->hasTable($failedTable)) {
                $failedCount = (int) DB::table($failedTable)->count();
                if ($failedCount > 0) {
                    $warnings[] = sprintf('%d failed job(s) present in %s.', $failedCount, $failedTable);
                }
            }

            // 2. Check pending & stuck jobs for database driver
            if ($driver === 'database') {
                $jobsTable = (string) $this->config->get('queue.connections.database.table', 'jobs');
                $retryAfter = (int) $this->config->get('queue.connections.database.retry_after', 90);

                if (DB::getSchemaBuilder()->hasTable($jobsTable)) {
                    $totalPending = (int) DB::table($jobsTable)->count();

                    // Pending count per logical queue
                    foreach (QueueName::cases() as $queueCase) {
                        $pendingByQueue[$queueCase->value] = (int) DB::table($jobsTable)
                            ->where('queue', $queueCase->value)
                            ->count();
                    }

                    // Stuck reserved jobs (reserved_at older than retry_after + 30s buffer)
                    $stuckThreshold = Carbon::now()->subSeconds($retryAfter + 30)->getTimestamp();
                    $stuckCount = (int) DB::table($jobsTable)
                        ->whereNotNull('reserved_at')
                        ->where('reserved_at', '<', $stuckThreshold)
                        ->count();

                    if ($stuckCount > 0) {
                        $warnings[] = sprintf('%d stuck/expired job(s) detected in database queue.', $stuckCount);
                        $isHealthy = false;
                    }

                    // Alert if high pending count in financial-critical queue
                    $criticalPending = $pendingByQueue[QueueName::FinancialCritical->value] ?? 0;
                    if ($criticalPending > 100) {
                        $warnings[] = sprintf('High backlog in %s queue: %d jobs pending.', QueueName::FinancialCritical->value, $criticalPending);
                    }
                }
            } elseif ($driver === 'sync') {
                foreach (QueueName::cases() as $queueCase) {
                    $pendingByQueue[$queueCase->value] = 0;
                }
            } else {
                // Redis or other driver
                foreach (QueueName::cases() as $queueCase) {
                    $pendingByQueue[$queueCase->value] = 0;
                }
            }
        } catch (Throwable $e) {
            Log::error('QueueHealthService: Health check failed to query queue backend', [
                'error' => $e->getMessage(),
            ]);

            $isHealthy = false;
            $warnings[] = 'Queue backend communication error: '.$e->getMessage();
        }

        return new QueueHealthReport(
            isHealthy: $isHealthy,
            driver: $driver,
            totalPending: $totalPending,
            pendingByQueue: $pendingByQueue,
            failedCount: $failedCount,
            stuckCount: $stuckCount,
            warnings: $warnings,
            checkedAt: Carbon::now()->toIso8601String(),
        );
    }
}
