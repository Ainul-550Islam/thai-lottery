<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * ReportExportException — export lifecycle/checksum/expiry
 * failures with stable codes.
 */
final class ReportExportException extends RuntimeException
{
    public const MALFORMED = 'EXPORT_MALFORMED';
    public const UNSUPPORTED_FORMAT = 'EXPORT_UNSUPPORTED_FORMAT';
    public const JOB_INCOMPLETE = 'EXPORT_JOB_INCOMPLETE';
    public const EXPIRED = 'EXPORT_EXPIRED';
    public const CHECKSUM_MISMATCH = 'EXPORT_CHECKSUM_MISMATCH';
    public const NOT_FOUND = 'EXPORT_NOT_FOUND';

    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Report export refused: '.$message);
    }

    public function errorCode(): string
    {
        return $this->deskCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public static function malformed(string $reason): self
    {
        return new self(self::MALFORMED, $reason, ['reason' => $reason]);
    }

    public static function unsupportedFormat(string $format): self
    {
        return new self(self::UNSUPPORTED_FORMAT, sprintf('format [%s] has no deterministic writer on this desk', $format), ['format' => $format]);
    }

    public static function jobIncomplete(string $fingerprint, string $status): self
    {
        return new self(self::JOB_INCOMPLETE, sprintf('report job [%s] is [%s]; exports are earned at completed', substr($fingerprint, 0, 12), $status), ['query_fingerprint' => $fingerprint, 'status' => $status]);
    }

    public static function expired(string $fingerprint): self
    {
        return new self(self::EXPIRED, sprintf('artifact [%s] passed its server retention horizon', substr($fingerprint, 0, 12)), ['artifact_fingerprint' => $fingerprint]);
    }

    public static function checksumMismatch(string $fingerprint): self
    {
        return new self(self::CHECKSUM_MISMATCH, sprintf('artifact [%s] bytes do not match the sealed checksum', substr($fingerprint, 0, 12)), ['artifact_fingerprint' => $fingerprint]);
    }

    public static function notFound(string $reference): self
    {
        return new self(self::NOT_FOUND, sprintf('export [%s] is unknown to this desk', $reference), ['reference' => $reference]);
    }
}
