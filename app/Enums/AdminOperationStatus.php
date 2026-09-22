<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AdminOperationStatus — the evidence-backed admin-operation
 * lifecycle: Pending (proposed) → Approved → Executing → Completed.
 * Failed and Cancelled are the side lanes one may never reopen.
 */
enum AdminOperationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Terminal physics: once done, it never re-opens.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * The lawful next seats for a diarised transition.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Approved || $target === self::Cancelled,
            self::Approved => $target === self::Executing || $target === self::Cancelled,
            self::Executing => $target === self::Completed || $target === self::Failed,
            default => false,
        };
    }

    public function acceptsExecution(): bool
    {
        return $this === self::Approved;
    }
}
