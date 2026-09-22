<?php

declare(strict_types=1);

namespace App\DTOs\Operations;

use App\Enums\ReportFormat;
use App\Exceptions\ReportExportException;

/**
 * ReportExportData — an export artifact request: job + format.
 * The artifact identity binds the job query and format; identical
 * re-asks replay the sealed bytes.
 */
final class ReportExportData
{
    public const RETENTION_HOURS = 72;

    public function __construct(
        public readonly int $reportJobId,
        public readonly ReportFormat $format,
    ) {
    }

    /**
     * @param array{report_job_id:int, format:string|ReportFormat} $data
     */
    public static function fromInput(array $data): self
    {
        $format = $data['format'] ?? null;
        if (! $format instanceof ReportFormat) {
            $format = is_string($format) ? ReportFormat::tryFrom(strtolower(trim($format))) : null;
        }
        if (! $format instanceof ReportFormat) {
            throw ReportExportException::malformed('A valid export format is required');
        }

        return new self(
            reportJobId: (int) ($data['report_job_id'] ?? 0),
            format: $format,
        );
    }

    /**
     * Artifact identity: the rendered job at this format.
     */
    public function artifactFingerprint(string $queryFingerprint): string
    {
        return hash('sha256', 'glo-export|'.$queryFingerprint.'|'.$this->format->value);
    }
}
