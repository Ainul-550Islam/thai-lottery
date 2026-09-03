<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\MarketResultType;

/**
 * One market's single win-or-lose decision for one selection.
 *
 * Produced by App\Services\Betting\ThreeDigitMatchService, TodMatchService,
 * TwoDigitMatchService and RunMatchService. Every one of them returns exactly one
 * decision per selection, which is what enforces the two payout rules that the
 * Phase 4.2 specification is most emphatic about:
 *
 *   A Tod selection that matches one of its permutations WINS ONCE. The payout is
 *   never multiplied by the number of matching permutations.
 *
 *   A Run selection whose digit occurs more than once in the drawn result WINS
 *   ONCE. 222 with a Run Top selection of 2 pays once, not three times. 44 with a
 *   Run Bottom selection of 4 pays once, not twice.
 *
 * payoutCount() is therefore 1 when matched and 0 when not matched, and
 * occurrences() and permutationCount() are diagnostic figures only.
 *
 * STRINGS ONLY
 * selection and winning are digit strings, so '07' never becomes 7 and '007'
 * never becomes 7.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No money. There is no stake, no multiplier and no payout amount here;
 *   App\Services\Betting\MarketPayoutService prices a match.
 * - No persistence, no bet, no ticket, no payout row.
 */
final readonly class MarketMatchResult
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(
        public string $marketKey,
        public string $selection,
        public string $winning,
        public bool $matched,
        public ?string $matchedValue,
        public string $matchMode,
        public ?MarketResultType $resultType,
        public bool $payoutOnce,
        public ?int $permutationCount,
        public ?int $occurrences,
        public array $context = [],
    ) {}

    /**
     * A winning decision.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function won(
        string $marketKey,
        string $selection,
        string $winning,
        string $matchedValue,
        string $matchMode,
        ?MarketResultType $resultType = null,
        ?int $permutationCount = null,
        ?int $occurrences = null,
        array $context = [],
    ): self {
        return new self(
            $marketKey,
            $selection,
            $winning,
            true,
            $matchedValue,
            $matchMode,
            $resultType,
            true,
            $permutationCount,
            $occurrences,
            $context,
        );
    }

    /**
     * A losing decision.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function lost(
        string $marketKey,
        string $selection,
        string $winning,
        string $matchMode,
        ?MarketResultType $resultType = null,
        ?int $permutationCount = null,
        ?int $occurrences = null,
        array $context = [],
    ): self {
        return new self(
            $marketKey,
            $selection,
            $winning,
            false,
            null,
            $matchMode,
            $resultType,
            true,
            $permutationCount,
            $occurrences,
            $context,
        );
    }

    public function isMatched(): bool
    {
        return $this->matched;
    }

    public function marketKey(): string
    {
        return $this->marketKey;
    }

    /**
     * The canonical selection as a digit string.
     */
    public function selection(): string
    {
        return $this->selection;
    }

    /**
     * The canonical winning value as a digit string.
     */
    public function winningValue(): string
    {
        return $this->winning;
    }

    /**
     * Which permutation or digit actually matched, or null when nothing matched.
     */
    public function matchedValue(): ?string
    {
        return $this->matchedValue;
    }

    /**
     * The rule under which the comparison was made: exact, permutation or
     * digit_contains.
     */
    public function matchMode(): string
    {
        return $this->matchMode;
    }

    public function resultType(): ?MarketResultType
    {
        return $this->resultType;
    }

    /**
     * Distinct permutations the selection covered, for permutation markets only.
     *
     * Coverage figure. Never a stake or payout multiplier.
     */
    public function permutationCount(): ?int
    {
        return $this->permutationCount;
    }

    /**
     * How many times a Run digit occurred in the drawn result, for reporting.
     *
     * Two occurrences still pay once.
     */
    public function occurrences(): ?int
    {
        return $this->occurrences;
    }

    /**
     * How many payouts this decision produces: 1 when matched, 0 when not.
     *
     * Never the permutation count and never the occurrence count.
     */
    public function payoutCount(): int
    {
        return $this->matched ? 1 : 0;
    }

    /**
     * Always true: every market in this engine pays a matched selection once.
     */
    public function paysOnce(): bool
    {
        return $this->payoutOnce;
    }

    public function contextValue(string $key): string|int|float|bool|null
    {
        return $this->context[$key] ?? null;
    }

    /**
     * @return array{market_key: string, selection: string, winning: string, matched: bool, matched_value: string|null, match_mode: string, result_type: string|null, payout_count: int, pays_once: bool, permutation_count: int|null, occurrences: int|null, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'market_key' => $this->marketKey,
            'selection' => $this->selection,
            'winning' => $this->winning,
            'matched' => $this->matched,
            'matched_value' => $this->matchedValue,
            'match_mode' => $this->matchMode,
            'result_type' => $this->resultType?->value,
            'payout_count' => $this->payoutCount(),
            'pays_once' => $this->payoutOnce,
            'permutation_count' => $this->permutationCount,
            'occurrences' => $this->occurrences,
            'context' => $this->context,
        ];
    }
}
