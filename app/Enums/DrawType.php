<?php

namespace App\Enums;

enum DrawType: string
{
    case TwoD = '2d';
    case ThreeD = '3d';
    case Tod = 'tod';
    case Run = 'run';

    public function label(): string
    {
        return match ($this) {
            self::TwoD => '2D Draw',
            self::ThreeD => '3D Draw',
            self::Tod => 'Tod Draw',
            self::Run => 'Run Draw',
        };
    }

    public function digits(): int
    {
        return match ($this) {
            self::TwoD => 2,
            self::ThreeD => 3,
            self::Tod => 2,
            self::Run => 2,
        };
    }

    public function payoutMultiplier(): int
    {
        return match ($this) {
            self::TwoD => 90,
            self::ThreeD => 900,
            self::Tod => 45,
            self::Run => 12,
        };
    }

    public function maxNumber(): int
    {
        return match ($this) {
            self::TwoD, self::Tod, self::Run => 99,
            self::ThreeD => 999,
        };
    }

    public function minNumber(): int
    {
        return 0;
    }
}
