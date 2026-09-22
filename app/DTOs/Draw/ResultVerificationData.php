<?php

declare(strict_types=1);

namespace App\DTOs\Draw;

/**
 * The public / authorized verification request.
 *
 * WHAT THE CALLER PRESENTS
 * - draw_reference: the draw's public handle (draw_number on the ledger).
 *   Canonical uppercase/digits/hyphens — like a ticket reference, a draw
 *   is always NAMED and never inferred.
 * - result_fingerprint: the fingerprint the caller says it saw (64 hex).
 * - nonce: at least 16 characters — reader-supplied freshness; this
 *   service is READ-ONLY so the nonce is never consumed on a ledger,
 *   but grammar still demands one so the caller's contract is a full
 *   verification contract (it belongs to the same vocabulary as our
 *   ticket QR lane).
 * - context: reader id / origin tag; never secrets.
 */
final readonly class ResultVerificationData
{
    public function __construct(
        public string $drawReference,
        public string $resultFingerprint,
        public string $nonce,
        public array $context = [],
    ) {
    }

    /**
     * @throws \App\Exceptions\DrawReconciliationException
     */
    public static function fromPayload(
        string $drawReference,
        string $resultFingerprint,
        string $nonce,
        array $context = [],
    ): self {
        $ref = strtoupper(trim($drawReference));
        $fp = strtolower(trim($resultFingerprint));
        $n = trim($nonce);

        if (! preg_match('/^[A-Z0-9-]{1,64}$/', $ref)) {
            throw \App\Exceptions\DrawReconciliationException::malformed(
                'the draw reference is not canonical (uppercase ASCII, digits, hyphens)',
            );
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw \App\Exceptions\DrawReconciliationException::malformed(
                'the result fingerprint must be exactly 64 lowercase hex characters',
            );
        }

        if (mb_strlen($n) < 16) {
            throw \App\Exceptions\DrawReconciliationException::malformed(
                'the nonce must be at least 16 characters',
            );
        }

        return new self(
            drawReference: $ref,
            resultFingerprint: $fp,
            nonce: $n,
            context: $context,
        );
    }
}
