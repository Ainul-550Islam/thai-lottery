<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Vendor/retail allocation lifecycle.
 *
 * GLO physical reality: the house doesn't hand paper to a vendor casually —
 * it commits a defined quantity against a defined draw to a defined vendor,
 * and the conversation that follows has exactly these states:
 *
 *   Pending    → Allocated
 *   Allocated  → Accepted, Released
 *   Accepted   → Released, Exhausted
 *   Released   → nothing (terminal: quota returned to the house)
 *   Exhausted  → nothing (terminal: quota fully sold through)
 *
 * States are status, not arithmetic — the arithmetic of "how many units
 * remain in this allocation" is DERIVED from the inventory items that name
 * this allocation as their holder, and never stored as a counter here (the
 * standing codebase rule: membership is projected from member stamps, so
 * the allocation row can never disagree with its units).
 */
enum TicketAllocationStatus: string
{
    case Pending = 'pending';
    case Allocated = 'allocated';
    case Accepted = 'accepted';
    case Released = 'released';
    case Exhausted = 'exhausted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Allocated => 'Allocated',
            self::Accepted => 'Accepted',
            self::Released => 'Released',
            self::Exhausted => 'Exhausted',
        };
    }

    /**
     * Whether inventory may still be bound INTO this allocation.
     */
    public function admitsUnits(): bool
    {
        return in_array($this, [self::Pending, self::Allocated, self::Accepted], true);
    }

    /**
     * Whether units already bound may be SOLD out of this allocation today
     * (accepted quota only: an allocation not yet acknowledged by the
     * vendor sells nothing).
     */
    public function maySell(): bool
    {
        return $this === self::Accepted;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Released, self::Exhausted], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Allocated],
            self::Allocated => [self::Accepted, self::Released],
            self::Accepted => [self::Released, self::Exhausted],
            self::Released => [],
            self::Exhausted => [],
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
            self::Allocated => 'info',
            self::Accepted => 'success',
            self::Released => 'warning',
            self::Exhausted => 'danger',
        };
    }
}
