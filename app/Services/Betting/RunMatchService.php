<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\MarketMatchResult;
use App\DTOs\MarketRuleData;
use App\Exceptions\InvalidMarketRuleException;
use App\ValueObjects\LotteryNumber;

/**
 * The RUN TOP and RUN BOTTOM match rule.
 *
 * THE RULE
 *   A Run selection is ONE DIGIT. It is not a two digit selection.
 *   RUN = NO PERMUTATION. Permutations are never generated for a Run selection.
 *
 *   RUN TOP wins when the selected digit appears anywhere in the drawn THREE digit
 *   top result:
 *     result 527, selection 2 -> WIN
 *     result 527, selection 8 -> LOSE
 *
 *   RUN BOTTOM wins when the selected digit appears anywhere in the drawn TWO digit
 *   bottom result:
 *     result 42, selection 4 -> WIN
 *     result 42, selection 7 -> LOSE
 *
 * REPEATED OCCURRENCES PAY ONCE
 *   result 222, selection 2 -> WIN ONCE. The payout is NOT multiplied by three.
 *   result 44,  selection 4 -> WIN ONCE. It does NOT pay twice.
 * occurrences() on the returned result reports how many times the digit occurred,
 * purely for reporting, while payoutCount() stays 1.
 *
 * MULTIPLIERS ARE NOT DECIDED HERE
 * Run Top and Run Bottom are configured at different rates (3 and 4 in this
 * project's authoritative configuration). This matcher deliberately knows no rate
 * at all: nothing numeric about money appears in this file. Pricing is
 * App\Services\Betting\MarketPayoutService, which reads the authoritative source
 * declared by config('lottery.payouts.multiplier_source').
 *
 * STRINGS ONLY
 * Containment is tested with a character by character string comparison over the
 * drawn value, not with strpos on a numeric value and not after any cast, so a
 * drawn '07' is the characters '0' and '7' and a selection of '0' wins on it.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * No payout calculation, no result lookup, no persistence, no risk, no permutation.
 */
class RunMatchService
{
    public const MARKET_KEY_TOP = 'run_top';

    public const MARKET_KEY_BOTTOM = 'run_bottom';

    public function __construct(private readonly MarketRuleResolver $rules) {}

    /**
     * Decide one Run selection against the drawn value of its side.
     *
     * @throws InvalidMarketRuleException
     */
    public function match(string $selection, string $winning, string $marketKey): MarketMatchResult
    {
        $rule = $this->assertRunMarket($marketKey);
        $this->rules->assertSelectionWidth($marketKey, $selection);

        $number = LotteryNumber::of($selection, $rule->digits(), $marketKey);
        $canonicalWinning = $this->canonicalWinning($rule, $winning);

        $occurrences = $this->countOccurrences($canonicalWinning, $number->value());

        $context = [
            'rule' => 'single digit containment in the drawn result',
            'selection_digits' => $rule->digits(),
            'result_digits' => $rule->resultType()->digits(),
            'permutation_allowed' => false,
            'side' => $rule->side->value,
            'result_source' => $rule->resultSource(),
            'repeat_rule' => 'A digit occurring more than once still pays exactly once.',
        ];

        return $occurrences > 0
            ? MarketMatchResult::won(
                $marketKey,
                $number->value(),
                $canonicalWinning,
                $number->value(),
                $rule->matchMode(),
                $rule->resultType(),
                null,
                $occurrences,
                $context,
            )
            : MarketMatchResult::lost(
                $marketKey,
                $number->value(),
                $canonicalWinning,
                $rule->matchMode(),
                $rule->resultType(),
                null,
                0,
                $context,
            );
    }

    /**
     * Decide a RUN TOP selection against the three digit top result.
     *
     * @throws InvalidMarketRuleException
     */
    public function matchTop(string $selection, string $winning): MarketMatchResult
    {
        return $this->match($selection, $winning, self::MARKET_KEY_TOP);
    }

    /**
     * Decide a RUN BOTTOM selection against the two digit bottom result.
     *
     * @throws InvalidMarketRuleException
     */
    public function matchBottom(string $selection, string $winning): MarketMatchResult
    {
        return $this->match($selection, $winning, self::MARKET_KEY_BOTTOM);
    }

    /**
     * Whether a Run selection would win.
     *
     * @throws InvalidMarketRuleException
     */
    public function matches(string $selection, string $winning, string $marketKey): bool
    {
        return $this->match($selection, $winning, $marketKey)->isMatched();
    }

    /**
     * How many digits a Run selection carries, from the authoritative rule.
     *
     * Exposed because this is one of the rules Phase 4.1 could not declare: the
     * answer is 1 for both run_top and run_bottom.
     *
     * @throws InvalidMarketRuleException
     */
    public function selectionDigits(string $marketKey): int
    {
        return $this->assertRunMarket($marketKey)->digits();
    }

    /**
     * Guarantees this matcher makes, for the change report.
     *
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'one_digit' => 'A Run selection is exactly 1 digit; a 2 digit input is refused.',
            'no_permutation' => 'No permutation is generated for a Run selection.',
            'top_source' => 'Run Top is tested against the 3 digit top result.',
            'bottom_source' => 'Run Bottom is tested against the 2 digit bottom result.',
            'pays_once' => '222 with selection 2 wins once; 44 with selection 4 wins once.',
            'no_rate' => 'No payout rate appears in this file; rates stay in configuration.',
            'no_money' => 'No stake, multiplier or payout arithmetic happens inside the matcher.',
        ];
    }

    /**
     * Count occurrences of a single digit character in the drawn value.
     *
     * Diagnostic only. A count above one never changes the payout.
     */
    private function countOccurrences(string $winning, string $digit): int
    {
        $occurrences = 0;

        foreach (str_split($winning) as $character) {
            if ($character === $digit) {
                $occurrences++;
            }
        }

        return $occurrences;
    }

    /**
     * Require a single digit containment market.
     *
     * @throws InvalidMarketRuleException
     */
    private function assertRunMarket(string $marketKey): MarketRuleData
    {
        $rule = $this->rules->resolve($marketKey);

        if (! $rule->isDigitContainment()) {
            throw InvalidMarketRuleException::wrongMatchMode(
                $marketKey,
                $rule->matchMode(),
                MarketRuleResolver::MATCH_DIGIT_CONTAINS,
            );
        }

        if ($rule->allowsPermutation()) {
            throw InvalidMarketRuleException::permutationNotAllowed($marketKey);
        }

        if ($rule->digits() !== 1) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'digits',
                'a Run selection is one digit; the Run matcher refuses any other width',
            );
        }

        return $rule;
    }

    /**
     * Validate the drawn value against the market's result type.
     *
     * @throws InvalidMarketRuleException
     */
    private function canonicalWinning(MarketRuleData $rule, string $winning): string
    {
        $expected = $rule->resultType()->digits();

        if (preg_match('/^[0-9]+$/', $winning) !== 1 || strlen($winning) !== $expected) {
            throw InvalidMarketRuleException::unusableWinningValue($rule->marketKey(), $winning, $expected);
        }

        return $winning;
    }
}
