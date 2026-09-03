<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\BetMarket;
use App\Enums\BetSelectionType;
use App\Enums\BetSide;
use App\Enums\BetType;
use App\Exceptions\InvalidLotteryNumberException;
use App\Exceptions\RiskConfigurationException;
use App\Exceptions\UnsupportedBetMarketException;
use App\Services\Risk\NumberNormalizationService;
use App\ValueObjects\LotteryNumber;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Parses, validates and canonicalises lottery numbers per market.
 *
 * WHY THIS DELEGATES INSTEAD OF NORMALISING ITSELF
 * Phase 3.1 already ships App\Services\Risk\NumberNormalizationService, and the
 * risk engine judges exposure against the numbers that service produces. If this
 * class implemented a second, independent normaliser, a number could be accepted in
 * one canonical form by the betting layer and looked up in a different canonical
 * form by the risk layer, and the limit would silently apply to the wrong number.
 * Canonicalisation is therefore delegated. What this service adds is the market
 * dimension - Phase 3.1 works per BetType, while a bet is placed into a configured
 * market key - and the strict, no-padding parse a purchase path needs.
 *
 * THE PADDING POLICY IS THE PROJECT'S, NOT AN INVENTION
 * config('lottery.numbers.zero_pad') is true and Phase 3.1's normalize() zero-pads,
 * so this project HAS an explicit normalisation policy: a short input is left-padded
 * to the market's digit count. That policy is honoured by normalize(), which is what
 * makes '7' become '007' for a 3D market. It is deliberately NOT applied by
 * parseStrict(), which requires the caller to have already supplied the exact digit
 * count. Both behaviours exist because they answer different questions:
 *
 *   normalize()   - "what did this player most likely mean?"   (padding allowed)
 *   parseStrict() - "is this exactly a valid canonical number?" (padding refused)
 *
 * A purchase path should use parseStrict() on an already-canonical value so that a
 * client bug which drops a leading zero is reported rather than repaired.
 *
 * NEVER NUMERIC
 * No (int), no intval(), no (float), no ltrim of zeros anywhere. '007' is never 7 and
 * '099' is never 99.
 *
 * NO RUN OR TOD RULE IS INVENTED HERE
 * The digit rule for every market is read from configuration. Where configuration
 * and App\Enums\BetType disagree, or where a rule is simply absent, this service
 * reports the disagreement and refuses; it does not choose a value. See
 * digitRuleAudit(), todCombinationRule() and runRule().
 */
class LotteryNumberService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly NumberNormalizationService $numbers,
    ) {
    }

    /**
     * Parse a value that must ALREADY be canonical for its market.
     *
     * Nothing is padded and nothing is truncated. '7' for a 3D market is refused,
     * and so is '99' where '099' is required, exactly as the specification demands.
     * Surrounding whitespace is the only tolerated deviation.
     *
     * @throws InvalidLotteryNumberException
     * @throws UnsupportedBetMarketException
     */
    public function parseStrict(string $raw, string $marketKey): LotteryNumber
    {
        $digits = $this->digitsForMarket($marketKey);

        return LotteryNumber::of($raw, $digits, $marketKey);
    }

    /**
     * Parse strictly, returning null instead of throwing.
     *
     * @throws UnsupportedBetMarketException when the market itself is unusable
     */
    public function tryParseStrict(string $raw, string $marketKey): ?LotteryNumber
    {
        try {
            return $this->parseStrict($raw, $marketKey);
        } catch (InvalidLotteryNumberException) {
            return null;
        }
    }

    /**
     * Canonicalise a player-supplied value under the project's declared padding
     * policy.
     *
     * Delegates the padding itself to the Phase 3.1 normaliser so the betting layer
     * and the risk layer always agree on what a number is. Only used when the
     * configured policy allows padding; when config('lottery.numbers.zero_pad') is
     * false this behaves exactly like parseStrict().
     *
     * @throws InvalidLotteryNumberException
     * @throws UnsupportedBetMarketException
     */
    public function normalize(string $raw, string $marketKey): LotteryNumber
    {
        $digits = $this->digitsForMarket($marketKey);

        if (! $this->zeroPaddingIsPermitted()) {
            return LotteryNumber::of($raw, $digits, $marketKey);
        }

        $betType = $this->betTypeForMarket($marketKey);

        // The Phase 3.1 normaliser pads to the digit count of the BET TYPE, while a
        // bet is placed into a MARKET. Where the two digit counts disagree - which
        // they do for Tod and Run, since App\Enums\BetType::digits() contradicts
        // config('lottery.types.*.digits') - delegating blindly would canonicalise
        // to the wrong width. The counts are compared first and the disagreement is
        // reported instead of being resolved by preference.
        $typeDigits = $this->configuredDigitsForType($betType);

        if ($typeDigits !== null && $typeDigits !== $digits) {
            throw InvalidLotteryNumberException::digitRuleUnavailable(
                $marketKey,
                sprintf(
                    'config lottery.markets.%s.digits declares %d digit(s) but '
                    .'config lottery.types.%s.digits declares %d; the project does not state which '
                    .'governs, so no number can be canonicalised for this market',
                    $marketKey,
                    $digits,
                    $betType->value,
                    $typeDigits,
                ),
                ['market' => $marketKey, 'bet_type' => $betType->value],
            );
        }

        try {
            $canonical = $this->numbers->normalize($raw, $betType);
        } catch (RiskConfigurationException $exception) {
            throw InvalidLotteryNumberException::malformed(
                $raw,
                sprintf('the shared normaliser refused it (%s)', $exception->getMessage()),
                ['market' => $marketKey, 'bet_type' => $betType->value],
            );
        }

        return LotteryNumber::of($canonical, $digits, $marketKey);
    }

    /**
     * Canonicalise without throwing on a bad number.
     *
     * @throws UnsupportedBetMarketException when the market itself is unusable
     */
    public function tryNormalize(string $raw, string $marketKey): ?LotteryNumber
    {
        try {
            return $this->normalize($raw, $marketKey);
        } catch (InvalidLotteryNumberException) {
            return null;
        }
    }

    /**
     * Is the project configured to left-pad a short number.
     */
    public function zeroPaddingIsPermitted(): bool
    {
        return $this->config->get('lottery.numbers.zero_pad') === true;
    }

    /**
     * The digit count a market requires, read from its own configuration block.
     *
     * @throws UnsupportedBetMarketException when the market is absent or disabled
     * @throws InvalidLotteryNumberException when the market declares no usable
     *                                       digit count
     */
    public function digitsForMarket(string $marketKey): int
    {
        $definition = $this->marketDefinition($marketKey);
        $digits = $definition['digits'] ?? null;

        if (! is_int($digits) || $digits < 1) {
            throw InvalidLotteryNumberException::digitRuleUnavailable(
                $marketKey,
                'config lottery.markets.'.$marketKey.'.digits is missing or is not a positive integer',
            );
        }

        return $digits;
    }

    /**
     * Is a number valid for a market, with no exception raised either way.
     */
    public function isValidForMarket(string $raw, string $marketKey): bool
    {
        try {
            $this->parseStrict($raw, $marketKey);

            return true;
        } catch (InvalidLotteryNumberException | UnsupportedBetMarketException) {
            return false;
        }
    }

    /**
     * Is a number inside the configured range for its market's bet type.
     *
     * The range lives on the TYPE block (min_number / max_number) rather than on the
     * market block, so it is read from there. Comparison is string-based and
     * length-guarded, never numeric.
     */
    public function isWithinConfiguredRange(LotteryNumber $number, string $marketKey): bool
    {
        $betType = $this->betTypeForMarket($marketKey);

        try {
            $range = $this->numbers->range($betType);
        } catch (RiskConfigurationException) {
            return false;
        }

        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;

        if (! is_string($min) || ! is_string($max)) {
            return false;
        }

        if (strlen($min) !== $number->digits() || strlen($max) !== $number->digits()) {
            // A range expressed at a different width cannot be compared safely, and
            // widening it here would be inventing a rule.
            return false;
        }

        return strcmp($number->value(), $min) >= 0 && strcmp($number->value(), $max) <= 0;
    }

    /**
     * The BetType a market persists as, taken from the market definition.
     *
     * @throws UnsupportedBetMarketException when the definition names no known type
     */
    public function betTypeForMarket(string $marketKey): BetType
    {
        $definition = $this->marketDefinition($marketKey);
        $betType = BetMarket::betTypeFor($definition);

        if (! $betType instanceof BetType) {
            throw UnsupportedBetMarketException::notConfigured(
                $marketKey,
                'its configuration does not name a bet_type this project declares',
            );
        }

        return $betType;
    }

    /**
     * A round-trip proof that a canonical number survives storage unchanged.
     *
     * Reported by the validation matrix: a value written as '007' must read back as
     * '007', with the same digit count and the same string identity.
     *
     * @return array{input: string, canonical: string, restored: string, preserved: bool}
     */
    public function roundTrip(string $raw, string $marketKey): array
    {
        $canonical = $this->parseStrict($raw, $marketKey);
        $restored = LotteryNumber::fromCanonical($canonical->toDatabase());

        return [
            'input' => $raw,
            'canonical' => $canonical->value(),
            'restored' => $restored->value(),
            'preserved' => $canonical->equals($restored) && $restored->value() === $raw,
        ];
    }

    /**
     * Every declared source of a digit rule for a market, with agreement flagged.
     *
     * This is the audit the specification asks for. It reports what the project
     * actually says and whether the sources agree; it never picks a winner.
     *
     * @return array{
     *     market: string,
     *     market_digits: int|null,
     *     bet_type: string|null,
     *     type_digits: int|null,
     *     enum_digits: int|null,
     *     sources: array<string, int|null>,
     *     agrees: bool,
     *     specification_required: bool,
     *     note: string
     * }
     */
    public function digitRuleAudit(string $marketKey): array
    {
        $definition = $this->config->get('lottery.markets.'.$marketKey);
        $definition = is_array($definition) ? $definition : null;

        $marketDigits = is_int($definition['digits'] ?? null) ? $definition['digits'] : null;
        $betType = BetMarket::betTypeFor($definition);
        $typeDigits = $betType instanceof BetType ? $this->configuredDigitsForType($betType) : null;
        $enumDigits = $betType instanceof BetType ? $betType->digits() : null;

        $declared = array_values(array_filter(
            [$marketDigits, $typeDigits, $enumDigits],
            static fn (?int $value): bool => $value !== null,
        ));

        $agrees = $declared !== [] && count(array_unique($declared)) === 1;

        $note = match (true) {
            $declared === [] => 'SPECIFICATION REQUIRED: no digit rule is declared for this market.',
            $agrees => 'All declared sources agree.',
            $marketDigits !== null && $typeDigits !== null && $marketDigits !== $typeDigits => sprintf(
                'SPECIFICATION REQUIRED: config lottery.markets.%s.digits (%d) contradicts '
                .'config lottery.types.%s.digits (%d).',
                $marketKey,
                $marketDigits,
                (string) $betType?->value,
                $typeDigits,
            ),
            default => sprintf(
                'App\\Enums\\BetType::digits() returns %s for %s while configuration declares %s. '
                .'The enum is outside the Phase 4.1 file scope and has NOT been changed; '
                .'SPECIFICATION REQUIRED to reconcile it.',
                var_export($enumDigits, true),
                (string) $betType?->value,
                var_export($marketDigits ?? $typeDigits, true),
            ),
        };

        return [
            'market' => $marketKey,
            'market_digits' => $marketDigits,
            'bet_type' => $betType?->value,
            'type_digits' => $typeDigits,
            'enum_digits' => $enumDigits,
            'sources' => [
                'config lottery.markets.'.$marketKey.'.digits' => $marketDigits,
                'config lottery.types.'.((string) $betType?->value).'.digits' => $typeDigits,
                'App\\Enums\\BetType::digits()' => $enumDigits,
            ],
            'agrees' => $agrees,
            'specification_required' => ! $agrees,
            'note' => $note,
        ];
    }

    /**
     * The digit-rule audit for every declared market.
     *
     * @return array<string, array<string, mixed>>
     */
    public function digitRuleAuditAll(): array
    {
        $audit = [];

        foreach (BetMarket::allMarketKeys() as $marketKey) {
            $audit[$marketKey] = $this->digitRuleAudit($marketKey);
        }

        return $audit;
    }

    /**
     * What the project actually declares about the 3D Tod combination rule.
     *
     * WHAT IS DECLARED
     * That Tod matches without regard to order: config('lottery.markets.3d_tod')
     * carries match_mode 'permutation' and config('lottery.types.tod') carries
     * allows_permutation true and requires_exact_order false.
     *
     * WHAT IS NOT DECLARED
     * Whether a Tod selection materialises as ONE bet item matched order-insensitively
     * or as SEVERAL bet items, one per arrangement; and if several, how a repeated
     * digit is handled - '112' has 3 distinct arrangements, not 6 - and whether the
     * stake is per arrangement or divided across them. Those choices change what the
     * player pays and what the house owes, so none of them is assumed here.
     *
     * Nothing in this method generates ABC, ACB, BAC, BCA, CAB or CBA.
     *
     * @return array{
     *     market: string,
     *     match_mode: string|null,
     *     allows_permutation: bool|null,
     *     requires_exact_order: bool|null,
     *     combination_rule: string,
     *     specification_required: bool,
     *     undeclared: list<string>
     * }
     */
    public function todCombinationRule(): array
    {
        $marketKey = '3d_tod';
        $market = $this->config->get('lottery.markets.'.$marketKey);
        $market = is_array($market) ? $market : [];
        $type = $this->config->get('lottery.types.tod');
        $type = is_array($type) ? $type : [];

        $matchMode = is_string($market['match_mode'] ?? null) ? $market['match_mode'] : null;
        $allowsPermutation = is_bool($type['allows_permutation'] ?? null) ? $type['allows_permutation'] : null;
        $requiresExactOrder = is_bool($type['requires_exact_order'] ?? null)
            ? $type['requires_exact_order']
            : null;

        return [
            'market' => $marketKey,
            'match_mode' => $matchMode,
            'allows_permutation' => $allowsPermutation,
            'requires_exact_order' => $requiresExactOrder,
            'combination_rule' => 'SPECIFICATION REQUIRED',
            'specification_required' => true,
            'undeclared' => [
                'whether a Tod selection expands into one bet item or one per arrangement',
                'how many arrangements a number with a repeated digit produces for pricing',
                'whether the stake is charged per arrangement or divided across arrangements',
            ],
        ];
    }

    /**
     * What the project actually declares about the Run market.
     *
     * The digit rule is declared consistently by configuration (1 digit) but is
     * contradicted by App\Enums\BetType::digits(), which returns 2 for Run. The
     * result mapping is declared per side. The multiplier is contradictory across
     * three declared sources and is reported by
     * App\Services\Betting\PayoutMultiplierService rather than resolved here.
     *
     * @return array<string, mixed>
     */
    public function runRule(): array
    {
        $topAudit = $this->digitRuleAudit('run_top');
        $bottomAudit = $this->digitRuleAudit('run_bottom');

        $top = $this->config->get('lottery.markets.run_top');
        $top = is_array($top) ? $top : [];
        $bottom = $this->config->get('lottery.markets.run_bottom');
        $bottom = is_array($bottom) ? $bottom : [];

        return [
            'digits' => [
                'config lottery.types.run.digits' => $this->configuredDigitsForType(BetType::Run),
                'config lottery.markets.run_top.digits' => $top['digits'] ?? null,
                'config lottery.markets.run_bottom.digits' => $bottom['digits'] ?? null,
                'App\\Enums\\BetType::digits()' => BetType::Run->digits(),
                'agrees' => $topAudit['agrees'] && $bottomAudit['agrees'],
            ],
            'mapping' => [
                'run_top' => [
                    'source' => $top['source'] ?? null,
                    'match_mode' => $top['match_mode'] ?? null,
                    'position' => $top['position'] ?? null,
                ],
                'run_bottom' => [
                    'source' => $bottom['source'] ?? null,
                    'match_mode' => $bottom['match_mode'] ?? null,
                    'position' => $bottom['position'] ?? null,
                ],
            ],
            'permutation' => [
                'config lottery.types.run.allows_permutation' => $this->config
                    ->get('lottery.types.run.allows_permutation'),
                'App\\Enums\\BetType::allowsPermutation()' => BetType::Run->allowsPermutation(),
                'agrees' => $this->config->get('lottery.types.run.allows_permutation')
                    === BetType::Run->allowsPermutation(),
            ],
            'multiplier' => 'see App\\Services\\Betting\\PayoutMultiplierService::multiplierAudit()',
            'specification_required' => ! ($topAudit['agrees'] && $bottomAudit['agrees']),
        ];
    }

    /**
     * The result source a market is settled from, as declared.
     *
     * Reported so the mapping audit can name it. This service does not read a draw
     * result and does not settle anything.
     *
     * @return array{market: string, side: string|null, source: string|null, match_mode: string|null, storage: string}
     */
    public function resultMapping(string $marketKey): array
    {
        $definition = $this->config->get('lottery.markets.'.$marketKey);
        $definition = is_array($definition) ? $definition : [];

        $side = BetSide::fromPosition(
            is_string($definition['position'] ?? null) ? $definition['position'] : null,
        );

        $source = is_string($definition['source'] ?? null) ? $definition['source'] : null;

        return [
            'market' => $marketKey,
            'side' => $side?->value,
            'source' => $source,
            'match_mode' => is_string($definition['match_mode'] ?? null) ? $definition['match_mode'] : null,
            'storage' => $source === 'bottom_two'
                ? sprintf(
                    'draw_results.metadata[%s] - there is no dedicated bottom_two column '
                    .'(schema limitation)',
                    var_export($this->config->get('lottery.results.bottom_two_metadata_key'), true),
                )
                : 'draw_results.first_prize',
        ];
    }

    /**
     * Does a selection type require a rule this project has not declared.
     */
    public function selectionTypeIsFullySpecified(BetSelectionType $selectionType): bool
    {
        return ! $selectionType->requiresUnspecifiedCombinationRule();
    }

    /**
     * The digit count configuration declares for a bet type, or null when unusable.
     */
    private function configuredDigitsForType(BetType $betType): ?int
    {
        try {
            return $this->numbers->digitsFor($betType);
        } catch (RiskConfigurationException) {
            return null;
        }
    }

    /**
     * The configuration block for a market, requiring it to exist and be enabled.
     *
     * @return array<string, mixed>
     *
     * @throws UnsupportedBetMarketException
     */
    private function marketDefinition(string $marketKey): array
    {
        $definition = $this->config->get('lottery.markets.'.$marketKey);

        if (! is_array($definition) || $definition === []) {
            throw UnsupportedBetMarketException::notConfigured(
                $marketKey,
                'config lottery.markets.'.$marketKey.' is absent',
            );
        }

        if (($definition['enabled'] ?? false) !== true) {
            throw UnsupportedBetMarketException::notConfigured($marketKey, 'it is disabled in configuration');
        }

        return $definition;
    }
}
