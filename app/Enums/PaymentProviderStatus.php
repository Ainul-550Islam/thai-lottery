<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a payment gateway provider as the HOUSE sees it.
 *
 *   Pending    — registered, never yet trusted with traffic
 *   Active     — carrying traffic; methods resolve against it
 *   Suspended  — clerk's own red light; reversibly out of traffic
 *   Disabled   — struck from the registry; nothing resolves against it
 *
 * A provider may return from Pending (provisioning finishes) or from
 * Suspended (review lifts), but never from Disabled — disabling is
 * the nomenclature of "struck off the books".
 */
enum PaymentProviderStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Disabled => 'Disabled',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Active, self::Disabled], true),
            self::Active => in_array($next, [self::Suspended, self::Disabled], true),
            self::Suspended => in_array($next, [self::Active, self::Disabled], true),
            self::Disabled => false,
        };
    }

    /**
     * Whether the registry may RESOLVE this provider for traffic.
     * Only Active providers answer the bell.
     */
    public function canServeTraffic(): bool
    {
        return $this === self::Active;
    }
}
