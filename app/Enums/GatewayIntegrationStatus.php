<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a payment gateway integration.
 *
 * Distinguishes between:
 * - FullyImplemented: Complete end-to-end driver, request signing/verification, and webhook handling.
 * - PartiallyImplemented: Basic integration present; advanced features (e.g. direct payouts) require external setup.
 * - ConfiguredOnly: Configuration and manual flow present, but driver relies on operator action.
 * - Simulated: Mocked/sandbox driver for testing environments only.
 * - Unsupported: Method recognized by schema but not supported as an automated payment gateway.
 */
enum GatewayIntegrationStatus: string
{
    case FullyImplemented = 'fully_implemented';
    case PartiallyImplemented = 'partially_implemented';
    case ConfiguredOnly = 'configured_only';
    case Simulated = 'simulated';
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::FullyImplemented => 'Fully Implemented',
            self::PartiallyImplemented => 'Partially Implemented',
            self::ConfiguredOnly => 'Configured Only (Manual Flow)',
            self::Simulated => 'Simulated',
            self::Unsupported => 'Unsupported',
        };
    }

    public function isAutomated(): bool
    {
        return in_array($this, [self::FullyImplemented, self::PartiallyImplemented], true);
    }
}
