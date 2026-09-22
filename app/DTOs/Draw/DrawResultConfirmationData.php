<?php

declare(strict_types=1);

namespace App\DTOs\Draw;

/**
 * The operator-stated confirmation of ONE ingested draw result.
 *
 * A confirmation is the checker's answer to "do YOU see the same numbers the
 * feed/op saw?": the claimed first_prize and bottom_two, the confirmer's
 * identity and the result-row identity (fingerprint) they were shown. The
 * confirmation service compares these against the stored ingestion record
 * bit for bit; a single-digit drift refuses the confirmation.
 *
 * WHY A DTO
 * The confirmation decision is evidence: the same object that carries the
 * claimed values carries the actor stamp, so an auditor reading the audit
 * record always sees both identities and both numbers in one row. Array-free
 * construction means every legal call passes a complete typed object; the
 * malformed-entry shape rejected() is what the service degrades unparseable
 * input through instead of silently coercing it.
 */
final class DrawResultConfirmationData
{
    /**
     * @param  string  $resultFingerprint  The ingestion-side fingerprint of
     *                                    the record being confirmed (sha256
     *                                    over the canonical pair).
     * @param  array<string, mixed>  $context  Safe diagnostic context.
     */
    public function __construct(
        public readonly int $drawId,
        public readonly int $confirmerUserId,
        public readonly string $claimedFirstPrize,
        public readonly string $claimedBottomTwo,
        public readonly ?string $resultFingerprint,
        public readonly array $context = [],
    ) {
    }

    /**
     * The claimed numbers must be machine-readable as exactly six + exactly
     * two digits. The confirmation service refuses malformed claimed values
     * BEFORE the comparison — comparing a malformed claim against stored
     * data answers nothing.
     */
    public function claimIsWellFormed(): bool
    {
        return preg_match('/^\d{6}$/', $this->claimedFirstPrize) === 1
            && preg_match('/^\d{2}$/', $this->claimedBottomTwo) === 1;
    }

    /**
     * The claimed bottom-two is ALWAYS the derived tail of the claimed first
     * prize when left blank. A confirmation supplies what the operator saw;
     * blank bottom_two means "same as first prize's last two digits", which
     * is also what the ingestion canonicalizer derived.
     */
    public static function derive(int $drawId, int $confirmerUserId, string $claimedFirstPrize, ?string $claimedBottomTwo, ?string $resultFingerprint, array $context = []): self
    {
        $first = trim($claimedFirstPrize);
        $bottom = $claimedBottomTwo !== null && trim($claimedBottomTwo) !== ''
            ? trim($claimedBottomTwo)
            : ($first !== '' && strlen($first) >= 2 ? substr($first, -2) : '');

        return new self($drawId, $confirmerUserId, $first, $bottom, $resultFingerprint, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'confirmer_user_id' => $this->confirmerUserId,
            'claimed_first_prize' => $this->claimedFirstPrize,
            'claimed_bottom_two' => $this->claimedBottomTwo,
            'result_fingerprint' => $this->resultFingerprint,
            'context' => $this->context,
        ];
    }
}
