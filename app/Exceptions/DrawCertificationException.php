<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The certification court's pronounced refusals.
 *
 * CASES, by refusal ground:
 * - DRAW_CERT_MALFORMED        the payload never parsed into grammar.
 * - DRAW_CERT_NOT_FOUND        the named draw (or certification) isn't
 *                              on the ledger.
 * - DRAW_CERT_STATE_FORBIDS    the draw/certification is in a state the
 *                              asked act cannot conversation with.
 * - DRAW_CERT_DUPLICATE        a DIFFERENT ask presented under the same
 *                              certification key — the classic fork.
 * - DRAW_CERT_FINGERPRINT_MISMATCH
 *   the presented fingerprint does not equal what the court re-derived
 *   from the draw's own winning numbers.
 *
 * Code shape follows the standing batch-6/7/8 convention: prefixed code
 * constants, static factories, errorCode() + context().
 */
final class DrawCertificationException extends Exception
{
    public const CODE_MALFORMED = 'DRAW_CERT_MALFORMED';

    public const CODE_NOT_FOUND = 'DRAW_CERT_NOT_FOUND';

    public const CODE_STATE_FORBIDS = 'DRAW_CERT_STATE_FORBIDS';

    public const CODE_DUPLICATE = 'DRAW_CERT_DUPLICATE';

    public const CODE_FINGERPRINT_MISMATCH = 'DRAW_CERT_FINGERPRINT_MISMATCH';

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
            sprintf('Draw certification refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $drawReference, array $context = []): self
    {
        return new self(
            sprintf('Draw certification refused: [%s] is not on the ledger', $drawReference),
            self::CODE_NOT_FOUND,
            $context + ['draw_reference' => $drawReference],
        );
    }

    public static function stateForbids(int $drawId, string $currentState, string $asked, array $context = []): self
    {
        return new self(
            sprintf('Draw certification refused: draw #%d is in [%s], which forbids [%s]', $drawId, $currentState, $asked),
            self::CODE_STATE_FORBIDS,
            $context + ['draw_id' => $drawId, 'current_state' => $currentState, 'asked' => $asked],
        );
    }

    public static function duplicate(string $certificationKey, int $drawId, array $context = []): self
    {
        return new self(
            sprintf('Draw certification refused: a different ask presented under the same certification key %s...', substr($certificationKey, 0, 12)),
            self::CODE_DUPLICATE,
            $context + ['certification_key' => $certificationKey, 'draw_id' => $drawId],
        );
    }

    public static function fingerprintMismatch(int $drawId, array $context = []): self
    {
        return new self(
            sprintf('Draw certification refused: the presented fingerprint does not equal the court-derived one (draw #%d)', $drawId),
            self::CODE_FINGERPRINT_MISMATCH,
            $context + ['draw_id' => $drawId],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->errorContext;
    }
}
