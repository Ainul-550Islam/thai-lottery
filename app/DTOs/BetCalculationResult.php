<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Services\Finance\Money;
use App\ValueObjects\BetAmount;
use App\ValueObjects\PayoutMultiplier;

/**
 * The result of pricing one selection.
 *
 * THE ONE FORMULA
 * potential_payout = stake x multiplier, computed exactly. The worked example from
 * the specification holds: stake 10.55 with multiplier 70.0000 gives 738.50 and not
 * 738.4999999 or 738.5000001, because the arithmetic runs through
 * App\Services\Risk\MoneyExposureCalculator on bcmath strings and never touches a
 * float.
 *
 * WHY ROUNDING IS RECORDED
 * A product such as 10.555 x 3 does not land on a whole satang. The liability is
 * then rounded UP to the currency scale, matching the Phase 3.1 exposure engine:
 * the house must never under-reserve against a payout it may owe. When that happens
 * $wasRounded is true and $exactProduct carries the untruncated product, so the
 * difference is visible and auditable rather than silent.
 *
 * NO SIDE EFFECT
 * This is a computed value. It debits nothing, locks nothing, posts nothing and
 * writes nothing. Holding one does not commit the house to anything - only a later
 * phase that actually creates a bet does that.
 */
final readonly class BetCalculationResult
{
    /**
     * @param  BetAmount  $stake  the exact stake priced
     * @param  PayoutMultiplier  $multiplier  the rate applied
     * @param  Money  $potentialPayout  stake x multiplier at the currency scale
     * @param  string  $exactProduct  the untruncated product at working precision
     * @param  bool  $wasRounded  whether the exact product needed rounding up
     * @param  array<string, scalar|null>  $context  diagnostic context only
     */
    public function __construct(
        public BetAmount $stake,
        public PayoutMultiplier $multiplier,
        public Money $potentialPayout,
        public string $exactProduct,
        public bool $wasRounded = false,
        public ?string $marketKey = null,
        public array $context = [],
    ) {
    }

    /**
     * The stake as an exact decimal string.
     */
    public function stakeAmount(): string
    {
        return $this->stake->amount();
    }

    /**
     * The applied multiplier as an exact decimal string at scale 4.
     */
    public function multiplierValue(): string
    {
        return $this->multiplier->value();
    }

    /**
     * The payable liability as an exact decimal string at the currency scale.
     *
     * This is the value a later phase would write to bets.potential_payout
     * DECIMAL(20,2).
     */
    public function potentialPayoutAmount(): string
    {
        return $this->potentialPayout->amount();
    }

    /**
     * The house's exposure beyond the stake it collected.
     *
     * potential_payout - stake. Reported for risk discussion only; it is not a
     * reservation and reserves nothing.
     */
    public function houseLiability(): Money
    {
        return $this->potentialPayout->minus($this->stake->money());
    }

    /**
     * The amount added by rounding up, as an exact decimal string.
     *
     * '0.00' when no rounding occurred.
     */
    public function roundingAdjustment(): string
    {
        $scale = $this->potentialPayout->scale();

        return bcsub($this->potentialPayout->amount(), bcadd($this->exactProduct, '0', $scale), $scale);
    }

    /**
     * Was the multiplier a whole number, meaning it could also be stored in the
     * narrower bet_items.payout_multiplier integer column.
     */
    public function multiplierFitsBetItemColumn(): bool
    {
        return $this->multiplier->fitsBetItemColumn();
    }

    /**
     * Total liability across several priced selections.
     *
     * Returns null for an empty set rather than inventing a zero, since a zero
     * liability and no selections are different statements.
     *
     * @param  iterable<self>  $results
     */
    public static function totalPotentialPayout(iterable $results): ?Money
    {
        $total = null;

        foreach ($results as $result) {
            $total = $total === null
                ? $result->potentialPayout
                : $total->plus($result->potentialPayout);
        }

        return $total;
    }

    /**
     * Total stake across several priced selections.
     *
     * @param  iterable<self>  $results
     */
    public static function totalStake(iterable $results): ?Money
    {
        $total = null;

        foreach ($results as $result) {
            $total = $total === null
                ? $result->stake->money()
                : $total->plus($result->stake->money());
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'market_key' => $this->marketKey,
            'stake' => $this->stake->amount(),
            'currency' => $this->stake->currency()->value,
            'multiplier' => $this->multiplier->value(),
            'potential_payout' => $this->potentialPayout->amount(),
            'exact_product' => $this->exactProduct,
            'was_rounded' => $this->wasRounded,
            'rounding_adjustment' => $this->roundingAdjustment(),
            'house_liability' => $this->houseLiability()->amount(),
            'multiplier_fits_bet_item_column' => $this->multiplierFitsBetItemColumn(),
            'context' => $this->context,
        ];
    }
}
