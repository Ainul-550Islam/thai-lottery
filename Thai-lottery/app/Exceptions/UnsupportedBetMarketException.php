<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BetMarket;
use App\Enums\BetSelectionType;
use App\Enums\BetSide;
use App\Enums\BetValidationCode;

/**
 * The requested market, or the requested combination of market, selection type
 * and side, is not one this project sells.
 *
 * WHY A COMBINATION IS REFUSED RATHER THAN COERCED
 * The six sellable markets are declared in config('lottery.markets'). A request
 * for something outside that set - 3D Bottom being the obvious example, since no
 * 3d_bottom market exists anywhere in the project - has no price, no result
 * source and no match mode. Coercing it to the nearest declared market would sell
 * the player something they did not ask for, so it is refused with a stable code.
 *
 * The supported alternatives are listed in the context so a client can present a
 * correct choice without a second round trip.
 */
class UnsupportedBetMarketException extends BetDomainException
{
    /**
     * The market family itself is not recognised.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function unknownMarket(string $raw, array $context = []): self
    {
        return new self(
            sprintf(
                'Market "%s" is not recognised. Supported markets: %s.',
                self::echoSafely($raw),
                implode(', ', array_column(BetMarket::cases(), 'value')),
            ),
            BetValidationCode::InvalidMarket->value,
            $context + ['market' => self::echoSafely($raw)],
        );
    }

    /**
     * The side is not one this project uses at all.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function unknownSide(string $raw, array $context = []): self
    {
        return new self(
            sprintf(
                'Side "%s" is not recognised. Supported sides: %s.',
                self::echoSafely($raw),
                implode(', ', BetSide::values()),
            ),
            BetValidationCode::InvalidSide->value,
            $context + ['side' => self::echoSafely($raw)],
        );
    }

    /**
     * The selection type is not one this project uses at all.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function unknownSelectionType(string $raw, array $context = []): self
    {
        return new self(
            sprintf(
                'Selection type "%s" is not recognised. Supported selection types: %s.',
                self::echoSafely($raw),
                implode(', ', BetSelectionType::values()),
            ),
            BetValidationCode::InvalidSelectionType->value,
            $context + ['selection_type' => self::echoSafely($raw)],
        );
    }

    /**
     * The side is valid in general but not for this market family.
     *
     * This is the path a 3D Bottom request takes.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function sideNotAvailable(BetMarket $market, BetSide $side, array $context = []): self
    {
        return new self(
            sprintf(
                '%s does not have a %s side. %s supports: %s.',
                $market->label(),
                $side->label(),
                $market->label(),
                implode(', ', array_map(
                    static fn (BetSide $available): string => $available->value,
                    $market->sides(),
                )),
            ),
            BetValidationCode::InvalidSide->value,
            $context + ['market' => $market->value, 'side' => $side->value],
        );
    }

    /**
     * The selection type is valid in general but not for this market family.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function selectionTypeNotAvailable(
        BetMarket $market,
        BetSelectionType $selectionType,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                '%s cannot be selected as %s. %s supports: %s.',
                $market->label(),
                $selectionType->label(),
                $market->label(),
                implode(', ', array_map(
                    static fn (BetSelectionType $available): string => $available->value,
                    $market->selectionTypes(),
                )),
            ),
            BetValidationCode::InvalidSelectionType->value,
            $context + ['market' => $market->value, 'selection_type' => $selectionType->value],
        );
    }

    /**
     * Every part is individually valid but the combination is not a market this
     * project declares.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function combinationNotSold(
        BetMarket $market,
        BetSelectionType $selectionType,
        BetSide $side,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'The combination %s / %s / %s is not a market this platform sells. Declared markets: %s.',
                $market->value,
                $selectionType->value,
                $side->value,
                implode(', ', BetMarket::allMarketKeys()),
            ),
            BetValidationCode::UnsupportedMarket->value,
            $context + [
                'market' => $market->value,
                'selection_type' => $selectionType->value,
                'side' => $side->value,
            ],
        );
    }

    /**
     * The market key resolves but its configuration block is absent, disabled or
     * unusable.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notConfigured(string $marketKey, string $reason, array $context = []): self
    {
        return new self(
            sprintf('Market %s is not available: %s.', $marketKey, $reason),
            BetValidationCode::UnsupportedMarket->value,
            $context + ['market_key' => $marketKey],
        );
    }

    /**
     * The configured market key this failure concerns, when one was resolved.
     */
    public function marketKey(): ?string
    {
        $value = $this->contextValue('market_key');

        return is_string($value) ? $value : null;
    }

    private static function echoSafely(string $raw): string
    {
        if (mb_strlen($raw) <= 40) {
            return $raw;
        }

        return mb_substr($raw, 0, 40).'...';
    }
}
