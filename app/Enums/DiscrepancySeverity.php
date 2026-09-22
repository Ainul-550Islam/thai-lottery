<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How dangerous one reconciliation discrepancy is.
 *
 * ORDERING IS SEMANTIC, NOT COSMETIC
 *   Critical — a money invariant is provably broken: negative balance,
 *              unbalanced ledger, duplicated money. Any one Critical makes the
 *              whole report Critical (alerting, non-zero CLI exit).
 *   High     — money is consistent but in a state that cannot persist safely:
 *              locked funds with nothing behind them. Review today.
 *   Warning  — an inconsistency worth knowing about with no proven money
 *              exposure yet.
 *   Info     — purely informational finding.
 *
 * Values are published in reports and alert metadata. Add new severities in
 * rank order; never rename existing ones.
 */
enum DiscrepancySeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Warning => 'Warning',
            self::Info => 'Info',
        };
    }

    public function isCritical(): bool
    {
        return $this === self::Critical;
    }

    /**
     * Sort rank for report presentation: most dangerous first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Warning => 2,
            self::Info => 3,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'red',
            self::High => 'orange',
            self::Warning => 'yellow',
            self::Info => 'gray',
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
