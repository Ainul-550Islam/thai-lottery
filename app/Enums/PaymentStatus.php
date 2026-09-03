<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Authorized => 'Authorized',
            self::Captured => 'Captured',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially Refunded',
            self::Cancelled => 'Cancelled',
            self::Disputed => 'Disputed',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Captured, self::Failed, self::Refunded, self::Cancelled]);
    }

    public function isSuccessful(): bool
    {
        return in_array($this, [self::Captured, self::Authorized]);
    }

    public function canRefund(): bool
    {
        return in_array($this, [self::Captured, self::Authorized]);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Authorized => 'blue',
            self::Captured => 'green',
            self::Failed => 'red',
            self::Refunded => 'orange',
            self::PartiallyRefunded => 'orange',
            self::Cancelled => 'gray',
            self::Disputed => 'red',
        };
    }
}
