<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Fail-closed KYC refusals.
 *
 * - KYC_MALFORMED             grammar never accepted the paper.
 * - KYC_INVALID_DOCUMENT_SET  the document set can't carry a verdict
 *                               (no primary identity document, and/or
 *                               evidence in a non-evidence state — the
 *                               service derives, never guesses).
 * - KYC_EXPIRED_EVIDENCE      a required document's own expiry passed:
 *                               lapsed evidence may never erect a standing
 *                               verdict.
 * - KYC_VERIFICATION_CONFLICT the current live verification disagrees
 *                               with the proposed act (wrong lifeline).
 * - KYC_DUPLICATE_DECISION    a DIFFERENT decision under the same
 *                               verification reference — a fork; replay
 *                               itself is free.
 * - KYC_NOT_FOUND             named user/document/verification missing.
 */
final class KycVerificationException extends Exception
{
    public const CODE_MALFORMED = 'KYC_MALFORMED';

    public const CODE_INVALID_DOCUMENT_SET = 'KYC_INVALID_DOCUMENT_SET';

    public const CODE_EXPIRED_EVIDENCE = 'KYC_EXPIRED_EVIDENCE';

    public const CODE_VERIFICATION_CONFLICT = 'KYC_VERIFICATION_CONFLICT';

    public const CODE_DUPLICATE_DECISION = 'KYC_DUPLICATE_DECISION';

    public const CODE_NOT_FOUND = 'KYC_NOT_FOUND';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $errorContext = [],
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $reason, array $context = []): self
    {
        return new self(
            sprintf('KYC refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function invalidDocumentSet(int $userId, string $why, array $context = []): self
    {
        return new self(
            sprintf('KYC refused: user #%d\'s document set can\'t carry a verdict — %s', $userId, $why),
            self::CODE_INVALID_DOCUMENT_SET,
            $context + ['user_id' => $userId, 'why' => $why],
        );
    }

    public static function expiredEvidence(string $fingerprint, array $context = []): self
    {
        return new self(
            sprintf('KYC refused: document evidence [%s] expired — lapsed paper may never carry a standing verdict', substr($fingerprint, 0, 12).'…'),
            self::CODE_EXPIRED_EVIDENCE,
            $context + ['fingerprint_prefix' => substr($fingerprint, 0, 12)],
        );
    }

    public static function conflict(string $reference, string $why, array $context = []): self
    {
        return new self(
            sprintf('KYC refused: verification [%s] conflicts — %s', $reference, $why),
            self::CODE_VERIFICATION_CONFLICT,
            $context + ['reference' => $reference, 'why' => $why],
        );
    }

    public static function duplicateDecision(string $reference, array $context = []): self
    {
        return new self(
            sprintf('KYC refused: a different decision already carries reference [%s] — a fork, cried loudly', $reference),
            self::CODE_DUPLICATE_DECISION,
            $context + ['reference' => $reference],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('KYC refused: [%s] is not on the compliance paper', $reference),
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
