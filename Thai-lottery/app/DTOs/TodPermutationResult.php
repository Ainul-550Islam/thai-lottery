<?php

declare(strict_types=1);

namespace App\DTOs;

use App\ValueObjects\LotteryNumber;

/**
 * The unique permutations of one 3D TOD selection.
 *
 * A Tod selection is ONE selection. This object describes the winning shapes that
 * selection covers; it does not describe six bets, six stakes or six payouts.
 *
 *   123 -> 6 unique permutations (123 132 213 231 312 321)
 *   112 -> 3 unique permutations (112 121 211)
 *   111 -> 1 unique permutation  (111)
 *
 * DUPLICATE PERMUTATIONS ARE NEVER CREATED, so permutationCount() is the count of
 * distinct winning shapes and never 6 for a number with repeated digits.
 *
 * STRINGS ONLY
 * Every permutation is a digit string of the same width as the selection, so
 * '007' stays '007' and never becomes 7. No intval(), no floatval(), no numeric
 * array keys, no numeric sorting.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No stake and no payout. A Tod stake is the stake for the single selection and
 *   is never multiplied by the permutation count; pricing lives in
 *   App\Services\Betting\MarketPayoutService.
 * - No match decision. That is App\Services\Betting\TodMatchService.
 * - No persistence.
 */
final readonly class TodPermutationResult
{
    /**
     * @param  list<string>  $permutations  distinct, deterministically sorted digit strings
     */
    private function __construct(
        public LotteryNumber $number,
        public array $permutations,
        public bool $hasRepeatedDigits,
    ) {}

    /**
     * @param  list<string>  $permutations
     */
    public static function of(LotteryNumber $number, array $permutations): self
    {
        return new self($number, array_values($permutations), $number->hasRepeatedDigits());
    }

    /**
     * The canonical selection the permutations were generated from.
     */
    public function selection(): string
    {
        return $this->number->value();
    }

    /**
     * Digit width of the selection and of every permutation.
     */
    public function digits(): int
    {
        return $this->number->digits();
    }

    /**
     * The distinct permutations, sorted ascending as strings.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return $this->permutations;
    }

    /**
     * How many distinct winning shapes the single selection covers.
     *
     * This is a coverage count. It is not a stake multiplier and it is not a
     * payout multiplier.
     */
    public function permutationCount(): int
    {
        return count($this->permutations);
    }

    /**
     * Whether a given digit string is one of the distinct permutations.
     *
     * Compared with a strict string comparison, so '007' never matches '7'.
     */
    public function contains(string $candidate): bool
    {
        foreach ($this->permutations as $permutation) {
            if ($permutation === $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lowest permutation as a string, useful for deterministic display.
     */
    public function first(): string
    {
        return $this->permutations[0] ?? $this->number->value();
    }

    /**
     * Whether the selection has any repeated digit, which is why the count can be
     * 3 or 1 instead of 6.
     */
    public function hasRepeatedDigits(): bool
    {
        return $this->hasRepeatedDigits;
    }

    /**
     * The count a naive factorial implementation would have produced.
     *
     * Kept for reporting only: it makes the duplicate protection visible, for
     * example 112 covering 3 shapes where 3! is 6.
     */
    public function factorialCount(): int
    {
        $count = 1;

        for ($index = 2; $index <= $this->number->digits(); $index++) {
            $count *= $index;
        }

        return $count;
    }

    /**
     * Whether duplicate permutations were suppressed for this selection.
     */
    public function duplicatesSuppressed(): bool
    {
        return $this->permutationCount() < $this->factorialCount();
    }

    /**
     * @return array{selection: string, digits: int, permutation_count: int, factorial_count: int, duplicates_suppressed: bool, has_repeated_digits: bool, permutations: list<string>}
     */
    public function toArray(): array
    {
        return [
            'selection' => $this->selection(),
            'digits' => $this->digits(),
            'permutation_count' => $this->permutationCount(),
            'factorial_count' => $this->factorialCount(),
            'duplicates_suppressed' => $this->duplicatesSuppressed(),
            'has_repeated_digits' => $this->hasRepeatedDigits,
            'permutations' => $this->permutations,
        ];
    }
}
