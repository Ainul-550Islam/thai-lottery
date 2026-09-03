<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Confirmed, self::Cancelled, self::Expired]);
    }

    public function canCancel(): bool
    {
        return $this === self::Pending;
    }

    public function isValid(): bool
    {
        return $this === self::Confirmed;
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Confirmed => 'green',
            self::Cancelled => 'red',
            self::Expired => 'gray',
        };
    }
}
