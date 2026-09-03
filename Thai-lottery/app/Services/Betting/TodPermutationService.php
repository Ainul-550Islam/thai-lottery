<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\TodPermutationResult;
use App\Exceptions\InvalidMarketRuleException;
use App\ValueObjects\LotteryNumber;

/**
 * The reusable unique permutation engine for 3D TOD.
 *
 * WHAT A TOD SELECTION IS
 * A 3D TOD selection is exactly three digits whose ORDER DOES NOT MATTER. The
 * selection covers the distinct arrangements of those digits:
 *
 *   123 -> 6   123 132 213 231 312 321
 *   112 -> 3   112 121 211
 *   111 -> 1   111
 *
 * DUPLICATE PERMUTATIONS MUST NOT BE CREATED. A naive 3! expansion produces six
 * entries for 112, three of which are copies. This engine deduplicates on the
 * digit STRING, so the count is the count of distinct winning shapes.
 *
 * WHAT THE COUNT IS NOT
 * The permutation count is a coverage figure. It is not a stake multiplier and not
 * a payout multiplier:
 *
 *   - A stake of 10.00 on 123 TOD is a stake of 10.00. It is NOT 60.00 and it does
 *     NOT become six independent financial charges.
 *   - A matched Tod selection pays ONCE, at stake x the configured Tod rate, no
 *     matter how many of its permutations exist.
 *
 * STRINGS ONLY, NEVER INTEGERS
 * Every value here is a digit string. There is no intval(), no floatval(), no
 * array_map('intval', ...), no (int) cast and no arithmetic on the digits. Uniqueness
 * is tracked in a map whose keys are PREFIXED ('p' . $candidate) precisely because
 * a bare PHP array key of '123' would be silently converted to the integer 123
 * while '007' would stay a string, which is exactly the class of bug that turns 007
 * into 7. Sorting uses SORT_STRING for the same reason.
 *
 * DETERMINISM
 * The returned list is always sorted ascending as strings, so the same selection
 * always yields byte-identical output. Nothing depends on hash order.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No match decision (App\Services\Betting\TodMatchService).
 * - No money of any kind (App\Services\Betting\MarketPayoutService).
 * - No configuration reads: digit width comes from the LotteryNumber it is given,
 *   which was itself parsed against config('lottery.markets.<key>.digits').
 * - No wallet, ledger, bet, bet item, ticket or payout writes. No queries at all.
 */
class TodPermutationService
{
    /**
     * The market key this engine serves, used only for error messages.
     */
    public const TOD_MARKET_KEY = '3d_tod';

    /**
     * Refuse selections wider than this, so a pathological input cannot generate a
     * factorial explosion. Tod is three digits, so this ceiling is never reached in
     * normal operation; it exists because the engine is reusable.
     */
    public const MAX_PERMUTABLE_DIGITS = 8;

    /**
     * Unique permutations of a canonical lottery number.
     */
    public function permutations(LotteryNumber $number): TodPermutationResult
    {
        return TodPermutationResult::of(
            $number,
            $this->uniquePermutationStrings($number->value()),
        );
    }

    /**
     * Unique permutations of a raw digit string of a known width.
     *
     * The width is asserted rather than inferred, so a two digit string can never
     * be silently accepted as a Tod selection.
     *
     * @throws InvalidMarketRuleException
     */
    public function permutationsOf(string $digits, int $requiredDigits, string $marketKey = self::TOD_MARKET_KEY): TodPermutationResult
    {
        $this->assertPermutable($digits, $requiredDigits, $marketKey);

        return $this->permutations(LotteryNumber::of($digits, $requiredDigits, $marketKey));
    }

    /**
     * The distinct permutations of a digit string, sorted ascending as strings.
     *
     * @return list<string>
     */
    public function uniquePermutationStrings(string $digits): array
    {
        if ($digits === '') {
            return [];
        }

        $seen = [];
        $out = [];

        $this->expand('', $this->characters($digits), $seen, $out);

        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * How many distinct permutations a digit string has.
     *
     * Computed from the actual generated set, so the number reported can never
     * disagree with the number of shapes the matcher will compare against.
     */
    public function countFor(string $digits): int
    {
        return count($this->uniquePermutationStrings($digits));
    }

    /**
     * Whether a candidate digit string is one of the distinct permutations.
     *
     * Strict string comparison: '007' never matches '7' or '70'.
     */
    public function covers(string $digits, string $candidate): bool
    {
        if (strlen($digits) !== strlen($candidate)) {
            return false;
        }

        foreach ($this->uniquePermutationStrings($digits) as $permutation) {
            if ($permutation === $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether two digit strings are rearrangements of each other.
     *
     * Cheap equivalent of covers() that does not generate the set: two strings are
     * rearrangements when their sorted characters are identical.
     */
    public function isRearrangement(string $left, string $right): bool
    {
        if (strlen($left) !== strlen($right)) {
            return false;
        }

        $leftChars = $this->characters($left);
        $rightChars = $this->characters($right);

        sort($leftChars, SORT_STRING);
        sort($rightChars, SORT_STRING);

        return $leftChars === $rightChars;
    }

    /**
     * Guarantees this engine makes, for the change report.
     *
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'unique_only' => 'Duplicate permutations are never created; 112 yields 3 shapes, not 6.',
            'strings_only' => 'Digits are handled as strings throughout; no intval, floatval or numeric cast.',
            'leading_zeros' => "Leading zeros are preserved; '007' never becomes 7 and '099' never becomes 99.",
            'deterministic' => 'The returned list is sorted ascending with SORT_STRING, so output is byte-stable.',
            'not_a_multiplier' => 'The permutation count is coverage only. It never scales a stake or a payout.',
            'no_money' => 'No stake, multiplier, payout or persistence is touched here.',
        ];
    }

    /**
     * Split a digit string into single character strings.
     *
     * str_split is used rather than any numeric helper, so each element stays a
     * one character string such as '0'.
     *
     * @return list<string>
     */
    private function characters(string $digits): array
    {
        return $digits === '' ? [] : array_values(str_split($digits));
    }

    /**
     * Depth first expansion that emits each distinct arrangement once.
     *
     * Deduplication happens at two levels: identical characters at the same depth
     * are skipped, and the finished string is checked against a prefixed key map.
     * The prefix is what keeps '123' from becoming the integer array key 123.
     *
     * @param  list<string>  $remaining
     * @param  array<string, true>  $seen
     * @param  list<string>  $out
     */
    private function expand(string $prefix, array $remaining, array &$seen, array &$out): void
    {
        if ($remaining === []) {
            $key = 'p'.$prefix;

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $prefix;
            }

            return;
        }

        $usedAtThisDepth = [];

        foreach ($remaining as $index => $character) {
            $characterKey = 'c'.$character;

            if (isset($usedAtThisDepth[$characterKey])) {
                continue;
            }

            $usedAtThisDepth[$characterKey] = true;

            $rest = $remaining;
            unset($rest[$index]);

            $this->expand($prefix.$character, array_values($rest), $seen, $out);
        }
    }

    /**
     * Refuse anything that is not a permutable digit string of the required width.
     *
     * @throws InvalidMarketRuleException
     */
    private function assertPermutable(string $digits, int $requiredDigits, string $marketKey): void
    {
        if (preg_match('/^[0-9]+$/', $digits) !== 1) {
            throw InvalidMarketRuleException::nonNumericSelection($marketKey, $digits);
        }

        if (strlen($digits) !== $requiredDigits) {
            throw InvalidMarketRuleException::wrongDigitCount($marketKey, $digits, $requiredDigits);
        }

        if ($requiredDigits > self::MAX_PERMUTABLE_DIGITS) {
            throw InvalidMarketRuleException::malformedRule(
                $marketKey,
                'digits',
                sprintf(
                    'permutation is refused above %d digits to prevent a factorial expansion',
                    self::MAX_PERMUTABLE_DIGITS,
                ),
            );
        }
    }
}
