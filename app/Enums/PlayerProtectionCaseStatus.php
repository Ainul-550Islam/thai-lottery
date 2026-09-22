<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * PlayerProtectionCaseStatus — the responsible-gaming case lifecycle.
 *
 * Open → Monitoring → Escalated → Resolved → Closed. The file moves
 * forward by deliberate acts alone; Closed is one-way (de fisco, one
 * continued trajectory). Monitoring is the desk's watch-state: the
 * known-dangerous stage between seeing and pronouncing.
 */
enum PlayerProtectionCaseStatus: string
{
    case Open = 'open';
    case Monitoring = 'monitoring';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Monitoring, self::Escalated],
            self::Monitoring => [self::Escalated, self::Resolved],
            self::Escalated => [self::Monitoring, self::Resolved],
            self::Resolved => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * The live-file statuses on the desk. Actions of any kind touch
     * these alone (Release aside, which the action service governs).
     */
    public function isLive(): bool
    {
        return match ($this) {
            self::Open, self::Monitoring, self::Escalated => true,
            self::Resolved, self::Closed => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }
}
