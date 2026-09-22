<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The overall verdict of one financial-reconciliation execution.
 *
 * THE THREE TIERS CARRY OPERATIONAL MEANING
 *   Pass     — zero discrepancies of any severity. Money reality, the wallet
 *              projection and the ledger tell the same story.
 *   Warning  — discrepancies exist but none is Critical: review is required,
 *              the platform keeps operating.
 *   Critical — at least one Critical-severity discrepancy: a money invariant is
 *              broken (unbalanced ledger, negative balance, duplicated money).
 *              The CLI command exits non-zero and the queue job fans an alert
 *              out to operators on this tier.
 *
 * VALUES ARE PUBLISHED
 * The backing string appears in the CLI JSON report, the Filament operator
 * page and queued alert metadata. Add new tiers at the end; never rename.
 */
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

    /**
     * Color used by Filament badges and CLI table output.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pass => 'green',
            self::Warning => 'yellow',
            self::Critical => 'red',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
