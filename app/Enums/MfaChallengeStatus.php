<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * MfaChallengeStatus — MFA challenge lifecycle.
 *
 * Pending → Verified | Failed | Expired; Failed ballots five times
 * (per challenge) into Locked — replay-protected by construction:
 * a Verified or terminal challenge never verifies again.
 */
enum MfaChallengeStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Expired = 'expired';
    case Locked = 'locked';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Verified, self::Failed, self::Expired, self::Locked],
            self::Failed => [self::Expired, self::Locked],
            self::Verified, self::Expired, self::Locked => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether this challenge can still accept a verification attempt.
     */
    public function acceptsVerification(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
