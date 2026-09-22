<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

use App\Enums\BetAmendmentStatus;
use App\Models\Bet;
use App\Models\BetAmendment;

/**
 * The outcome of a bet amendment, whether it applied or failed.
 *
 * WHY RESULT — NOT EXCEPTION — CARRIES THE FAILED CASE
 * A refused replacement is NOT an exceptional state: it is a normal, reportable
 * outcome in which the player's money is already safe (the old bet was cancelled
 * and fully refunded before the replacement was attempted). The amendment row
 * records Failed with the refusal reason so the player can see both halves:
 * "your old bet is refunded; your new bet was not accepted, and here is why."
 */
final readonly class BetAmendmentResult
{
    public function __construct(
        public BetAmendment $amendment,
        public Bet $originalBet,
        public ?Bet $replacementBet,
        public BetAmendmentStatus $status,
        public string $refundedAmount,
        public ?string $chargedAmount,
        public string $currency,
        public ?string $failureReason,
    ) {
    }

    public function isApplied(): bool
    {
        return $this->status === BetAmendmentStatus::Applied;
    }

    public function isFailed(): bool
    {
        return $this->status === BetAmendmentStatus::Failed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amendment_id' => (int) $this->amendment->getKey(),
            'status' => $this->status->value,
            'original_bet_id' => (int) $this->originalBet->getKey(),
            'original_bet_number' => (string) $this->originalBet->bet_number,
            'replacement_bet_id' => $this->replacementBet === null ? null : (int) $this->replacementBet->getKey(),
            'replacement_bet_number' => $this->replacementBet?->bet_number,
            'refunded_amount' => $this->refundedAmount,
            'charged_amount' => $this->chargedAmount,
            'currency' => $this->currency,
            'failure_reason' => $this->failureReason,
            'applied_at' => $this->amendment->applied_at?->toIso8601String(),
        ];
    }
}
