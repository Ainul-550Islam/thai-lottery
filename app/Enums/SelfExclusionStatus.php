<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SelfExclusionStatus — the self-exclusion lifecycle.
 *
 * THE LAW OF THE LIFECYCLE: every self-exclusion is born Requested,
 * becomes Active at its server-authoritative effective moment, and
 * seals exactly once — Expired when the end time has physically
 * passed, Cancelled only while the exclusion was never live.
 * The Active state is NON-BYPASSABLE: no transition leaves Active
 * except Expired, and the desk acts are enforcement decisions made
 * elsewhere — the status itself never opens its gate.
 */
enum SelfExclusionStatus: string
{
    case Requested = 'requested';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Active, self::Cancelled],
            self::Active => [self::Expired],
            self::Expired => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * The ACTIVE state is the non-bypassable gate: whether this row
     * currently bars prohibited account activity is derived from the
     * status plus server clock — this flag states the lifecycle half.
     */
    public function gatesProhibitedActivity(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
