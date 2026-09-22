<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * AML assessment refusals.
 *
 * - AML_MALFORMED            grammar never accepted the pack.
 * - AML_INVALID_EVIDENCE     the evidence can't anchor a pronouncement.
 * - AML_STALE_ASSESSMENT     an act tried to ride an assessment whose
 *                              evidence fingerprint is no longer the
 *                              current one.
 * - AML_RISK_CONFLICT        score and pronounced level disagree.
 * - AML_DUPLICATE_ASSESSMENT same key, DIFFERENT facts — a fork (replay
 *                              of identical facts is free).
 * - AML_NOT_FOUND            named user/assessment missing.
 */
final class AmlRiskAssessmentException extends Exception
{
    public const CODE_MALFORMED = 'AML_MALFORMED';

    public const CODE_INVALID_EVIDENCE = 'AML_INVALID_EVIDENCE';

    public const CODE_STALE_ASSESSMENT = 'AML_STALE_ASSESSMENT';

    public const CODE_RISK_CONFLICT = 'AML_RISK_CONFLICT';

    public const CODE_DUPLICATE_ASSESSMENT = 'AML_DUPLICATE_ASSESSMENT';

    public const CODE_NOT_FOUND = 'AML_NOT_FOUND';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $errorContext = [],
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $reason, array $context = []): self
    {
        return new self(sprintf('AML refused: %s', $reason), self::CODE_MALFORMED, $context + ['reason' => $reason]);
    }

    public static function invalidEvidence(string $reason, array $context = []): self
    {
        return new self(sprintf('AML refused: %s', $reason), self::CODE_INVALID_EVIDENCE, $context + ['reason' => $reason]);
    }

    public static function stale(string $assessmentKey, array $context = []): self
    {
        return new self(
            sprintf('AML refused: assessment [%s] is stale — new facts already produced a newer version', substr($assessmentKey, 0, 16).'…'),
            self::CODE_STALE_ASSESSMENT,
            $context + ['assessment_key_prefix' => substr($assessmentKey, 0, 16)],
        );
    }

    public static function riskConflict(string $reason, array $context = []): self
    {
        return new self(sprintf('AML refused: %s', $reason), self::CODE_RISK_CONFLICT, $context + ['reason' => $reason]);
    }

    public static function duplicate(string $assessmentKey, array $context = []): self
    {
        return new self(
            sprintf('AML refused: a different assessment already carries key [%s] — a fork', substr($assessmentKey, 0, 16).'…'),
            self::CODE_DUPLICATE_ASSESSMENT,
            $context + ['assessment_key_prefix' => substr($assessmentKey, 0, 16)],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('AML refused: [%s] is not on the assessment paper', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
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
