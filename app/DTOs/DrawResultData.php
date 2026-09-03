<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\MarketResultType;
use App\Exceptions\DrawResultValidationException;

/**
 * One draw's validated official result, as digit strings.
 *
 * Produced only by App\Services\Draw\DrawResultValidator. The private constructor
 * makes that structural rather than a convention: no other class can build one, so
 * an unvalidated result cannot reach persistence or settlement.
 *
 * WHAT IT CARRIES
 *   firstPrize   the full official first prize, configured width
 *                (config('lottery.results.first_prize_digits'), 6 in this project)
 *   bottomTwo    the separately drawn two digit bottom number
 *
 * Both are DIGIT STRINGS. A first prize of '007123' stays '007123'. A bottom two of
 * '07' stays '07'. Nothing here converts either to a number, so a leading zero
 * cannot be lost.
 *
 * NO FLOAT, NO INT COERCION
 * No (int), (float), intval(), floatval() or round() appears in this class. The
 * only numeric operations are strlen(), substr() and preg_match() over strings.
 *
 * WHY THE DERIVED VALUES LIVE HERE
 * lastThree() and lastTwo() are pure substr() reads of firstPrize, matching exactly
 * what the verified App\Services\Betting\MarketResultResolver does when it reads a
 * stored result back (substr($firstPrize, -3) and substr($firstPrize, -2)). Keeping
 * the same derivation on the write path guarantees that the winning_numbers rows
 * written at publication equal the values settlement resolves afterwards. This is
 * not a second copy of the rule: the resolver stays authoritative for reads, and
 * PHASE_5.1_AUDIT.md records that the two must agree, which
 * tests/Feature/Settlement/DrawResultPublicationTest asserts directly.
 *
 * BOTTOM TWO IS NOT DERIVED
 * bottomTwo is an independently drawn value and is never taken from the first
 * prize. lastTwo() and bottomTwo may coincide by chance and that is not an error;
 * they are still two different values with two different meanings.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No validation. The rules live in DrawResultValidator, which is the only caller
 *   of fromValidated().
 * - No persistence. Writing draw_results and winning_numbers is
 *   App\Services\Draw\DrawResultPublicationService's job.
 * - No money of any kind: no multiplier, no prize amount, no stake.
 */
final readonly class DrawResultData
{
    /**
     * @param  array<string, mixed>  $optionalPrizes  additional official prize fields,
     *                                                already validated, keyed by the
     *                                                draw_results column they belong to
     * @param  array<string, scalar|null>  $context  diagnostic context only
     */
    private function __construct(
        public string $firstPrize,
        public string $bottomTwo,
        public int $firstPrizeDigits,
        public array $optionalPrizes = [],
        public array $context = [],
    ) {}

    /**
     * Build a result that App\Services\Draw\DrawResultValidator has already checked.
     *
     * Intentionally the only construction path. It re-asserts the two invariants it
     * depends on rather than trusting its caller, because a DrawResultData that
     * escaped with a non-digit value would defeat every downstream guarantee. These
     * assertions are defence in depth, not the validation itself.
     *
     * @param  array<string, mixed>  $optionalPrizes
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawResultValidationException
     */
    public static function fromValidated(
        string $firstPrize,
        string $bottomTwo,
        int $firstPrizeDigits,
        array $optionalPrizes = [],
        array $context = [],
    ): self {
        if (preg_match('/^[0-9]{'.$firstPrizeDigits.'}$/', $firstPrize) !== 1) {
            throw DrawResultValidationException::firstPrizeLength($firstPrize, $firstPrizeDigits, [
                'stage' => 'DrawResultData::fromValidated',
            ]);
        }

        if (preg_match('/^[0-9]{2}$/', $bottomTwo) !== 1) {
            throw DrawResultValidationException::bottomTwoLength($bottomTwo, 2, [
                'stage' => 'DrawResultData::fromValidated',
            ]);
        }

        return new self($firstPrize, $bottomTwo, $firstPrizeDigits, $optionalPrizes, $context);
    }

    /**
     * The full official first prize, exactly as published.
     */
    public function firstPrize(): string
    {
        return $this->firstPrize;
    }

    /**
     * The independently drawn two digit bottom number, exactly as published.
     */
    public function bottomTwo(): string
    {
        return $this->bottomTwo;
    }

    /**
     * The last three digits of the first prize, as a string.
     *
     * Decides 3d_direct, 3d_tod and run_top. A first prize of '100007' gives '007'.
     */
    public function lastThree(): string
    {
        return substr($this->firstPrize, -3);
    }

    /**
     * The last two digits of the first prize, as a string.
     *
     * Decides 2d_top. A first prize of '100007' gives '07'.
     */
    public function lastTwo(): string
    {
        return substr($this->firstPrize, -2);
    }

    /**
     * The winning value for a result type, as a digit string.
     *
     * Total over MarketResultType with no default branch, so a future result type
     * cannot silently fall through to the first prize.
     */
    public function valueFor(MarketResultType $resultType): string
    {
        return match ($resultType) {
            MarketResultType::ThreeDigitTop => $this->lastThree(),
            MarketResultType::TwoDigitTop => $this->lastTwo(),
            MarketResultType::TwoDigitBottom => $this->bottomTwo,
        };
    }

    /**
     * Every winning value of this result, keyed by MarketResultType backing value.
     *
     * @return array<string, string>
     */
    public function allValues(): array
    {
        $values = [];

        foreach (MarketResultType::cases() as $resultType) {
            $values[$resultType->value] = $this->valueFor($resultType);
        }

        return $values;
    }

    /**
     * Whether the bottom two happens to equal the last two of the first prize.
     *
     * Reported, never corrected. Two markets can legitimately be decided by the
     * same two digits in the same draw.
     */
    public function bottomTwoEqualsLastTwo(): bool
    {
        return $this->bottomTwo === $this->lastTwo();
    }

    /**
     * Whether the first prize carries a leading zero.
     *
     * Used by the publication and settlement tests to prove that a value such as
     * '007123' survives validation, storage and read-back unchanged.
     */
    public function firstPrizeHasLeadingZero(): bool
    {
        return str_starts_with($this->firstPrize, '0');
    }

    /**
     * Whether the bottom two carries a leading zero, such as '07'.
     */
    public function bottomTwoHasLeadingZero(): bool
    {
        return str_starts_with($this->bottomTwo, '0');
    }

    /**
     * Additional official prize fields, keyed by draw_results column.
     *
     * @return array<string, mixed>
     */
    public function optionalPrizes(): array
    {
        return $this->optionalPrizes;
    }

    /**
     * The draw_results columns this result writes, excluding metadata.
     *
     * bottom_two is deliberately ABSENT: the draw_results table has no bottom_two
     * column, and Phase 5.1 does not invent one. The bottom number goes into the
     * metadata JSON instead, under the key
     * config('lottery.results.bottom_two_metadata_key'), which is where the verified
     * App\Services\Betting\MarketResultResolver already reads it from.
     * metadataPayload() supplies it.
     *
     * @return array<string, mixed>
     */
    public function toResultColumns(): array
    {
        return ['first_prize' => $this->firstPrize] + $this->optionalPrizes;
    }

    /**
     * The metadata payload, merged over whatever the row already holds.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public function metadataPayload(string $bottomTwoMetadataKey, array $existing = []): array
    {
        return $existing + [
            $bottomTwoMetadataKey => $this->bottomTwo,
            'first_prize_digits' => $this->firstPrizeDigits,
            'published_by_phase' => '5.1',
        ];
    }

    /**
     * @return array{first_prize: string, first_prize_digits: int, bottom_two: string, last_three: string, last_two: string, bottom_two_equals_last_two: bool, first_prize_has_leading_zero: bool, bottom_two_has_leading_zero: bool, values: array<string, string>, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'first_prize' => $this->firstPrize,
            'first_prize_digits' => $this->firstPrizeDigits,
            'bottom_two' => $this->bottomTwo,
            'last_three' => $this->lastThree(),
            'last_two' => $this->lastTwo(),
            'bottom_two_equals_last_two' => $this->bottomTwoEqualsLastTwo(),
            'first_prize_has_leading_zero' => $this->firstPrizeHasLeadingZero(),
            'bottom_two_has_leading_zero' => $this->bottomTwoHasLeadingZero(),
            'values' => $this->allValues(),
            'context' => $this->context,
        ];
    }
}
