<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ReportJobStatus — lifecycle of a report generation request:
 * Queued → Running → Completed, with Failed and Expired side lanes
 * that never re-open.
 */
enum ReportJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Expired => true,
            default => false,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Queued => $target === self::Running || $target === self::Expired || $target === self::Failed,
            self::Running => $target === self::Completed || $target === self::Failed || $target === self::Expired,
            default => false,
        };
    }
}
