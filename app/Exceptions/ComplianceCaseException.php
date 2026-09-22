<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Compliance case lifecycle refusals.
 *
 * - COMPLIANCE_CASE_MALFORMED            grammar never accepted the ask.
 * - COMPLIANCE_CASE_INVALID_TRANSITION   the lifecycle map refuses.
 * - COMPLIANCE_CASE_DUPLICATE            same key, DIFFERENT facts — a fork.
 * - COMPLIANCE_CASE_MISSING_EVIDENCE     a case without an evidence
 *                                          fingerprint is smoke, not paper.
 * - COMPLIANCE_CASE_UNAUTHORIZED_CLOSURE  a non-admin hand tried to seal
 *                                          a file (or seal a live one).
 * - COMPLIANCE_CASE_NOT_FOUND            named case missing.
 * - COMPLIANCE_CASE_HOLD_ACTIVE          the case is under an unreleased
 *                                          compliance hold — closure waits.
 */
final class ComplianceCaseException extends Exception
{
    public const CODE_MALFORMED = 'COMPLIANCE_CASE_MALFORMED';

    public const CODE_INVALID_TRANSITION = 'COMPLIANCE_CASE_INVALID_TRANSITION';

    public const CODE_DUPLICATE = 'COMPLIANCE_CASE_DUPLICATE';

    public const CODE_MISSING_EVIDENCE = 'COMPLIANCE_CASE_MISSING_EVIDENCE';

    public const CODE_UNAUTHORIZED_CLOSURE = 'COMPLIANCE_CASE_UNAUTHORIZED_CLOSURE';

    public const CODE_NOT_FOUND = 'COMPLIANCE_CASE_NOT_FOUND';

    public const CODE_HOLD_ACTIVE = 'COMPLIANCE_CASE_HOLD_ACTIVE';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $errorContext = [],
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $reason, array $context = []): self
    {
        return new self(sprintf('Compliance case refused: %s', $reason), self::CODE_MALFORMED, $context + ['reason' => $reason]);
    }

    public static function invalidTransition(string $caseKey, string $from, string $to, array $context = []): self
    {
        return new self(
            sprintf('Compliance case refused: case may not move %s → %s', $from, $to),
            self::CODE_INVALID_TRANSITION,
            $context + ['case_key_prefix' => substr($caseKey, 0, 16), 'from' => $from, 'to' => $to],
        );
    }

    public static function duplicate(string $caseKey, array $context = []): self
    {
        return new self(
            sprintf('Compliance case refused: a different file already carries this key — a fork'),
            self::CODE_DUPLICATE,
            $context + ['case_key_prefix' => substr($caseKey, 0, 16)],
        );
    }

    public static function missingEvidence(string $reason, array $context = []): self
    {
        return new self(sprintf('Compliance case refused: %s', $reason), self::CODE_MISSING_EVIDENCE, $context + ['reason' => $reason]);
    }

    public static function unauthorizedClosure(int $actorUserId, array $context = []): self
    {
        return new self(
            sprintf('Compliance case refused: operator #%d lacks closure authority', $actorUserId),
            self::CODE_UNAUTHORIZED_CLOSURE,
            $context + ['actor_user_id' => $actorUserId],
        );
    }

    public static function notFound(string $caseKey, array $context = []): self
    {
        return new self(
            sprintf('Compliance case refused: [%s] is not a file on this desk', substr($caseKey, 0, 16).'…'),
            self::CODE_NOT_FOUND,
            $context + ['case_key_prefix' => substr($caseKey, 0, 16)],
        );
    }

    public static function holdActive(string $caseKey, array $context = []): self
    {
        return new self(
            sprintf('Compliance case refused: an unreleased compliance hold keeps this file open', []),
            self::CODE_HOLD_ACTIVE,
            $context + ['case_key_prefix' => substr($caseKey, 0, 16)],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->errorContext;
    }
}
