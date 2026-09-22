<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * OperationalReportException — report validation/query/generation
 * failures with stable codes.
 */
final class OperationalReportException extends RuntimeException
{
    public const MALFORMED = 'OPREPORT_MALFORMED';
    public const UNSUPPORTED_FILTER = 'OPREPORT_UNSUPPORTED_FILTER';
    public const ACCESS_DENIED = 'OPREPORT_ACCESS_DENIED';
    public const TERMINAL_JOB = 'OPREPORT_TERMINAL_JOB';
    public const NOT_FOUND = 'OPREPORT_NOT_FOUND';
    public const GENERATION_FAILURE = 'OPREPORT_GENERATION_FAILURE';

    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Operational report refused: '.$message);
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

    public static function unsupportedFilter(string $type, string $filter): self
    {
        return new self(self::UNSUPPORTED_FILTER, sprintf('filter [%s] is not part of the [%s] report blend', $filter, $type), ['report_type' => $type, 'filter' => $filter]);
    }

    public static function accessDenied(string $reportType): self
    {
        return new self(self::ACCESS_DENIED, sprintf('the operator may not ask the [%s] family', $reportType), ['report_type' => $reportType]);
    }

    public static function terminalJob(string $fingerprint, string $status): self
    {
        return new self(self::TERMINAL_JOB, sprintf('report job [%s] already rests in [%s]', substr($fingerprint, 0, 12), $status), ['query_fingerprint' => $fingerprint, 'status' => $status]);
    }

    public static function notFound(string $reference): self
    {
        return new self(self::NOT_FOUND, sprintf('report job [%s] is unknown to this desk', $reference), ['reference' => $reference]);
    }

    public static function generationFailure(string $reason): self
    {
        return new self(self::GENERATION_FAILURE, $reason, ['reason' => $reason]);
    }
}
