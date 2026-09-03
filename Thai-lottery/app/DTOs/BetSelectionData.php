<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BetMarket;
use App\Enums\BetSelectionType;
use App\Enums\BetSide;
use App\Enums\BetType;
use App\ValueObjects\BetAmount;
use App\ValueObjects\LotteryNumber;
use App\ValueObjects\PayoutMultiplier;

/**
 * One incoming selection, carried immutably through the domain layer.
 *
 * WHAT IT IS
 * A description of what a player is asking for: a draw, a market family, a side, a
 * selection mechanism, a number and a stake. It is the input to validation and to
 * calculation.
 *
 * WHAT IT IS NOT
 * It is not a Bet, not a BetItem and not a Ticket. It persists nothing, queries
 * nothing, debits nothing and locks nothing. Constructing one has no side effect
 * whatsoever, which is what makes it safe to build straight from unvalidated input.
 *
 * RAW AND CANONICAL ARE BOTH KEPT
 * $rawNumber is exactly what arrived, untouched: not trimmed of zeros, not cast,
 * not padded. $number is the canonical LotteryNumber, and it is null until
 * validation has produced one. Keeping both is deliberate - a leading-zero defect
 * is only diagnosable if the original input survives alongside the canonical form.
 *
 * IMMUTABILITY AND DERIVATION
 * The class is final and readonly. The with* methods return new instances rather
 * than mutating, so a validated copy can be built without discarding the original
 * request.
 */
final readonly class BetSelectionData
{
    /**
     * @param  array<string, scalar|null>  $metadata  safe diagnostic context only
     */
    public function __construct(
        public int $drawId,
        public BetMarket $market,
        public BetSide $side,
        public BetSelectionType $selectionType,
        public string $rawNumber,
        public BetAmount $stake,
        public ?LotteryNumber $number = null,
        public ?PayoutMultiplier $multiplier = null,
        public ?string $marketKey = null,
        public ?BetType $betType = null,
        public array $metadata = [],
    ) {
    }

    /**
     * A copy carrying the canonical number produced by validation.
     */
    public function withNumber(LotteryNumber $number): self
    {
        return new self(
            $this->drawId,
            $this->market,
            $this->side,
            $this->selectionType,
            $this->rawNumber,
            $this->stake,
            $number,
            $this->multiplier,
            $this->marketKey,
            $this->betType,
            $this->metadata,
        );
    }

    /**
     * A copy carrying the resolved multiplier.
     */
    public function withMultiplier(PayoutMultiplier $multiplier): self
    {
        return new self(
            $this->drawId,
            $this->market,
            $this->side,
            $this->selectionType,
            $this->rawNumber,
            $this->stake,
            $this->number,
            $multiplier,
            $this->marketKey,
            $this->betType,
            $this->metadata,
        );
    }

    /**
     * A copy carrying the resolved configured market key and its bet type.
     */
    public function withResolvedMarket(string $marketKey, ?BetType $betType = null): self
    {
        return new self(
            $this->drawId,
            $this->market,
            $this->side,
            $this->selectionType,
            $this->rawNumber,
            $this->stake,
            $this->number,
            $this->multiplier,
            $marketKey,
            $betType ?? $this->betType,
            $this->metadata,
        );
    }

    /**
     * A copy carrying a different stake.
     */
    public function withStake(BetAmount $stake): self
    {
        return new self(
            $this->drawId,
            $this->market,
            $this->side,
            $this->selectionType,
            $this->rawNumber,
            $stake,
            $this->number,
            $this->multiplier,
            $this->marketKey,
            $this->betType,
            $this->metadata,
        );
    }

    /**
     * A copy with extra safe diagnostic context merged in.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->drawId,
            $this->market,
            $this->side,
            $this->selectionType,
            $this->rawNumber,
            $this->stake,
            $this->number,
            $this->multiplier,
            $this->marketKey,
            $this->betType,
            $metadata + $this->metadata,
        );
    }

    /**
     * Has validation produced a canonical number for this selection.
     */
    public function hasCanonicalNumber(): bool
    {
        return $this->number instanceof LotteryNumber;
    }

    /**
     * Has a multiplier been resolved for this selection.
     */
    public function hasMultiplier(): bool
    {
        return $this->multiplier instanceof PayoutMultiplier;
    }

    /**
     * The canonical number if there is one, otherwise the raw input.
     *
     * Useful for reporting, and never used for arithmetic or persistence.
     */
    public function numberForDisplay(): string
    {
        return $this->number?->value() ?? $this->rawNumber;
    }

    /**
     * The market key if resolved, otherwise the family/selection/side triple, for
     * reporting only.
     */
    public function marketDescription(): string
    {
        return $this->marketKey ?? sprintf(
            '%s/%s/%s',
            $this->market->value,
            $this->selectionType->value,
            $this->side->value,
        );
    }

    /**
     * Log-safe representation.
     *
     * Only identifiers, canonical values and amounts. There is no user identity, no
     * token, no request payload and no credential in this DTO to begin with, and
     * nothing is added here.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'market' => $this->market->value,
            'side' => $this->side->value,
            'selection_type' => $this->selectionType->value,
            'market_key' => $this->marketKey,
            'bet_type' => $this->betType?->value,
            'raw_number' => $this->rawNumber,
            'number' => $this->number?->value(),
            'digits' => $this->number?->digits(),
            'stake' => $this->stake->amount(),
            'currency' => $this->stake->currency()->value,
            'multiplier' => $this->multiplier?->value(),
            'metadata' => $this->metadata,
        ];
    }
}
