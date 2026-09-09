<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Models\AuditLog;
use App\Services\Queue\QueueHealthService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Multi-Tier System Health, Liveness, and Readiness Assessment Service.
 *
 * Evaluates operational readiness, connectivity to core dependencies,
 * queue backlogs, failed jobs, and financial reconciliation freshness.
 */
class SystemHealthService
{
    public function __construct(
        private readonly QueueHealthService $queueHealth,
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Liveness check: confirms application process is running.
     *
     * @return array{status: string, timestamp: string}
     */
    public function checkLiveness(): array
    {
        return [
            'status' => 'UP',
            'timestamp' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Readiness check: validates connectivity to critical dependencies.
     *
     * @return array{ready: bool, database: bool, cache: bool, queue: bool, timestamp: string}
     */
    public function checkReadiness(): array
    {
        $dbOk = $this->pingDatabase();
        $cacheOk = $this->pingCache();
        $queueOk = $this->pingQueue();

        $allReady = $dbOk && $cacheOk && $queueOk;

        return [
            'ready' => $allReady,
            'database' => $dbOk,
            'cache' => $cacheOk,
            'queue' => $queueOk,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Comprehensive health assessment with degraded state detection.
     *
     * @return array<string, mixed>
     */
    public function checkDetailedHealth(): array
    {
        $startTime = microtime(true);
        $warnings = [];
        $isDegraded = false;
        $isUnhealthy = false;

        // 1. Database Check
        $dbStart = microtime(true);
        $dbOk = $this->pingDatabase();
        $dbLatency = round((microtime(true) - $dbStart) * 1000, 2);

        if (! $dbOk) {
            $isUnhealthy = true;
            $warnings[] = 'Database is unreachable.';
        } elseif ($dbLatency > 500) {
            $isDegraded = true;
            $warnings[] = sprintf('High database query latency: %0.2f ms.', $dbLatency);
        }

        // 2. Cache Check
        $cacheStart = microtime(true);
        $cacheOk = $this->pingCache();
        $cacheLatency = round((microtime(true) - $cacheStart) * 1000, 2);

        if (! $cacheOk) {
            $isDegraded = true;
            $warnings[] = 'Cache storage is unreachable.';
        }

        // 3. Queue Check
        $queueReport = $this->queueHealth->check();
        if (! $queueReport->isHealthy) {
            $isDegraded = true;
            foreach ($queueReport->warnings as $w) {
                $warnings[] = $w;
            }
        }

        if ($queueReport->failedCount > 10) {
            $isDegraded = true;
            $warnings[] = sprintf('Excessive failed jobs backlog: %d failed jobs in queue.', $queueReport->failedCount);
        }

        // 4. Financial Reconciliation Freshness
        $reconciliationInfo = $this->checkReconciliationFreshness();
        if ($reconciliationInfo['is_stale']) {
            $isDegraded = true;
            $warnings[] = sprintf('Financial reconciliation is stale (last run: %s, %s hours ago).', $reconciliationInfo['last_run_at'] ?? 'NEVER', $reconciliationInfo['staleness_hours'] ?? 'N/A');
        }
        if ($reconciliationInfo['last_status'] === 'critical') {
            $isDegraded = true;
            $warnings[] = 'Last financial reconciliation reported CRITICAL anomalies.';
        }

        $overallStatus = $isUnhealthy ? 'UNHEALTHY' : ($isDegraded ? 'DEGRADED' : 'HEALTHY');

        return [
            'status' => $overallStatus,
            'timestamp' => Carbon::now()->toIso8601String(),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'dependencies' => [
                'database' => [
                    'status' => $dbOk ? 'pass' : 'fail',
                    'latency_ms' => $dbLatency,
                ],
                'cache' => [
                    'status' => $cacheOk ? 'pass' : 'fail',
                    'latency_ms' => $cacheLatency,
                ],
                'queue' => [
                    'status' => $queueReport->isHealthy ? 'pass' : 'warn',
                    'driver' => $queueReport->driver,
                    'total_pending' => $queueReport->totalPending,
                    'failed_count' => $queueReport->failedCount,
                    'stuck_count' => $queueReport->stuckCount,
                ],
                'reconciliation' => [
                    'status' => $reconciliationInfo['is_stale'] ? 'warn' : 'pass',
                    'last_run_at' => $reconciliationInfo['last_run_at'],
                    'staleness_hours' => $reconciliationInfo['staleness_hours'],
                    'last_status' => $reconciliationInfo['last_status'],
                ],
            ],
            'warnings' => $warnings,
        ];
    }

    private function pingDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function pingCache(): bool
    {
        try {
            $testKey = 'health_ping_'.bin2hex(random_bytes(4));
            $this->cache->put($testKey, 'ok', 10);
            $val = $this->cache->get($testKey);
            $this->cache->forget($testKey);

            return $val === 'ok';
        } catch (Throwable) {
            return false;
        }
    }

    private function pingQueue(): bool
    {
        try {
            return $this->queueHealth->check()->isHealthy;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{is_stale: bool, last_run_at: ?string, staleness_hours: ?float, last_status: string}
     */
    private function checkReconciliationFreshness(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('audit_logs')) {
                return ['is_stale' => true, 'last_run_at' => null, 'staleness_hours' => null, 'last_status' => 'unknown'];
            }

            $lastAudit = AuditLog::query()
                ->where('action', 'reconcile')
                ->latest('id')
                ->first();

            if (! $lastAudit instanceof AuditLog || $lastAudit->created_at === null) {
                return ['is_stale' => true, 'last_run_at' => null, 'staleness_hours' => null, 'last_status' => 'never_run'];
            }

            $hours = round(abs(Carbon::now()->diffInMinutes($lastAudit->created_at)) / 60.0, 2);
            $metadata = is_array($lastAudit->metadata) ? $lastAudit->metadata : [];
            $status = (string) ($metadata['status'] ?? 'pass');

            return [
                'is_stale' => $hours > 24.0,
                'last_run_at' => $lastAudit->created_at->toIso8601String(),
                'staleness_hours' => $hours,
                'last_status' => $status,
            ];
        } catch (Throwable) {
            return ['is_stale' => true, 'last_run_at' => null, 'staleness_hours' => null, 'last_status' => 'error'];
        }
    }
}
