<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which side of the official result a market settles against.
 *
 * The backing values are exactly the strings the project already uses for a
 * position: config('lottery.markets.<key>.position') is 'top' or 'bottom', and
 * the same strings are stored in bet_items.position and winning_numbers.position.
 * Using the same vocabulary means a side never has to be translated on its way
 * to the database.
 *
 * VALID COMBINATIONS ARE OWNED BY App\Enums\BetMarket
 * A side is only meaningful together with a family. 3D is TOP only, because the
 * project declares no 3d_bottom market; asking for 3D Bottom is refused by
 * BetMarket::supportsSide() rather than silently accepted. This enum does not
 * repeat that table.
 *
 * RESULT MAPPING IS NOT DECIDED HERE
 * How a side maps onto the published result is settlement behaviour and belongs
 * to a later phase. The configured sources are recorded in the market definitions
 * ('source' => 'first_prize_last_three' | 'first_prize_last_two' | 'bottom_two')
 * and resultSourceKey() only names where to read them from; it computes nothing.
 */
enum BetSide: string
{
    case Top = 'top';
    case Bottom = 'bottom';

    public function label(): string
    {
        return match ($this) {
            self::Top => 'Top',
            self::Bottom => 'Bottom',
        };
    }

    /**
     * The value written to bet_items.position and winning_numbers.position.
     *
     * Both columns are varchar(32) nullable, so the backing value fits directly.
     */
    public function positionValue(): string
    {
        return $this->value;
    }

    public function isTop(): bool
    {
        return $this === self::Top;
    }

    public function isBottom(): bool
    {
        return $this === self::Bottom;
    }

    public function opposite(): self
    {
        return match ($this) {
            self::Top => self::Bottom,
            self::Bottom => self::Top,
        };
    }

    /**
     * The configuration key that names the result field this side is settled
     * from, for a given market key.
     *
     * This returns a key, never a value and never a number. Reading it and
     * matching against it is Phase 5 settlement work.
     */
    public function resultSourceKey(string $marketKey): string
    {
        return sprintf('lottery.markets.%s.source', $marketKey);
    }

    /**
     * Resolve a position string coming from configuration or from a database
     * column, without defaulting.
     *
     * Returns null for anything unrecognised so the caller can reject it with a
     * stable code instead of guessing a side.
     */
    public static function fromPosition(?string $position): ?self
    {
        if ($position === null) {
            return null;
        }

        return self::tryFrom(mb_strtolower(trim($position)));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
