<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ProviderOperationStatus — operational state seats an operator
 * records for an integration provider. Health telemetry informs the
 * seat but never silences it: this is the desk's own pronouncement,
 * not observed provider state.
 */
enum ProviderOperationStatus: string
{
    case Available = 'available';
    case Degraded = 'degraded';
    case Suspended = 'suspended';
    case Disabled = 'disabled';

    /**
     * Terminal per se = none: a provider may be revived from any
     * seat with fresh evidence — but only one seat stands at a time.
     */
    public function isTerminal(): bool
    {
        return false;
    }

    public function mayServeTraffic(): bool
    {
        return match ($this) {
            self::Available => true,
            self::Degraded => true,  // traffic may pass with caution
            default => false,
        };
    }

    /**
     * Ordered severity floor for comparison (never arithmetic on raw).
     */
    public function severity(): int
    {
        return match ($this) {
            self::Available => 0,
            self::Degraded => 1,
            self::Suspended => 2,
            self::Disabled => 3,
        };
    }
}
