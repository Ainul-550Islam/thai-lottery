<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ReportFormat — export artifact formats. The desk WRITES json and
 * csv firsthand (deterministic bytes); xlsx/pdf are vocabulary
 * seats requested through the export lane, pronounced by name when
 * unbacked by a rendering engine.
 */
enum ReportFormat: string
{
    case Json = 'json';
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    /**
     * Whether the desk can produce deterministic bytes for this
     * format with its own writers (no external render farm).
     */
    public function isLocallyRenderable(): bool
    {
        return match ($this) {
            self::Json, self::Csv => true,
            default => false,
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::Json => 'application/json',
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }
}
