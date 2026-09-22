<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of one draw's reconciliation conversation.
 *
 *   Pending ──▶ Matched
 *      │
 *      └──▶ DriftDetected ──▶ Resolved
 *
 * SEMANTICS
 * - Pending: gathered, counting — or waiting on a lane that didn't answer.
 * - Matched: the draw's official result, its winning tickets, its prize
 *   settlements and its payout records all agree to the argument. CASE
 *   CLOSED, terminal.
 * - DriftDetected: at least one lane disagreed; the drift lines are on
 *   the row, pronounced, never silently healed.
 * - Resolved: an operator has reviewed the drift and closed it — ALWAYS
 *   a fresh human in the middle (auto-resolution would defeat the point
 *   of a reconciliation lane).
 *
 * TERMINAL: Matched, Resolved. DriftDetected rows wait for a judge.
 */
enum DrawReconciliationStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case DriftDetected = 'drift_detected';
    case Resolved = 'resolved';

    /**
     * @return array<int, DrawReconciliationStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Matched, self::DriftDetected],
            self::Matched => [],
            self::DriftDetected => [self::Resolved],
            self::Resolved => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Matched || $this === self::Resolved;
    }

    /**
     * Is this row still waiting for the judge?
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::DriftDetected;
    }
}
