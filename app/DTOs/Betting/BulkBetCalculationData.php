<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

/**
 * The read-only money preview of a bulk purchase — the "quote" before the player
 * confirms.
 *
 * WHY A SEPARATE OBJECT INSTEAD OF RETURNING RAW SUMS
 * Every total here is produced by bcmath addition over exact decimal strings, and
 * the object carries the per-selection breakdown together with the totals so the
 * client can render one receipt. No JSON float is ever produced: every amount
 * stays a string end to end, which is the whole reason the quote is trustworthy
 * as the basis of a purchase confirmation.
 *
 * READ-ONLY GUARANTEE
 * Building this object touches no money, reserves no risk capacity and writes no
 * row: it reads configuration multipliers and the request, nothing else.
 */
final readonly class BulkBetCalculationData
{
    /**
     * @param  int  $drawId  the draw the quote is for
     * @param  list<array{market: string, number: string, stake: string, multiplier: string, potential_payout: string}>  $items  per-selection quote detail
     * @param  string  $totalStake  exact decimal total of every stake
     * @param  string  $totalPotentialPayout  exact decimal total of every potential payout
     * @param  string  $currency  ISO 4217 code
     * @param  list<string>  $refusals  selections that cannot be quoted, as reason strings; quoted items and refusals never overlap
     */
    public function __construct(
        public int $drawId,
        public array $items,
        public string $totalStake,
        public string $totalPotentialPayout,
        public string $currency,
        public array $refusals = [],
    ) {
    }

    public function selectionCount(): int
    {
        return count($this->items);
    }

    public function hasRefusals(): bool
    {
        return $this->refusals !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'selection_count' => $this->selectionCount(),
            'items' => $this->items,
            'total_stake' => $this->totalStake,
            'total_potential_payout' => $this->totalPotentialPayout,
            'currency' => $this->currency,
            'refusals' => $this->refusals,
        ];
    }
}
