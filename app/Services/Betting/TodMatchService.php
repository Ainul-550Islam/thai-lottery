<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\MarketMatchResult;
use App\DTOs\MarketRuleData;
use App\DTOs\TodPermutationResult;
use App\Exceptions\InvalidMarketRuleException;
use App\ValueObjects\LotteryNumber;

/**
 * The 3D TOD match rule.
 *
 * THE RULE
 *   Selection: exactly 3 digits. Order does NOT matter.
 *   The selection covers its UNIQUE permutations only:
 *     123 -> 6   112 -> 3   111 -> 1
 *   If the drawn three digit top result equals ANY one of those permutations, the
 *   selection WINS ONCE.
 *
 * THE PAYOUT RULE THIS SERVICE PROTECTS
 * A Tod selection is ONE selection with ONE stake and ONE possible payout.
 *   - The player's stake is the stake for the single 3D TOD selection. A stake of
 *     10.00 is 10.00. It is never read as 60.00 and never becomes six independent
 *     financial charges.
 *   - The payout is never multiplied by the number of permutations, and never by the
 *     number of matching permutations. payoutCount() on the returned result is 1
 *     when matched and 0 when not.
 *
 * MATCHING PROCEDURE, IN ORDER
 *   1. normalise the selected number against the market's declared digit width
 *   2. preserve leading zeros
 *   3. generate the unique permutations
 *   4. compare each permutation against the canonical winning result
 *   5. return ONE winning decision
 *
 * NO INTEGER SETS
 * Uniqueness is handled by App\Services\Betting\TodPermutationService, which
 * deduplicates on prefixed string keys. Nothing here builds a set whose keys would
 * be coerced to integers, so '007' is never turned into 7 and '099' is never turned
 * into 99.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No payout calculation inside the matcher. Pricing is
 *   App\Services\Betting\MarketPayoutService.
 * - No result lookup; the drawn value is supplied, normally by
 *   App\Services\Betting\MarketResultResolver.
 * - No wallet, ledger, bet, bet item, ticket or payout writes. No queries.
 */
class TodMatchService
{
    public const MARKET_KEY = '3d_tod';

    public function __construct(
        private readonly MarketRuleResolver $rules,
        private readonly TodPermutationService $permutations,
    ) {}

    /**
     * Decide one 3D TOD selection against the drawn three digit top result.
     *
     * @throws InvalidMarketRuleException
     */
    public function match(
        string $selection,
        string $winning,
        string $marketKey = self::MARKET_KEY,
    ): MarketMatchResult {
        $rule = $this->assertPermutationMarket($marketKey);
        $this->rules->assertSelectionWidth($marketKey, $selection);

        $number = LotteryNumber::of($selection, $rule->digits(), $marketKey);
        $canonicalWinning = $this->canonicalWinning($rule, $winning);

        $permutationResult = $this->permutations->permutations($number);

        $matchedValue = null;

        foreach ($permutationResult->values() as $permutation) {
            if ($permutation === $canonicalWinning) {
                $matchedValue = $permutation;

                break;
            }
        }

        $context = [
            'rule' => 'unique permutations, order does not matter',
            'digits' => $rule->digits(),
            'unique_permutation_only' => true,
            'factorial_count' => $permutationResult->factorialCount(),
            'duplicates_suppressed' => $permutationResult->duplicatesSuppressed(),
            'stake_semantics' => 'The stake is the stake of the single Tod selection and is never '
                .'multiplied by the permutation count.',
            'payout_semantics' => 'A match pays once regardless of how many permutations exist.',
            'leading_zeros_preserved' => true,
        ];

        return $matchedValue !== null
            ? MarketMatchResult::won(
                $marketKey,
                $number->value(),
                $canonicalWinning,
                $matchedValue,
                $rule->matchMode(),
                $rule->resultType(),
                $permutationResult->permutationCount(),
                null,
                $context,
            )
            : MarketMatchResult::lost(
                $marketKey,
                $number->value(),
                $canonicalWinning,
                $rule->matchMode(),
                $rule->resultType(),
                $permutationResult->permutationCount(),
                null,
                $context,
            );
    }

    /**
     * The unique permutations a Tod selection covers.
     *
     * Exposed so a caller can display coverage. The count is coverage only: it is
     * not a stake multiplier and not a payout multiplier.
     *
     * @throws InvalidMarketRuleException
     */
    public function permutationsFor(string $selection, string $marketKey = self::MARKET_KEY): TodPermutationResult
    {
        $rule = $this->assertPermutationMarket($marketKey);
        $this->rules->assertSelectionWidth($marketKey, $selection);

        return $this->permutations->permutations(
            LotteryNumber::of($selection, $rule->digits(), $marketKey),
        );
    }

    /**
     * How many distinct permutations a Tod selection covers.
     *
     * @throws InvalidMarketRuleException
     */
    public function permutationCount(string $selection, string $marketKey = self::MARKET_KEY): int
    {
        return $this->permutationsFor($selection, $marketKey)->permutationCount();
    }

    /**
     * Whether a Tod selection would win.
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
            'unique_permutations' => '123 covers 6 shapes, 112 covers 3, 111 covers 1. Duplicates are never '
                .'created.',
            'one_decision' => 'Exactly one win or lose decision is returned per selection.',
            'wins_once' => 'A match pays once; the payout is never multiplied by the permutation count.',
            'stake_untouched' => 'The stake is never multiplied by the permutation count and no independent '
                .'charges are created.',
            'leading_zeros' => "'007' and '099' keep their leading zeros through normalisation, permutation "
                .'and comparison.',
            'no_money' => 'No stake, multiplier or payout arithmetic happens inside the matcher.',
        ];
    }

    /**
     * Require a permutation market of three digits.
     *
     * @throws InvalidMarketRuleException
     */
    private function assertPermutationMarket(string $marketKey): MarketRuleData
    {
        $rule = $this->rules->assertPermutationAllowed($marketKey);

        if (! $rule->uniquePermutationOnly()) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'match_mode',
                'a permutation market must permute uniquely',
            );
        }

        if ($rule->digits() !== 3) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'digits',
                'the Tod matcher only serves three digit markets',
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
