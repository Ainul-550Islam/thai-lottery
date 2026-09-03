<?php

namespace App\Enums;

enum LimitStatus: string
{
    case Active = 'active';
    case Exceeded = 'exceeded';
    case Suspended = 'suspended';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Exceeded => 'Exceeded',
            self::Suspended => 'Suspended',
            self::Removed => 'Removed',
        };
    }

    public function allowsBetting(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Exceeded => 'red',
            self::Suspended => 'yellow',
            self::Removed => 'gray',
        };
    }
}
