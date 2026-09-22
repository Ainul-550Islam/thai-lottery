<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * DeviceTrustStatus — device trust lifecycle.
 *
 * Unknown marks absence of a row; Pending awaits verification
 * evidence; Trusted stands until the desk revokes it. Revocation is
 * a deliberate, auditable act — trust never decays on its own
 * (expiry, if any, revokes with a reason).
 */
enum DeviceTrustStatus: string
{
    case Unknown = 'unknown';
    case Pending = 'pending';
    case Trusted = 'trusted';
    case Revoked = 'revoked';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Trusted, self::Revoked],
            self::Trusted => [self::Revoked],
            self::Revoked => [self::Pending],   // re-registration begins a new Pending era
            self::Unknown => [self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function grantsTrust(): bool
    {
        return $this === self::Trusted;
    }
}
