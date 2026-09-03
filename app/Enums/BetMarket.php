<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The market FAMILY a bet is placed into: 3D, 2D or Run.
 *
 * WHY THIS IS A FAMILY AND NOT A SELLABLE MARKET
 * The project already declares its six sellable markets in config/lottery.php
 * under the 'markets' key: 3d_direct, 3d_tod, 2d_top, 2d_bottom, run_top and
 * run_bottom. Each of those entries is a composite of three independent ideas:
 *
 *   family        3D / 2D / Run                 -> this enum
 *   selection     direct / tod / run            -> App\Enums\BetSelectionType
 *   side          top / bottom                  -> App\Enums\BetSide
 *
 * Re-declaring all six composites as enum cases would hard-code the product
 * matrix into PHP and force a code change every time a market is added, and it
 * would also duplicate App\Enums\BetType, which already exists and is what the
 * bets.type column stores. This enum therefore models only the family, and the
 * composite is resolved back to the configured market key by resolveMarketKey().
 *
 * RELATIONSHIP TO App\Enums\BetType
 * BetType is the persistence vocabulary (bets.type: '2d', '3d', 'tod', 'run')
 * and it is untouched by Phase 4.1. Tod is a BetType but not a family: it is the
 * 3D family selected by permutation. betTypeFor() derives the BetType from the
 * configured market definition rather than guessing, so the two vocabularies can
 * never drift.
 *
 * NO RULES LIVE HERE
 * Digit counts, multipliers, result sources and match modes are all read from
 * configuration by the Phase 4.1 services. This enum only knows which
 * combinations are structurally meaningful.
 *
 * PHASE 4.2 ADDITION
 * Two additive methods were appended for the market rule engine:
 * expectedResultType() and usesPermutation(). No case was added, no case value
 * changed, no existing method was altered or removed and no signature changed, so
 * every Phase 4.1 caller keeps working exactly as before. Both new methods are
 * structural cross-checks, not a second rule source: the authoritative rule set is
 * still config('lottery.markets'), resolved by
 * App\Services\Betting\MarketRuleResolver, which uses expectedResultType() only to
 * detect a configuration that contradicts the product structure.
 */
enum BetMarket: string
{
    case ThreeD = '3d';
    case TwoD = '2d';
    case Run = 'run';

    /**
     * Human label for the family.
     */
    public function label(): string
    {
        return match ($this) {
            self::ThreeD => '3D',
            self::TwoD => '2D',
            self::Run => 'Run',
        };
    }

    /**
     * The selection mechanisms this family structurally supports.
     *
     * @return list<BetSelectionType>
     */
    public function selectionTypes(): array
    {
        return match ($this) {
            self::ThreeD => [BetSelectionType::Direct, BetSelectionType::Tod],
            self::TwoD => [BetSelectionType::Direct],
            self::Run => [BetSelectionType::Run],
        };
    }

    /**
     * The sides this family structurally supports.
     *
     * 3D is TOP only. The configured markets contain 3d_direct and 3d_tod, both
     * with position 'top', and there is no 3d_bottom market anywhere in the
     * project, so a 3D Bottom selection is refused rather than invented.
     *
     * @return list<BetSide>
     */
    public function sides(): array
    {
        return match ($this) {
            self::ThreeD => [BetSide::Top],
            self::TwoD => [BetSide::Top, BetSide::Bottom],
            self::Run => [BetSide::Top, BetSide::Bottom],
        };
    }

    public function supportsSide(BetSide $side): bool
    {
        return in_array($side, $this->sides(), true);
    }

    public function supportsSelectionType(BetSelectionType $selectionType): bool
    {
        return in_array($selectionType, $this->selectionTypes(), true);
    }

    /**
     * The default selection mechanism when a caller does not supply one.
     *
     * 3D defaults to Direct because Direct is the exact-order market; Tod must be
     * asked for explicitly so a permutation bet is never sold by accident.
     */
    public function defaultSelectionType(): BetSelectionType
    {
        return match ($this) {
            self::ThreeD => BetSelectionType::Direct,
            self::TwoD => BetSelectionType::Direct,
            self::Run => BetSelectionType::Run,
        };
    }

    /**
     * The only side available when a family has exactly one, otherwise null.
     */
    public function soleSide(): ?BetSide
    {
        $sides = $this->sides();

        return count($sides) === 1 ? $sides[0] : null;
    }

    /**
     * Resolve family + selection + side to the market key used in
     * config('lottery.markets').
     *
     * This is the single translation table between the domain vocabulary and the
     * configuration vocabulary. It is a declarative map, not a rule engine: it
     * returns null for a combination the project does not declare, and the
     * caller turns that into an UNSUPPORTED_MARKET rejection. Adding a market in
     * Phase 4.2 means adding one entry here and one entry in configuration.
     */
    public function resolveMarketKey(BetSelectionType $selectionType, BetSide $side): ?string
    {
        return self::marketKeyTable()[$this->value][$selectionType->value][$side->value] ?? null;
    }

    /**
     * Every configured market key this family can produce.
     *
     * @return list<string>
     */
    public function marketKeys(): array
    {
        $keys = [];

        foreach (self::marketKeyTable()[$this->value] ?? [] as $bySide) {
            foreach ($bySide as $marketKey) {
                $keys[] = $marketKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Every market key declared by any family, in declaration order.
     *
     * @return list<string>
     */
    public static function allMarketKeys(): array
    {
        $keys = [];

        foreach (self::cases() as $market) {
            foreach ($market->marketKeys() as $marketKey) {
                $keys[] = $marketKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The family a configured market key belongs to, or null when the key is not
     * one this enum declares.
     */
    public static function fromMarketKey(string $marketKey): ?self
    {
        foreach (self::cases() as $market) {
            if (in_array($marketKey, $market->marketKeys(), true)) {
                return $market;
            }
        }

        return null;
    }

    /**
     * The full triple a configured market key decomposes into.
     *
     * @return array{market: self, selection_type: BetSelectionType, side: BetSide}|null
     */
    public static function decomposeMarketKey(string $marketKey): ?array
    {
        foreach (self::marketKeyTable() as $family => $bySelection) {
            foreach ($bySelection as $selection => $bySide) {
                foreach ($bySide as $side => $candidate) {
                    if ($candidate !== $marketKey) {
                        continue;
                    }

                    $market = self::tryFrom((string) $family);
                    $selectionType = BetSelectionType::tryFrom((string) $selection);
                    $betSide = BetSide::tryFrom((string) $side);

                    if ($market === null || $selectionType === null || $betSide === null) {
                        return null;
                    }

                    return [
                        'market' => $market,
                        'selection_type' => $selectionType,
                        'side' => $betSide,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * The persistence bet type for a configured market key, read from the market
     * definition so it is never guessed.
     *
     * Returns null when the definition is absent or does not name a BetType this
     * project declares; the caller reports that rather than assuming one.
     *
     * @param  array<string, mixed>|null  $marketDefinition  config('lottery.markets.<key>')
     */
    public static function betTypeFor(?array $marketDefinition): ?BetType
    {
        $value = $marketDefinition['bet_type'] ?? null;

        return is_string($value) ? BetType::tryFrom($value) : null;
    }

    /**
     * The kind of drawn value this family is decided by, for a given side.
     *
     * Structural cross-check for the Phase 4.2 rule engine. Six markets are sold
     * but only three winning values exist:
     *
     *   3D family, top    -> three digit top   (3d_direct and 3d_tod)
     *   2D family, top    -> two digit top     (2d_top)
     *   2D family, bottom -> two digit bottom  (2d_bottom)
     *   Run family, top   -> three digit top   (run_top reads the 3 digit top)
     *   Run family, bottom-> two digit bottom  (run_bottom reads the 2 digit bottom)
     *
     * Returns null for a side the family does not sell, for example 3D bottom, so
     * the caller refuses rather than inventing a result source.
     */
    public function expectedResultType(BetSide $side): ?MarketResultType
    {
        if (! $this->supportsSide($side)) {
            return null;
        }

        return match ($this) {
            self::ThreeD => MarketResultType::ThreeDigitTop,
            self::TwoD => $side === BetSide::Bottom
                ? MarketResultType::TwoDigitBottom
                : MarketResultType::TwoDigitTop,
            self::Run => $side === BetSide::Bottom
                ? MarketResultType::TwoDigitBottom
                : MarketResultType::ThreeDigitTop,
        };
    }

    /**
     * Whether this family permutes the player's digits for a given selection type.
     *
     * True only for the 3D family selected as Tod, and those permutations are
     * unique permutations. The authoritative Phase 4.2 rule for Run is NO
     * PERMUTATION, so the Run family returns false for every selection type.
     */
    public function usesPermutation(BetSelectionType $selectionType): bool
    {
        if (! $this->supportsSelectionType($selectionType)) {
            return false;
        }

        return $this === self::ThreeD && $selectionType === BetSelectionType::Tod;
    }

    /**
     * family => selection type => side => configured market key.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private static function marketKeyTable(): array
    {
        return [
            self::ThreeD->value => [
                BetSelectionType::Direct->value => [
                    BetSide::Top->value => '3d_direct',
                ],
                BetSelectionType::Tod->value => [
                    BetSide::Top->value => '3d_tod',
                ],
            ],
            self::TwoD->value => [
                BetSelectionType::Direct->value => [
                    BetSide::Top->value => '2d_top',
                    BetSide::Bottom->value => '2d_bottom',
                ],
            ],
            self::Run->value => [
                BetSelectionType::Run->value => [
                    BetSide::Top->value => 'run_top',
                    BetSide::Bottom->value => 'run_bottom',
                ],
            ],
        ];
    }
}
