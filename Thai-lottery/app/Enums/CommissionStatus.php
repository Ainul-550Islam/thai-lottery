<?php

namespace App\Enums;

enum CommissionStatus: string
{
    case Accrued = 'accrued';
    case Calculated = 'calculated';
    case Payable = 'payable';
    case Paid = 'paid';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Accrued => 'Accrued',
            self::Calculated => 'Calculated',
            self::Payable => 'Payable',
            self::Paid => 'Paid',
            self::Reversed => 'Reversed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Reversed, self::Cancelled]);
    }

    public function canPay(): bool
    {
        return $this === self::Payable;
    }

    public function canReverse(): bool
    {
        return in_array($this, [self::Accrued, self::Calculated, self::Payable, self::Paid]);
    }

    public function color(): string
    {
        return match ($this) {
            self::Accrued => 'yellow',
            self::Calculated => 'blue',
            self::Payable => 'blue',
            self::Paid => 'green',
            self::Reversed => 'orange',
            self::Cancelled => 'gray',
        };
    }
}
