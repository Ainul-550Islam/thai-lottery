<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of winning value a Thai lottery market is settled against.
 *
 * A market is not the same thing as a result. Six markets are sold
 * (3d_direct, 3d_tod, 2d_top, 2d_bottom, run_top, run_bottom) but they are
 * decided by only three distinct winning values:
 *
 *   three_digit_top  : the last three digits of the first prize number
 *   two_digit_top    : the last two digits of the first prize number
 *   two_digit_bottom : the separately drawn two digit bottom number
 *
 * That is why 3d_direct, 3d_tod and run_top all read the same three digit top
 * value while comparing it under three different match rules, and why 2d_bottom
 * and run_bottom share the two digit bottom value.
 *
 * The backing values mirror the source keys already declared in
 * config('lottery.markets.*.source'), so configuration remains the single place
 * where a market is wired to a result. fromSourceKey() performs that mapping.
 *
 * OPERATOR RULES, NOT OFFICIAL GOVERNMENT PRODUCT RULES
 * These are online-operator style market definitions. They are not an assertion
 * that every official Thai government lottery product settles identically.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No database access and no model knowledge. Reading the actual drawn value is
 *   App\Services\Betting\MarketResultResolver's job.
 * - No payout rates. Money lives in configuration, read through
 *   App\Services\Betting\MarketPayoutService.
 * - No integer casting anywhere. Result values are digit strings, so '007' and
 *   '07' keep their leading zeros.
 */
enum MarketResultType: string
{
    case ThreeDigitTop = 'first_prize_last_three';
    case TwoDigitTop = 'first_prize_last_two';
    case TwoDigitBottom = 'bottom_two';

    /**
     * Human readable description for reports and admin screens.
     */
    public function label(): string
    {
        return match ($this) {
            self::ThreeDigitTop => 'Three digit top (last three digits of the first prize)',
            self::TwoDigitTop => 'Two digit top (last two digits of the first prize)',
            self::TwoDigitBottom => 'Two digit bottom (separately drawn bottom number)',
        };
    }

    /**
     * Digit width of the winning value, as a string length.
     */
    public function digits(): int
    {
        return match ($this) {
            self::ThreeDigitTop => 3,
            self::TwoDigitTop => 2,
            self::TwoDigitBottom => 2,
        };
    }

    /**
     * Which side of the draw this winning value belongs to.
     */
    public function side(): BetSide
    {
        return match ($this) {
            self::ThreeDigitTop, self::TwoDigitTop => BetSide::Top,
            self::TwoDigitBottom => BetSide::Bottom,
        };
    }

    /**
     * The configuration source key this result type is declared under.
     *
     * Identical to the backing value; exposed as a method so callers read intent
     * rather than relying on the enum's backing representation.
     */
    public function sourceKey(): string
    {
        return $this->value;
    }

    /**
     * Whether the value is derived from the first prize number.
     */
    public function derivesFromFirstPrize(): bool
    {
        return $this !== self::TwoDigitBottom;
    }

    /**
     * Whether the value is stored outside a dedicated column.
     *
     * The draw_results table has first_prize but has no bottom_two column, so the
     * bottom number is carried in the metadata JSON under
     * config('lottery.results.bottom_two_metadata_key'). This is a schema fact,
     * reported rather than worked around.
     */
    public function isMetadataBacked(): bool
    {
        return $this === self::TwoDigitBottom;
    }

    /**
     * Where the value comes from, in words, for the change report.
     */
    public function storageDescription(): string
    {
        return match ($this) {
            self::ThreeDigitTop => 'substr(draw_results.first_prize, -3) as a string',
            self::TwoDigitTop => 'substr(draw_results.first_prize, -2) as a string',
            self::TwoDigitBottom => 'draw_results.metadata JSON, key config(lottery.results.bottom_two_metadata_key)',
        };
    }

    /**
     * Map a configured market source key onto a result type.
     *
     * Returns null for an unknown or absent key so the caller decides whether an
     * unknown source is a refusal. Nothing is guessed here.
     */
    public static function fromSourceKey(?string $sourceKey): ?self
    {
        if ($sourceKey === null || $sourceKey === '') {
            return null;
        }

        return self::tryFrom($sourceKey);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
