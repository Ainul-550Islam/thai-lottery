<?php

declare(strict_types=1);

namespace App\DTOs\Queue;

/**
 * Immutable DTO representing queue health metrics and liveness.
 */
final class QueueHealthReport
{
    /**
     * @param  array<string, int>  $pendingByQueue
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly bool $isHealthy,
        public readonly string $driver,
        public readonly int $totalPending,
        public readonly array $pendingByQueue,
        public readonly int $failedCount,
        public readonly int $stuckCount,
        public readonly array $warnings = [],
        public readonly ?string $checkedAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_healthy' => $this->isHealthy,
            'driver' => $this->driver,
            'total_pending' => $this->totalPending,
            'pending_by_queue' => $this->pendingByQueue,
            'failed_count' => $this->failedCount,
            'stuck_count' => $this->stuckCount,
            'warnings' => $this->warnings,
            'checked_at' => $this->checkedAt ?? date('c'),
        ];
    }
}
