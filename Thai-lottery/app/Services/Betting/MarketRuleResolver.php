<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\MarketRuleData;
use App\Enums\BetMarket;
use App\Enums\BetSelectionType;
use App\Enums\BetSide;
use App\Enums\BetType;
use App\Enums\MarketResultType;
use App\Exceptions\InvalidMarketRuleException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Resolves the structured rule set of a sellable market from configuration.
 *
 * WHY A RESOLVER AND NOT A SWITCH STATEMENT
 * A Thai lottery market is seven independent decisions, not one label. Encoding
 * them in a single switch is how 3D TOD ends up sharing the exact match branch of
 * 3D DIRECT, or how Run inherits permutation from Tod. This resolver returns each
 * decision separately, in App\DTOs\MarketRuleData:
 *
 *   1. market            family, selection type and side
 *   2. selection         what the player picks
 *   3. permutation       whether digits permute, and whether uniquely
 *   4. digit length      exactly how many digits, leading zeros preserved
 *   5. result source     which drawn value decides it
 *   6. multiplier source WHERE the rate is read from, never the rate itself
 *   7. payout frequency  how many times a matched selection pays
 *
 * THE SIX MARKETS
 *   3d_direct  3 digits  exact          top    3 digit top    pays once
 *   3d_tod     3 digits  unique perms   top    3 digit top    pays once
 *   2d_top     2 digits  exact          top    2 digit top    pays once
 *   2d_bottom  2 digits  exact          bottom 2 digit bottom pays once
 *   run_top    1 digit   NO permutation top    3 digit top    pays once
 *   run_bottom 1 digit   NO permutation bottom 2 digit bottom pays once
 *
 * THE THREE RULES PHASE 4.1 COULD NOT DECLARE, NOW RESOLVED
 *   3D TOD combination rule: unique permutations of exactly three digits, one
 *   stake, one possible payout. 123 covers 6 shapes, 112 covers 3, 111 covers 1.
 *
 *   RUN digit rule: RUN TOP and RUN BOTTOM are ONE DIGIT selections. They are not
 *   two digit selections. Run Top is tested against the three digit top result and
 *   Run Bottom against the two digit bottom result, by containment.
 *
 *   RUN permutation rule: RUN = NO PERMUTATION. Permutations are never generated
 *   for a Run selection.
 *
 * These are operator style market rules. They are not an assertion that every
 * official Thai government lottery product uses identical online bookmaker rules,
 * and every payout rate stays configurable.
 *
 * NO MONETARY VALUE LEAVES THIS SERVICE
 * MarketRuleData carries multiplierSource, a configuration path such as
 * 'lottery.markets.run_top.payout_multiplier'. The rate itself belongs to
 * configuration and is read by App\Services\Betting\MarketPayoutService through the
 * existing App\Services\Betting\PayoutMultiplierService. This service therefore
 * cannot become a second, stale price list.
 *
 * SOURCE OF TRUTH
 * config('lottery.markets') only. No new configuration file, no new key, no
 * hard-coded digit count and no hard-coded rate. Where the legacy family-level
 * descriptors in App\Enums\BetType or config('lottery.types') disagree with a
 * market entry, the disagreement is REPORTED by legacyDivergences() rather than
 * resolved by preference.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No database access. Reading a drawn value is
 *   App\Services\Betting\MarketResultResolver's job.
 * - No match decision, no stake, no payout, no risk assessment.
 * - No wallet, ledger, bet, bet item, ticket or payout writes.
 */
class MarketRuleResolver
{
    /**
     * The authoritative market configuration root.
     */
    public const MARKET_SOURCE = 'lottery.markets';

    /**
     * Match modes this engine understands, as declared by
     * config('lottery.markets.<key>.match_mode').
     */
    public const MATCH_EXACT = 'exact';

    public const MATCH_PERMUTATION = 'permutation';

    public const MATCH_DIGIT_CONTAINS = 'digit_contains';

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * The full rule set of one market.
     *
     * @throws InvalidMarketRuleException
     */
    public function resolve(string $marketKey): MarketRuleData
    {
        $definition = $this->definition($marketKey);

        $triple = BetMarket::decomposeMarketKey($marketKey);

        if ($triple === null) {
            throw InvalidMarketRuleException::unknownMarket(
                $marketKey,
                implode(', ', BetMarket::allMarketKeys()),
            );
        }

        $market = $triple['market'];
        $selectionType = $triple['selection_type'];
        $side = $triple['side'];

        $betType = BetMarket::betTypeFor($definition);

        if (! $betType instanceof BetType) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'bet_type',
                'it does not name a bet type this project declares',
            );
        }

        $digits = $this->digitsFrom($definition, $marketKey);
        $matchMode = $this->matchModeFrom($definition, $marketKey);
        $resultType = $this->resultTypeFrom($definition, $marketKey, $market, $side);

        $permutationAllowed = $matchMode === self::MATCH_PERMUTATION;

        $this->assertStructurallyConsistent(
            $marketKey,
            $market,
            $selectionType,
            $side,
            $matchMode,
            $permutationAllowed,
        );

        return new MarketRuleData(
            marketKey: $marketKey,
            market: $market,
            side: $side,
            selectionType: $selectionType,
            betType: $betType,
            digits: $digits,
            permutationAllowed: $permutationAllowed,
            uniquePermutationOnly: $permutationAllowed,
            matchMode: $matchMode,
            resultType: $resultType,
            resultSource: $resultType->sourceKey(),
            multiplierSource: self::MARKET_SOURCE.'.'.$marketKey.'.payout_multiplier',
            paysOnce: true,
            enabled: true,
            notes: $this->notesFor($marketKey, $betType, $digits, $permutationAllowed),
        );
    }

    /**
     * The rule set, or null when the market cannot be resolved.
     */
    public function tryResolve(string $marketKey): ?MarketRuleData
    {
        try {
            return $this->resolve($marketKey);
        } catch (InvalidMarketRuleException) {
            return null;
        }
    }

    /**
     * Whether a market key resolves to a usable rule set.
     */
    public function supports(string $marketKey): bool
    {
        return $this->tryResolve($marketKey) instanceof MarketRuleData;
    }

    /**
     * Every configured market that resolves, keyed by market key.
     *
     * @return array<string, MarketRuleData>
     */
    public function all(): array
    {
        $rules = [];

        foreach (BetMarket::allMarketKeys() as $marketKey) {
            $rule = $this->tryResolve($marketKey);

            if ($rule instanceof MarketRuleData) {
                $rules[$marketKey] = $rule;
            }
        }

        return $rules;
    }

    /**
     * The rule set for a family, selection type and side combination.
     *
     * @throws InvalidMarketRuleException
     */
    public function forSelection(BetMarket $market, BetSelectionType $selectionType, BetSide $side): MarketRuleData
    {
        $marketKey = $market->resolveMarketKey($selectionType, $side);

        if ($marketKey === null) {
            throw InvalidMarketRuleException::unknownMarket(
                sprintf('%s/%s/%s', $market->value, $selectionType->value, $side->value),
                implode(', ', BetMarket::allMarketKeys()),
            );
        }

        return $this->resolve($marketKey);
    }

    /**
     * Digits a market requires, read from the authoritative source.
     *
     * @throws InvalidMarketRuleException
     */
    public function digitsFor(string $marketKey): int
    {
        return $this->resolve($marketKey)->digits();
    }

    /**
     * Whether a market permutes its selection.
     *
     * @throws InvalidMarketRuleException
     */
    public function permutationAllowed(string $marketKey): bool
    {
        return $this->resolve($marketKey)->allowsPermutation();
    }

    /**
     * Assert that a market permutes, for callers about to expand a selection.
     *
     * @throws InvalidMarketRuleException
     */
    public function assertPermutationAllowed(string $marketKey): MarketRuleData
    {
        $rule = $this->resolve($marketKey);

        if (! $rule->allowsPermutation()) {
            throw InvalidMarketRuleException::permutationNotAllowed($marketKey);
        }

        return $rule;
    }

    /**
     * Assert that a selection carries exactly the digits the market requires.
     *
     * The selection stays a string throughout, so '07' is two digits and '7' is
     * one, and the two are never conflated.
     *
     * @throws InvalidMarketRuleException
     */
    public function assertSelectionWidth(string $marketKey, string $selection): MarketRuleData
    {
        $rule = $this->resolve($marketKey);

        if (preg_match('/^[0-9]+$/', $selection) !== 1) {
            throw InvalidMarketRuleException::nonNumericSelection($marketKey, $selection);
        }

        if (! $rule->acceptsDigitWidth($selection)) {
            throw InvalidMarketRuleException::wrongDigitCount($marketKey, $selection, $rule->digits());
        }

        return $rule;
    }

    /**
     * The rule set of every market in report form.
     *
     * @return array<string, array<string, mixed>>
     */
    public function audit(): array
    {
        $audit = [];

        foreach (BetMarket::allMarketKeys() as $marketKey) {
            $rule = $this->tryResolve($marketKey);

            $audit[$marketKey] = $rule instanceof MarketRuleData
                ? $rule->toArray()
                : ['market_key' => $marketKey, 'resolved' => false];
        }

        return $audit;
    }

    /**
     * Places where a legacy family-level descriptor disagrees with a market rule.
     *
     * Reported, never silently resolved. After the Phase 4.2 compatibility fix to
     * App\Enums\BetType the digit and permutation entries are expected to be empty;
     * the Run payout entry is expected to remain, because one integer on a family
     * enum cannot express two market rates (run_top 3 and run_bottom 4) and the
     * authoritative rate source is configuration.
     *
     * @return array<string, array<string, scalar|null>>
     */
    public function legacyDivergences(): array
    {
        $divergences = [];

        foreach ($this->all() as $marketKey => $rule) {
            $betType = $rule->betType;

            if ($betType->digits() !== $rule->digits()) {
                $divergences[$marketKey.'.digits'] = [
                    'market_key' => $marketKey,
                    'authoritative' => $rule->digits(),
                    'legacy_enum' => $betType->digits(),
                    'legacy_source' => 'App\Enums\BetType::digits()',
                ];
            }

            if ($betType->allowsPermutation() !== $rule->allowsPermutation()) {
                $divergences[$marketKey.'.permutation'] = [
                    'market_key' => $marketKey,
                    'authoritative' => $rule->allowsPermutation(),
                    'legacy_enum' => $betType->allowsPermutation(),
                    'legacy_source' => 'App\Enums\BetType::allowsPermutation()',
                ];
            }

            $configuredRate = $this->config->get(self::MARKET_SOURCE.'.'.$marketKey.'.payout_multiplier');

            if (is_int($configuredRate) && $configuredRate !== $betType->payoutMultiplier()) {
                $divergences[$marketKey.'.payout_multiplier'] = [
                    'market_key' => $marketKey,
                    'authoritative' => $configuredRate,
                    'authoritative_source' => $rule->multiplierSource(),
                    'legacy_enum' => $betType->payoutMultiplier(),
                    'legacy_source' => 'App\Enums\BetType::payoutMultiplier()',
                    'note' => 'A family enum returns one integer and cannot express two market rates; '
                        .'configuration remains authoritative.',
                ];
            }
        }

        return $divergences;
    }

    /**
     * Guarantees this resolver makes, for the change report.
     *
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'single_source' => 'Rules are read from config(lottery.markets) only. No second rule source exists.',
            'no_money' => 'MarketRuleData carries a multiplier SOURCE PATH, never a rate.',
            'run_is_one_digit' => 'run_top and run_bottom resolve to exactly 1 digit.',
            'run_no_permutation' => 'run_top and run_bottom resolve to permutation_allowed = false.',
            'tod_unique' => '3d_tod resolves to permutation_allowed = true with unique permutations only.',
            'pays_once' => 'Every market resolves to pays_once = true and payout_frequency = 1.',
            'no_switch' => 'Market, selection, permutation, digits, result source, rate source and payout '
                .'frequency are seven separate fields, not one branch.',
            'configurable' => 'Payout rates remain operator configuration; nothing is hard-coded here.',
        ];
    }

    /**
     * The configuration block of a market, required to exist and be enabled.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidMarketRuleException
     */
    private function definition(string $marketKey): array
    {
        $definition = $this->config->get(self::MARKET_SOURCE.'.'.$marketKey);

        if (! is_array($definition) || $definition === []) {
            throw InvalidMarketRuleException::unknownMarket(
                $marketKey,
                implode(', ', BetMarket::allMarketKeys()),
            );
        }

        if (($definition['enabled'] ?? false) !== true) {
            throw InvalidMarketRuleException::disabledMarket($marketKey);
        }

        return $definition;
    }

    /**
     * The digit width declared for a market.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidMarketRuleException
     */
    private function digitsFrom(array $definition, string $marketKey): int
    {
        $digits = $definition['digits'] ?? null;

        if (! is_int($digits) || $digits < 1) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'digits',
                'it must be a positive integer',
            );
        }

        return $digits;
    }

    /**
     * The match mode declared for a market.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidMarketRuleException
     */
    private function matchModeFrom(array $definition, string $marketKey): string
    {
        $mode = $definition['match_mode'] ?? null;

        if (! is_string($mode) || ! in_array($mode, $this->supportedMatchModes(), true)) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'match_mode',
                sprintf('it must be one of: %s', implode(', ', $this->supportedMatchModes())),
            );
        }

        return $mode;
    }

    /**
     * The result type declared for a market, cross-checked against the structure.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidMarketRuleException
     */
    private function resultTypeFrom(
        array $definition,
        string $marketKey,
        BetMarket $market,
        BetSide $side,
    ): MarketResultType {
        $source = $definition['source'] ?? null;

        if (! is_string($source) || $source === '') {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'source',
                'it must name the drawn value that decides the market',
            );
        }

        $resultType = MarketResultType::fromSourceKey($source);

        if (! $resultType instanceof MarketResultType) {
            throw InvalidMarketRuleException::unknownResultSource(
                $marketKey,
                $source,
                implode(', ', MarketResultType::values()),
            );
        }

        $expected = $market->expectedResultType($side);

        if ($expected instanceof MarketResultType && $expected !== $resultType) {
            throw InvalidMarketRuleException::wrongMatchMode(
                $marketKey,
                $expected->value,
                $resultType->value,
            );
        }

        return $resultType;
    }

    /**
     * Refuse a configuration that contradicts the product structure.
     *
     * @throws InvalidMarketRuleException
     */
    private function assertStructurallyConsistent(
        string $marketKey,
        BetMarket $market,
        BetSelectionType $selectionType,
        BetSide $side,
        string $matchMode,
        bool $permutationAllowed,
    ): void {
        if (! $market->supportsSide($side) || ! $market->supportsSelectionType($selectionType)) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'market composition',
                'the family does not sell this selection type on this side',
            );
        }

        $structurallyPermutes = $market->usesPermutation($selectionType);

        if ($structurallyPermutes !== $permutationAllowed) {
            throw InvalidMarketRuleException::wrongMatchMode(
                $marketKey,
                $structurallyPermutes ? self::MATCH_PERMUTATION : $matchMode,
                $matchMode,
            );
        }

        if ($market === BetMarket::Run && $matchMode !== self::MATCH_DIGIT_CONTAINS) {
            throw InvalidMarketRuleException::wrongMatchMode(
                $marketKey,
                self::MATCH_DIGIT_CONTAINS,
                $matchMode,
            );
        }
    }

    /**
     * Human readable notes attached to a resolved rule.
     *
     * @return array<string, scalar|null>
     */
    private function notesFor(string $marketKey, BetType $betType, int $digits, bool $permutationAllowed): array
    {
        $notes = [
            'rule_source' => self::MARKET_SOURCE.'.'.$marketKey,
            'payout_frequency_rule' => 'A matched selection pays exactly once.',
        ];

        if ($permutationAllowed) {
            $notes['permutation_rule'] = 'Unique permutations only. The stake is the stake of the single '
                .'selection and is never multiplied by the permutation count.';
        } else {
            $notes['permutation_rule'] = 'No permutation is generated for this market.';
        }

        if ($betType === BetType::Run) {
            $notes['run_rule'] = sprintf(
                'Run is a %d digit selection tested for containment in the drawn result; repeated '
                .'occurrences still pay once.',
                $digits,
            );
        }

        return $notes;
    }

    /**
     * @return list<string>
     */
    private function supportedMatchModes(): array
    {
        return [self::MATCH_EXACT, self::MATCH_PERMUTATION, self::MATCH_DIGIT_CONTAINS];
    }
}
