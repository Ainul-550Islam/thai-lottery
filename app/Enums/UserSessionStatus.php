<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * UserSessionStatus — session states.
 *
 * Active → Revoked | Expired | Rotated; Suspicious marks an Active
 * session under review (hard-gated from new acts until revoked).
 * Expiry is server-clock physics; Revoked is a deliberate act.
 */
enum UserSessionStatus: string
{
    case Active = 'active';
    case Suspicious = 'suspicious';
    case Revoked = 'revoked';
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Revoked, self::Expired, self::Suspicious],
            self::Suspicious => [self::Revoked, self::Expired, self::Active],
            self::Revoked, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the session may currently serve fresh requests under
     * the fail-closed gate (Suspicious does NOT pass).
     */
    public function servesRequests(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
