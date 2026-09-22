<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * RealityCheckStatus — the player reality-check lifecycle.
 *
 * Scheduled → Due → Delivered → Acknowledged → Expired. A check is
 * owed the moment session evidence crosses its threshold; delivery
 * is replay-safe by fingerprint; acknowledgement is the player
 * pronouncing they saw it; expiry is physics for a check never
 * delivered or never acknowledged past its horizon.
 */
enum RealityCheckStatus: string
{
    case Scheduled = 'scheduled';
    case Due = 'due';
    case Delivered = 'delivered';
    case Acknowledged = 'acknowledged';
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Due, self::Expired],
            self::Due => [self::Delivered, self::Expired],
            self::Delivered => [self::Acknowledged, self::Expired],
            self::Acknowledged, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the player still owes this check an acknowledgement.
     */
    public function requiresAcknowledgement(): bool
    {
        return $this === self::Due || $this === self::Delivered;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
