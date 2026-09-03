<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\MarketMatchResult;
use App\DTOs\MarketRuleData;
use App\Enums\MarketResultType;
use App\Exceptions\InvalidMarketRuleException;
use App\ValueObjects\LotteryNumber;

/**
 * The 2D TOP and 2D BOTTOM match rule.
 *
 * THE RULE
 *   Selection: exactly 2 digits. '07' is a valid selection and stays '07'.
 *   2D TOP is decided by the last two digits of the first prize.
 *   2D BOTTOM is decided by the separately drawn two digit bottom number.
 *   Match: exact only. No permutation.
 *   A matched selection pays once.
 *
 * WORKED CASES
 *   07 vs 07 -> WIN
 *   07 vs 70 -> LOSE   (same digits, wrong order)
 *   09 vs 09 -> WIN
 *
 * WHICH RESULT IS USED
 * The result source is not chosen here. Each market declares it in
 * config('lottery.markets.<key>.source') and
 * App\Services\Betting\MarketRuleResolver turns that into a
 * App\Enums\MarketResultType. This matcher only refuses a drawn value whose width
 * does not match the declared result type, so a three digit top value can never be
 * quietly compared against a two digit selection.
 *
 * BOTTOM RESULT AND THE SCHEMA
 * The bottom number has no dedicated column in draw_results. It is read from the
 * metadata JSON by App\Services\Betting\MarketResultResolver, which throws
 * App\Exceptions\MarketResultUnavailableException when it is absent. No migration is
 * created here, no column is invented and no value is faked.
 *
 * STRINGS ONLY
 * Comparison is === on digit strings, so '07' never becomes 7 and therefore never
 * equals '7'.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * No payout calculation, no result lookup, no persistence, no risk.
 */
class TwoDigitMatchService
{
    public const MARKET_KEY_TOP = '2d_top';

    public const MARKET_KEY_BOTTOM = '2d_bottom';

    public function __construct(private readonly MarketRuleResolver $rules) {}

    /**
     * Decide one 2D selection against the drawn two digit value of its side.
     *
     * @throws InvalidMarketRuleException
     */
    public function match(string $selection, string $winning, string $marketKey): MarketMatchResult
    {
        $rule = $this->assertTwoDigitExactMarket($marketKey);
        $this->rules->assertSelectionWidth($marketKey, $selection);

        $number = LotteryNumber::of($selection, $rule->digits(), $marketKey);
        $canonicalWinning = $this->canonicalWinning($rule, $winning);

        $matched = $number->value() === $canonicalWinning;

        $context = [
            'rule' => 'exact position match, no permutation',
            'digits' => $rule->digits(),
            'side' => $rule->side->value,
            'result_source' => $rule->resultSource(),
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
     * Decide a 2D TOP selection.
     *
     * @throws InvalidMarketRuleException
     */
    public function matchTop(string $selection, string $winning): MarketMatchResult
    {
        return $this->match($selection, $winning, self::MARKET_KEY_TOP);
    }

    /**
     * Decide a 2D BOTTOM selection.
     *
     * @throws InvalidMarketRuleException
     */
    public function matchBottom(string $selection, string $winning): MarketMatchResult
    {
        return $this->match($selection, $winning, self::MARKET_KEY_BOTTOM);
    }

    /**
     * Whether a selection would win.
     *
     * @throws InvalidMarketRuleException
     */
    public function matches(string $selection, string $winning, string $marketKey): bool
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
            'exact_only' => 'Comparison is position for position with ===; 07 loses against 70.',
            'two_digits' => 'The selection must carry exactly 2 digits.',
            'leading_zeros' => "'07' stays '07'; it never becomes 7.",
            'declared_source' => 'The drawn value comes from the source declared by configuration for the '
                .'market, top or bottom; the matcher never picks one.',
            'pays_once' => 'A matched selection produces exactly one payout decision.',
            'no_money' => 'No stake, multiplier or payout is touched here.',
        ];
    }

    /**
     * Require a two digit exact match market decided by a two digit value.
     *
     * @throws InvalidMarketRuleException
     */
    private function assertTwoDigitExactMarket(string $marketKey): MarketRuleData
    {
        $rule = $this->rules->resolve($marketKey);

        if (! $rule->requiresExactMatch()) {
            throw InvalidMarketRuleException::wrongMatchMode(
                $marketKey,
                $rule->matchMode(),
                MarketRuleResolver::MATCH_EXACT,
            );
        }

        if ($rule->digits() !== 2) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'digits',
                'the 2D matcher only serves two digit markets',
            );
        }

        $resultType = $rule->resultType();

        if ($resultType !== MarketResultType::TwoDigitTop && $resultType !== MarketResultType::TwoDigitBottom) {
            throw InvalidMarketRuleException::unknownResultSource(
                $marketKey,
                $resultType->value,
                implode(', ', [
                    MarketResultType::TwoDigitTop->value,
                    MarketResultType::TwoDigitBottom->value,
                ]),
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
