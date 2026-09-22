<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Compliance investigation lifecycle.
 *
 *   Open          — intake pronounced; nothing read yet
 *   Investigating — a desk owns the file and is reading evidence
 *   Escalated     — rising severity; above the desk's own authority
 *   Resolved      — fact-finding complete (with the resolution note)
 *   Closed        — sealed file; only a Resolved case may Close
 *
 * Closed is one-way. Escalation never cools by vocabulary: from
 * Escalated the file must be Resolved with evidence first.
 */
enum ComplianceCaseStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Investigating => 'Investigating',
            self::Escalated => 'Escalated',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Open => in_array($next, [self::Investigating, self::Escalated, self::Resolved], true),
            self::Investigating => in_array($next, [self::Escalated, self::Resolved], true),
            self::Escalated => $next === self::Resolved,
            self::Resolved => $next === self::Closed,
            self::Closed => false,
        };
    }

    /**
     * Cases still in flight (never Closed/Resolved-and-shut).
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Open, self::Investigating, self::Escalated], true);
    }

    public function blocksClosure(): bool
    {
        return $this->isLive();
    }
}
