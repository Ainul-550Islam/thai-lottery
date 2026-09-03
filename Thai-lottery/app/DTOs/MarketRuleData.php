<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BetMarket;
use App\Enums\BetSelectionType;
use App\Enums\BetSide;
use App\Enums\BetType;
use App\Enums\MarketResultType;

/**
 * The rule set of one sellable market, resolved from configuration.
 *
 * Produced by App\Services\Betting\MarketRuleResolver. It separates the seven
 * things that a Thai lottery market actually is, instead of hiding them inside one
 * switch statement:
 *
 *   1. market          which market is being sold (market key, family, side)
 *   2. selection       what the player picks (selection type)
 *   3. permutation     whether the digits permute, and whether uniquely
 *   4. digit length    exactly how many digits the selection carries
 *   5. result source   which drawn value decides it
 *   6. multiplier      where the payout rate is read from, as a source path
 *   7. payout frequency how many times a matched selection pays
 *
 * The six markets resolve as:
 *
 *   3d_direct  3 digits  exact           top    3 digit top     pays once
 *   3d_tod     3 digits  unique perms    top    3 digit top     pays once
 *   2d_top     2 digits  exact           top    2 digit top     pays once
 *   2d_bottom  2 digits  exact           bottom 2 digit bottom  pays once
 *   run_top    1 digit   no permutation  top    3 digit top     pays once
 *   run_bottom 1 digit   no permutation  bottom 2 digit bottom  pays once
 *
 * These are operator style market rules and payout rates stay configurable. They
 * are not a claim that every official Thai government lottery product settles the
 * same way.
 *
 * NO MONEY IN THIS OBJECT
 * multiplierSource is a configuration path such as
 * 'lottery.markets.run_top.payout_multiplier'. The rate itself is deliberately
 * absent: monetary values belong to configuration and are read through
 * App\Services\Betting\MarketPayoutService, so a rule object can never become a
 * second, stale price list.
 */
final readonly class MarketRuleData
{
    /**
     * @param  array<string, scalar|null>  $notes
     */
    public function __construct(
        public string $marketKey,
        public BetMarket $market,
        public BetSide $side,
        public BetSelectionType $selectionType,
        public BetType $betType,
        public int $digits,
        public bool $permutationAllowed,
        public bool $uniquePermutationOnly,
        public string $matchMode,
        public MarketResultType $resultType,
        public string $resultSource,
        public string $multiplierSource,
        public bool $paysOnce,
        public bool $enabled,
        public array $notes = [],
    ) {}

    public function marketKey(): string
    {
        return $this->marketKey;
    }

    /**
     * Exactly how many digits a selection in this market carries.
     */
    public function digits(): int
    {
        return $this->digits;
    }

    /**
     * Whether permutations of the selection are generated.
     *
     * True for 3d_tod only. Run is explicitly NO PERMUTATION.
     */
    public function allowsPermutation(): bool
    {
        return $this->permutationAllowed;
    }

    /**
     * Whether only distinct permutations are generated.
     *
     * Always true where permutation is allowed: 112 yields 3 shapes, not 6.
     */
    public function uniquePermutationOnly(): bool
    {
        return $this->uniquePermutationOnly;
    }

    /**
     * Whether the drawn value must equal the selection position for position.
     */
    public function requiresExactMatch(): bool
    {
        return $this->matchMode === 'exact';
    }

    /**
     * Whether the selection is a single digit tested for containment.
     */
    public function isDigitContainment(): bool
    {
        return $this->matchMode === 'digit_contains';
    }

    /**
     * The comparison rule: exact, permutation or digit_contains.
     */
    public function matchMode(): string
    {
        return $this->matchMode;
    }

    public function resultType(): MarketResultType
    {
        return $this->resultType;
    }

    /**
     * The configured source key that names the drawn value, for example
     * 'first_prize_last_three' or 'bottom_two'.
     */
    public function resultSource(): string
    {
        return $this->resultSource;
    }

    /**
     * Configuration path where the authoritative payout rate is declared.
     *
     * A path, never an amount.
     */
    public function multiplierSource(): string
    {
        return $this->multiplierSource;
    }

    /**
     * How many payouts a matched selection produces. Always exactly one.
     */
    public function payoutFrequency(): int
    {
        return 1;
    }

    public function paysOnce(): bool
    {
        return $this->paysOnce;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function isBottomMarket(): bool
    {
        return $this->side === BetSide::Bottom;
    }

    /**
     * Whether a given digit string has the width this market requires.
     */
    public function acceptsDigitWidth(string $selection): bool
    {
        return strlen($selection) === $this->digits;
    }

    /**
     * One line description used by reports and admin screens.
     */
    public function describe(): string
    {
        return sprintf(
            '%s: %d digit(s), %s, decided by %s, pays once, rate from %s',
            $this->marketKey,
            $this->digits,
            $this->permutationAllowed ? 'unique permutations' : 'no permutation',
            $this->resultType->value,
            $this->multiplierSource,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return array{market_key: string, market: string, side: string, selection_type: string, bet_type: string, digits: int, permutation_allowed: bool, unique_permutation_only: bool, match_mode: string, result_type: string, result_source: string, multiplier_source: string, pays_once: bool, payout_frequency: int, enabled: bool, notes: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'market_key' => $this->marketKey,
            'market' => $this->market->value,
            'side' => $this->side->value,
            'selection_type' => $this->selectionType->value,
            'bet_type' => $this->betType->value,
            'digits' => $this->digits,
            'permutation_allowed' => $this->permutationAllowed,
            'unique_permutation_only' => $this->uniquePermutationOnly,
            'match_mode' => $this->matchMode,
            'result_type' => $this->resultType->value,
            'result_source' => $this->resultSource,
            'multiplier_source' => $this->multiplierSource,
            'pays_once' => $this->paysOnce,
            'payout_frequency' => $this->payoutFrequency(),
            'enabled' => $this->enabled,
            'notes' => $this->notes,
        ];
    }
}
