<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Retail vendor lifecycle.
 *
 * One registered commercial channel through which the house's fixed ticket
 * luggage reaches the street. The lifecycle is deliberately narrower than
 * the ticket/product engines: a vendor exists for as long as a commercial
 * court keeps it alive.
 *
 *   Pending    → Active, Closed (never-approved vendors close quiet)
 *   Active     → Suspended, Closed
 *   Suspended  → Active, Closed
 *   Closed     → nothing (terminal — history remains; commerce stops)
 *
 * Only ACTIVE vendors may receive fresh allocation; ACTIVE vendors sell.
 * A suspended vendor holds what it holds, lets nothing out the door, and is
 * re-awakened only by explicit operator gesture.
 */
enum RetailVendorStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    /**
     * Whether this vendor may receive fresh ticket allocation today.
     */
    public function mayReceiveAllocation(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether this vendor may sell its held inventory today.
     */
    public function maySell(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the state is a commercial death sentence — the vendor may
     * still carry inventory history, but never again operates.
     */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Closed],
            self::Active => [self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Closed => 'danger',
        };
    }
}
