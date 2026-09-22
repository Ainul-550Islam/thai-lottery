<?php

declare(strict_types=1);

namespace App\DTOs\Prize;

use App\Enums\PrizeClaimStatus;

/**
 * The validation service's verdict over one PrizeClaimData.
 *
 * EVERY ANSWER IS STRUCTURED, NOT EXCEPTIONAL
 * -------------------------------------------
 * A claim the rules allow is only HALF the signal. "Not eligible — window is
 * closed" and "not eligible — already claimed elsewhere" and "not eligible —
 * not the owner" get DIFFERENT UX and different operational follow-through.
 * The verdict object therefore carries the complete reason decomposition:
 * every failing rule named, every passing rule named, so support never has
 * to ask the logs "which check failed?".
 *
 * When the claim IS eligible, the result carries the downstream state the
 * claim flow should advance to (auto-Approved below the manual-review
 * threshold, UnderReview at or above it) and the locked amount/currency/
 * window-context it was computed with. The caller reads; only the claim
 * service writes.
 */
final class PrizeClaimResultData
{
    /**
     * @param  list<string>  $failedRules  The stable rule keys that failed
     *                                    (empty when eligible).
     * @param  list<string>  $passedRules  The stable rule keys that passed
     *                                    (full set when eligible).
     * @param  string|null   $reason  The human explanation of the foremost
     *                                failure, or null when eligible.
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly PrizeClaimStatus $nextStatus,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $windowClosesAt,
        public readonly array $failedRules,
        public readonly array $passedRules,
        public readonly ?string $reason,
        public readonly array $context = [],
    ) {
    }

    /**
     * Whether the result implies the claim may proceed (all gates passed).
     * Equivalent to $eligible; the English helper exists for read-sites that
     * read more naturally in the affirmative.
     */
    public function mayProceed(): bool
    {
        return $this->eligible;
    }

    /**
     * The foremost refusal reason — readable by the player-facing surface,
     * or null when eligible.
     */
    public function refusalReason(): ?string
    {
        return $this->reason;
    }

    /**
     * The overarching eligibility verdict for audit lines: 'eligible',
     * or 'ineligible' joined to the named refrains.
     */
    public function verdict(): string
    {
        if ($this->eligible) {
            return 'eligible';
        }

        return sprintf('ineligible (%s)', implode(',', $this->failedRules));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'next_status' => $this->nextStatus->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'window_closes_at' => $this->windowClosesAt,
            'failed_rules' => $this->failedRules,
            'passed_rules' => $this->passedRules,
            'reason' => $this->reason,
            'context' => $this->context,
        ];
    }
}
