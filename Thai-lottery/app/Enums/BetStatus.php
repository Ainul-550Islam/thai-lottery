<?php

namespace App\Enums;

enum BetStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Won = 'won';
    case Lost = 'lost';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Won => 'Won',
            self::Lost => 'Lost',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Won, self::Lost, self::Refunded, self::Cancelled]);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Pending, self::Active]);
    }

    public function canRefund(): bool
    {
        return in_array($this, [self::Pending, self::Active]);
    }

    public function isWinning(): bool
    {
        return $this === self::Won;
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Active => 'blue',
            self::Won => 'green',
            self::Lost => 'red',
            self::Refunded => 'orange',
            self::Cancelled => 'gray',
        };
    }
}
