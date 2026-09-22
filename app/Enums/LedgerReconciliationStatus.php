<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wallet/ledger reconciliation lifecycle.
 *
 *   Pending ──▶ Matched
 *      │
 *      └──▶ DriftDetected ──▶ Resolved
 *
 * SEMANTICS
 * - Pending: totals gathered; the court hasn't pronounced.
 * - Matched: wallet rows, the liability-side ledger lane and the open
 *   reservation effects all agree (terminal, per lane).
 * - DriftDetected: at least one lane disagreed; pronounced, never
 *   silently healed.
 * - Resolved: a human judged the drift closed (terminal).
 *
 * TERMINAL: Matched, Resolved.
 */
enum LedgerReconciliationStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case DriftDetected = 'drift_detected';
    case Resolved = 'resolved';

    /**
     * @return array<int, LedgerReconciliationStatus>
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

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::DriftDetected;
    }
}
