<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * AdminOperationException — stable admin-operation codes.
 */
final class AdminOperationException extends RuntimeException
{
    public const MALFORMED = 'ADMINOP_MALFORMED';
    public const MISSING_TARGET_REFERENCE = 'ADMINOP_MISSING_TARGET_REFERENCE';
    public const FORBIDDEN = 'ADMINOP_FORBIDDEN';
    public const MAKER_CANNOT_BE_CHECKER = 'ADMINOP_MAKER_CANNOT_BE_CHECKER';
    public const INVALID_TRANSITION = 'ADMINOP_INVALID_TRANSITION';
    public const TERMINAL_OPERATION = 'ADMINOP_TERMINAL_OPERATION';
    public const EXECUTION_ROUTE_NOT_SET = 'ADMINOP_EXECUTION_ROUTE_NOT_SET';
    public const NOT_FOUND = 'ADMINOP_NOT_FOUND';

    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Admin operation refused: '.$message);
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

    public static function missingTargetReference(string $type): self
    {
        return new self(self::MISSING_TARGET_REFERENCE, sprintf('operation [%s] requires a target reference', $type), ['type' => $type]);
    }

    public static function forbidden(string $capability): self
    {
        return new self(self::FORBIDDEN, sprintf('the operator lacks the [%s] capability', $capability), ['capability' => $capability]);
    }

    public static function makerCannotBeChecker(string $fingerprint): self
    {
        return new self(self::MAKER_CANNOT_BE_CHECKER, sprintf('the maker may not check operation [%s]', substr($fingerprint, 0, 12)), ['operation_fingerprint' => $fingerprint]);
    }

    public static function invalidTransition(string $fingerprint, string $from, string $to): self
    {
        return new self(self::INVALID_TRANSITION, sprintf('operation [%s] may not move %s → %s', substr($fingerprint, 0, 12), $from, $to), ['operation_fingerprint' => $fingerprint, 'from' => $from, 'to' => $to]);
    }

    public static function terminalOperation(string $fingerprint, string $status): self
    {
        return new self(self::TERMINAL_OPERATION, sprintf('operation [%s] already rests in [%s]', substr($fingerprint, 0, 12), $status), ['operation_fingerprint' => $fingerprint, 'status' => $status]);
    }

    public static function executionRouteNotSet(string $type): self
    {
        return new self(self::EXECUTION_ROUTE_NOT_SET, sprintf('operation type [%s] has no owning desk route here', $type), ['type' => $type]);
    }

    public static function notFound(string $reference): self
    {
        return new self(self::NOT_FOUND, sprintf('operation [%s] is unknown to this desk', $reference), ['reference' => $reference]);
    }
}
