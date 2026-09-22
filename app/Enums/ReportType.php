<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ReportType — the report families the desk can generate. Each is
 * a read-only projection over lanes that already own the truth.
 */
enum ReportType: string
{
    case Financial = 'financial';
    case Payment = 'payment';
    case Draw = 'draw';
    case Prize = 'prize';
    case Compliance = 'compliance';
    case Player = 'player';
    case Operational = 'operational';

    /**
     * Accumulators need care: never sum across lanes. Each report
     * rows-based families can be exported wide; others paginated.
     */
    public function defaultFormat(): ReportFormat
    {
        return ReportFormat::Json;
    }

    /**
     * Maximum time horizon (days) the desk will enumerate rows for
     * in one synchronous run; larger asks queue the async job.
     */
    public function syncHorizonDays(): int
    {
        return match ($this) {
            self::Operational => 7,
            default => 31,
        };
    }
}
