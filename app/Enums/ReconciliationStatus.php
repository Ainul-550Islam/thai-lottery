<?php

declare(strict_types=1);

namespace App\Enums;

enum ReconciliationStatus: string
{
    case Pass = 'pass';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    public function isPassing(): bool
    {
        return $this === self::Pass;
    }

    public function isCritical(): bool
    {
        return $this === self::Critical;
    }

    public function color(): string
    {
        return match ($this) {
            self::Pass => 'success',
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }
}
