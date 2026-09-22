<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\DTOs\Operations\ReportExportData;
use App\Enums\ReportFormat;
use App\Enums\ReportJobStatus;
use App\Exceptions\ReportExportException;
use App\Models\OperationalReportJob;
use App\Models\ReportExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ReportExportService — deterministic export generation: same
 * completed job + same format = the same BYTES (stable ordering,
 * pinned envelope fields), the same checksum, the same artifact
 * row. Retrieval re-verifies the sealed checksum every time; a
 * horizon-passed artifact is withdrawn by name.
 */
final class ReportExportService
{
    private const DISK = 'local';
    private const DIRECTORY = 'reports';

    public function __construct(
        private readonly OperationalReportService $reports,
    ) {
    }

    /**
     * RENDER + SEAL: exactly-once by artifact_fingerprint.
     */
    public function render(ReportExportData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var OperationalReportJob $job */
            $job = OperationalReportJob::query()->findOrFail($data->reportJobId);

            if ($job->status !== ReportJobStatus::Completed) {
                throw ReportExportException::jobIncomplete($job->query_fingerprint, $job->status->value);
            }

            $fingerprint = $data->artifactFingerprint($job->query_fingerprint);

            /** @var ReportExport|null $existing */
            $existing = ReportExport::query()->where('artifact_fingerprint', $fingerprint)->first();

            if ($existing instanceof ReportExport) {
                return ['export' => $existing, 'created' => false, 'bytes' => $this->fetchBytes($existing)];
            }

            if (! $data->format->isLocallyRenderable()) {
                throw ReportExportException::unsupportedFormat($data->format->value);
            }

            $rows = $this->reports->rows($job);
            $bytes = $this->writeBytes($data->format, $job, $rows);
            $checksum = hash('sha256', $bytes);

            $path = self::DIRECTORY.'/'.$fingerprint.'.'.$data->format->value;
            Storage::disk(self::DISK)->put($path, $bytes);

            $row = ReportExport::query()->create([
                'artifact_fingerprint' => $fingerprint,
                'report_job_id' => $job->id,
                'format' => $data->format,
                'status' => 'ready',
                'checksum' => $checksum,
                'byte_size' => strlen($bytes),
                'storage_path' => $path,
                'row_count' => $rows->count(),
                'expires_at' => $job->expires_at,
            ]);

            return ['export' => $row, 'created' => true, 'bytes' => $bytes];
        });
    }

    /**
     * RETRIEVE: find artifact, verify the CHECKSUM equals the seal,
     * withdraw by name when the horizon passed. Returns METADATA
     * for the response lane; bytes flow when asked.
     */
    public function retrieve(string $artifactFingerprint, int $requesterUserId): array
    {
        /** @var ReportExport|null $artifact */
        $artifact = ReportExport::query()->where('artifact_fingerprint', $artifactFingerprint)->first();

        if (! $artifact instanceof ReportExport) {
            throw ReportExportException::notFound(substr($artifactFingerprint, 0, 12));
        }

        /** @var OperationalReportJob $job */
        $job = OperationalReportJob::query()->findOrFail($artifact->report_job_id);

        if ((int) $job->requester_user_id !== $requesterUserId) {
            throw ReportExportException::notFound(substr($artifactFingerprint, 0, 12));
        }

        if ($artifact->isExpired()) {
            throw ReportExportException::expired($artifactFingerprint);
        }

        $bytes = $this->fetchBytes($artifact);

        if (hash('sha256', $bytes) !== $artifact->checksum) {
            throw ReportExportException::checksumMismatch($artifactFingerprint);
        }

        return ['export' => $artifact, 'bytes' => $bytes];
    }

    /**
     * Horizon sweep: pass_expiry artifacts are withdrawn, files
     * deleted. Rows stay as the audit trail of what once stood.
     */
    public function expireStale(int $limit = 200): int
    {
        $expired = 0;

        ReportExport::query()
            ->where('status', 'ready')
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->get()
            ->each(function (ReportExport $artifact) use (&$expired): void {
                DB::transaction(function () use ($artifact, &$expired): void {
                    /** @var ReportExport $locked */
                    $locked = ReportExport::query()->lockForUpdate()->findOrFail($artifact->id);
                    if ($locked->status !== 'ready') {
                        return;
                    }
                    Storage::disk(self::DISK)->delete($locked->storage_path);
                    $locked->status = 'expired';
                    $locked->save();
                    $expired++;
                });
            });

        return $expired;
    }

    /**
     * Deterministic artifact bytes: pinned envelope, stable columns,
     * stable ordering, LF endings — always.
     *
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $rows
     */
    private function writeBytes(ReportFormat $format, OperationalReportJob $job, \Illuminate\Support\Collection $rows): string
    {
        if ($format === ReportFormat::Json) {
            return json_encode([
                'report_type' => $job->report_type->value,
                'query_fingerprint' => $job->query_fingerprint,
                'horizon_start' => $job->horizon_start?->toIso8601String(),
                'horizon_end' => $job->horizon_end?->toIso8601String(),
                'row_count' => $rows->count(),
                'rows' => $rows->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }

        // CSV: header row from union of keys, cells quoted, LF lines.
        $headers = [];
        $rows->each(function (array $row) use (&$headers): void {
            foreach (array_keys($row) as $key) {
                if (! in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }
        });

        $out = self::csvLine($headers);
        $rows->each(function (array $row) use ($headers, &$out): void {
            $out .= self::csvLine(array_map(fn (string $h): string => self::cell($row[$h] ?? null), $headers));
        });

        return $out;
    }

    /**
     * @param array<int, string> $cells
     */
    private static function csvLine(array $cells): string
    {
        return implode(',', array_map(static fn (string $c): string => '"'.str_replace('"', '""', $c).'"', $cells))."\n";
    }

    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value);
    }

    private function fetchBytes(ReportExport $artifact): string
    {
        $bytes = Storage::disk(self::DISK)->get($artifact->storage_path);

        if ($bytes === null || $bytes === false) {
            throw ReportExportException::notFound($artifact->artifact_fingerprint);
        }

        return $bytes;
    }
}
