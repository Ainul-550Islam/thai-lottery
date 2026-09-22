<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ResponsibleGamingLimitStatus — immutable limit-VERSION lifecycle.
 *
 * A limit is never edited: it is born Pending (an increase waiting
 * out its cool-off) or Active (a stricter limit taking effect at
 * once), and it seals as Replaced (superseded by a newer version,
 * supersede-not-edit), Expired (its horizon physically passed), or
 * Cancelled (only for a Pending version that never became law).
 */
enum ResponsibleGamingLimitStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Replaced = 'replaced';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Cancelled],
            self::Active => [self::Replaced, self::Expired],
            self::Replaced, self::Expired, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * The versions that currently constrain the player. A Pending
     * increase does not bind yet — its Active ancestor stands until
     * the cool-off is served.
     */
    public function bindsPlayer(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
