<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Enums\RiskLevel;
use App\Jobs\Notification\SendFinancialAlertJob;
use App\Services\Queue\QueueHealthService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Production Operational & Financial Anomaly Alerting Service.
 *
 * Evaluates operational anomalies, queue backlogs, failed jobs, and reconciliation
 * freshness. Employs atomic cache deduplication and cooldown throttling to prevent
 * alert floods.
 */
class OperationalAlertService
{
    private const DEFAULT_THROTTLE_SECONDS = 900; // 15 minutes cooldown

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        private readonly QueueHealthService $queueHealth,
        private readonly SystemHealthService $systemHealth,
    ) {
    }

    /**
     * Trigger an asynchronous financial anomaly alert with automatic deduplication.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function alertFinancialAnomaly(
        string $title,
        string $message,
        RiskLevel $severity = RiskLevel::High,
        array $metadata = [],
        int $throttleSeconds = self::DEFAULT_THROTTLE_SECONDS,
    ): bool {
        $fingerprint = 'alert:anomaly:'.md5($title.'_'.($metadata['entity_id'] ?? '').'_'.$severity->value);

        if (! $this->shouldSendAlert($fingerprint, $throttleSeconds)) {
            Log::info('OperationalAlertService: Alert throttled (duplicate within cooldown)', [
                'title' => $title,
                'fingerprint' => $fingerprint,
            ]);

            return false;
        }

        SendFinancialAlertJob::dispatch(
            title: $title,
            message: $message,
            severity: $severity,
            metadata: $metadata,
        );

        return true;
    }

    /**
     * Evaluate system health and dispatch alerts for operational degradations.
     *
     * @return list<string> List of alert keys triggered
     */
    public function evaluateAndAlert(): array
    {
        $triggered = [];
        $health = $this->systemHealth->checkDetailedHealth();

        // 1. Queue Backlog Alert
        $queueInfo = $health['dependencies']['queue'] ?? [];
        $criticalPending = (int) ($this->queueHealth->check()->pendingByQueue['financial-critical'] ?? 0);
        if ($criticalPending > 50) {
            $key = 'alert:queue:critical_backlog';
            if ($this->shouldSendAlert($key, self::DEFAULT_THROTTLE_SECONDS)) {
                SendFinancialAlertJob::dispatch(
                    title: 'CRITICAL: High Financial-Critical Queue Backlog',
                    message: sprintf('The financial-critical queue has %d pending jobs awaiting processing.', $criticalPending),
                    severity: RiskLevel::Critical,
                    metadata: ['pending_count' => $criticalPending, 'queue' => 'financial-critical'],
                );
                $triggered[] = $key;
            }
        }

        // 2. Failed Jobs Alert
        $failedCount = (int) ($queueInfo['failed_count'] ?? 0);
        if ($failedCount > 5) {
            $key = 'alert:queue:failed_jobs_spike';
            if ($this->shouldSendAlert($key, self::DEFAULT_THROTTLE_SECONDS)) {
                SendFinancialAlertJob::dispatch(
                    title: 'WARNING: Failed Jobs Backlog Detected',
                    message: sprintf('%d jobs are currently in failed_jobs table requiring operator review.', $failedCount),
                    severity: RiskLevel::High,
                    metadata: ['failed_count' => $failedCount],
                );
                $triggered[] = $key;
            }
        }

        // 3. Stale Reconciliation Alert
        $recInfo = $health['dependencies']['reconciliation'] ?? [];
        if ($recInfo['status'] === 'warn') {
            $key = 'alert:reconciliation:stale';
            if ($this->shouldSendAlert($key, 3600)) { // 1 hour cooldown for staleness
                SendFinancialAlertJob::dispatch(
                    title: 'WARNING: Financial Reconciliation Stale',
                    message: sprintf('Financial reconciliation has not completed in %s hours.', $recInfo['staleness_hours'] ?? '24+'),
                    severity: RiskLevel::High,
                    metadata: $recInfo,
                );
                $triggered[] = $key;
            }
        }

        return $triggered;
    }

    /**
     * Check whether an alert is permitted to send based on atomic cache lock.
     */
    public function shouldSendAlert(string $fingerprint, int $ttlSeconds = self::DEFAULT_THROTTLE_SECONDS): bool
    {
        try {
            return (bool) $this->cache->add($fingerprint, time(), $ttlSeconds);
        } catch (\Throwable $e) {
            Log::warning('OperationalAlertService: Cache error during alert throttling check', [
                'exception' => $e->getMessage(),
                'fingerprint' => $fingerprint,
            ]);

            return true;
        }
    }
}
