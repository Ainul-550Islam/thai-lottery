<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DrawLifecycleState;

/**
 * Immutable outcome of a real monetary settlement run.
 */
final class SettlementResult
{
    /**
     * @param  list<SettlementSelectionResult>  $selections
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int $drawId,
        public readonly string $drawNumber,
        public readonly DrawLifecycleState $stateBefore,
        public readonly DrawLifecycleState $stateAfter,
        public readonly string $firstPrize,
        public readonly string $bottomTwo,
        public readonly array $selections,
        public readonly int $selectionsEvaluated,
        public readonly int $selectionsWritten,
        public readonly int $betsUpdated,
        public readonly int $winningSelections,
        public readonly string $totalStake,
        public readonly string $totalPrize,
        public readonly string $currency,
        public readonly int $payoutsCreated,
        public readonly string $totalPayoutAmount,
        public readonly bool $alreadySettled,
        public readonly string $mode = 'monetary',
        public readonly ?string $settledAt = null,
        public readonly array $context = [],
    ) {
    }

    /**
     * @return list<SettlementSelectionResult>
     */
    public function selections(): array
    {
        return $this->selections;
    }

    public function performedWork(): bool
    {
        return ! $this->alreadySettled;
    }

    public function wroteNothing(): bool
    {
        return $this->alreadySettled;
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = ['won' => 0, 'lost' => 0];

        foreach ($this->selections as $selection) {
            if ($selection->isWinner()) {
                $counts['won']++;
            } else {
                $counts['lost']++;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'draw_number' => $this->drawNumber,
            'state_before' => $this->stateBefore->value,
            'state_after' => $this->stateAfter->value,
            'first_prize' => $this->firstPrize,
            'bottom_two' => $this->bottomTwo,
            'selections_evaluated' => $this->selectionsEvaluated,
            'selections_written' => $this->selectionsWritten,
            'bets_updated' => $this->betsUpdated,
            'winning_selections' => $this->winningSelections,
            'total_stake' => $this->totalStake,
            'total_prize' => $this->totalPrize,
            'currency' => $this->currency,
            'payouts_created' => $this->payoutsCreated,
            'total_payout_amount' => $this->totalPayoutAmount,
            'already_settled' => $this->alreadySettled,
            'mode' => $this->mode,
            'settled_at' => $this->settledAt,
            'status_counts' => $this->statusCounts(),
            'context' => $this->context,
        ];
    }
}
