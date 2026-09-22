<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a customer-facing payment method offered on a provider.
 *
 *   Pending  — configured, not yet offered
 *   Enabled  — offered; intents may bind it
 *   Disabled — pulled from the menu; existing facts still resolve
 *   Retired  — permanently closed; even resolution refuses it
 *
 * Retired is one-way: a method may be retired ONLY from Disabled
 * (you must take it off the shelf before you burn the shelf).
 */
enum PaymentMethodStatus: string
{
    case Pending = 'pending';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Enabled => 'Enabled',
            self::Disabled => 'Disabled',
            self::Retired => 'Retired',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Enabled, self::Retired], true),
            self::Enabled => in_array($next, [self::Disabled, self::Retired], true),
            self::Disabled => in_array($next, [self::Enabled, self::Retired], true),
            self::Retired => false,
        };
    }

    /**
     * Whether new intents may bind this method.
     */
    public function isOfferable(): bool
    {
        return $this === self::Enabled;
    }
}
