<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\MarketMatchResult;
use App\DTOs\MarketRuleData;
use App\Exceptions\InvalidMarketRuleException;
use App\ValueObjects\LotteryNumber;

/**
 * The 3D DIRECT match rule.
 *
 * THE RULE
 *   Selection: exactly 3 digits.
 *   Leading zeros MUST remain: '007' is a valid selection and stays '007'.
 *   Match: exact, position for position, against the three digit top result.
 *   No permutation: a selection of 123 LOSES against a result of 132.
 *   A matched selection pays once.
 *
 * WORKED CASES
 *   007 vs 007 -> WIN
 *   007 vs 700 -> LOSE   (same digits, wrong order)
 *   123 vs 132 -> LOSE
 *   123 vs 123 -> WIN
 *
 * STRINGS ONLY
 * The selection and the drawn value are compared with ===, on digit strings of
 * equal width. There is no intval(), no numeric comparison and no cast, so '007'
 * can never be reduced to 7 and then equal '7'.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No payout calculation. A match is priced by
 *   App\Services\Betting\MarketPayoutService.
 * - No result lookup. The drawn value is supplied by the caller, normally from
 *   App\Services\Betting\MarketResultResolver.
 * - No wallet, ledger, bet, bet item, ticket or payout writes. No queries.
 */
class ThreeDigitMatchService
{
    /**
     * The market this matcher serves.
     */
    public const MARKET_KEY = '3d_direct';

    public function __construct(private readonly MarketRuleResolver $rules) {}

    /**
     * Decide one 3D DIRECT selection against the drawn three digit top result.
     *
     * @throws InvalidMarketRuleException
     */
    public function match(
        string $selection,
        string $winning,
        string $marketKey = self::MARKET_KEY,
    ): MarketMatchResult {
        $rule = $this->assertExactMarket($marketKey);
        $this->rules->assertSelectionWidth($marketKey, $selection);

        $number = LotteryNumber::of($selection, $rule->digits(), $marketKey);
        $canonicalWinning = $this->canonicalWinning($rule, $winning);

        $matched = $number->value() === $canonicalWinning;

        $context = [
            'rule' => 'exact position match, no permutation',
            'digits' => $rule->digits(),
            'leading_zeros_preserved' => true,
        ];

        return $matched
            ? MarketMatchResult::won(
                $marketKey,
                $number->value(),
                $canonicalWinning,
                $canonicalWinning,
                $rule->matchMode(),
                $rule->resultType(),
                null,
                null,
                $context,
            )
            : MarketMatchResult::lost(
                $marketKey,
                $number->value(),
                $canonicalWinning,
                $rule->matchMode(),
                $rule->resultType(),
                null,
                null,
                $context,
            );
    }

    /**
     * Whether a selection would win, without building a result object.
     *
     * @throws InvalidMarketRuleException
     */
    public function matches(string $selection, string $winning, string $marketKey = self::MARKET_KEY): bool
    {
        return $this->match($selection, $winning, $marketKey)->isMatched();
    }

    /**
     * Guarantees this matcher makes, for the change report.
     *
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'exact_only' => 'Comparison is position for position with ===; 123 loses against 132.',
            'three_digits' => 'The selection must carry exactly 3 digits.',
            'leading_zeros' => "'007' stays '007'; it never becomes 7.",
            'pays_once' => 'A matched selection produces exactly one payout decision.',
            'no_money' => 'No stake, multiplier or payout is touched here.',
        ];
    }

    /**
     * Require the market to be an exact match market of the expected width.
     *
     * @throws InvalidMarketRuleException
     */
    private function assertExactMarket(string $marketKey): MarketRuleData
    {
        $rule = $this->rules->resolve($marketKey);

        if (! $rule->requiresExactMatch()) {
            throw InvalidMarketRuleException::wrongMatchMode(
                $marketKey,
                $rule->matchMode(),
                MarketRuleResolver::MATCH_EXACT,
            );
        }

        if ($rule->digits() !== 3) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'digits',
                'the 3D direct matcher only serves three digit markets',
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
