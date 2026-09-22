<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

/**
 * The computed shape of a permutation: every arrangement, the price, and the
 * potential payout — before any money moves.
 *
 * QUOTE-ONLY, NEVER A PURCHASE
 * This object is what the preview endpoint returns and what the purchase path
 * re-derives server-side. It performs no purchase itself and carries no wallet,
 * bet or ticket reference, so it can never be mistaken for a committed result.
 *
 * EXACT-ARITHMETIC RULES
 * total_stake is arrangement_count × stake computed with bcmul; total_potential
 * is arrangement_count × per-arrangement potential payout. Counts are ints;
 * amounts are decimal strings.
 */
final readonly class PermutationResultData
{
    /**
     * @param  list<string>  $arrangements  canonical arrangement strings, sorted, unique
     */
    public function __construct(
        public int $drawId,
        public string $marketKey,
        public string $baseNumber,
        public array $arrangements,
        public int $arrangementCount,
        public string $stakePerArrangement,
        public string $totalStake,
        public string $potentialPayoutPerWin,
        public string $maxPotentialPayout,
        public string $currency,
    ) {
    }

    /**
     * The selections this permutation expands into, in BulkBetService's input shape.
     *
     * @return list<BulkBetSelectionData>
     */
    public function toSelections(): array
    {
        $selections = [];

        foreach ($this->arrangements as $arrangement) {
            $selections[] = new BulkBetSelectionData(
                marketKey: $this->marketKey,
                number: $arrangement,
                stake: $this->stakePerArrangement,
            );
        }

        return $selections;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'market' => $this->marketKey,
            'base_number' => $this->baseNumber,
            'arrangements' => $this->arrangements,
            'arrangement_count' => $this->arrangementCount,
            'stake_per_arrangement' => $this->stakePerArrangement,
            'total_stake' => $this->totalStake,
            'potential_payout_per_arrangement' => $this->potentialPayoutPerWin,
            'max_potential_payout' => $this->maxPotentialPayout,
            'currency' => $this->currency,
        ];
    }
}
