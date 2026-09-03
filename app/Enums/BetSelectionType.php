<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the player's digits are matched against the result.
 *
 * DIRECT  the digits must appear in exactly the order chosen
 * TOD     the same digits in any order (the 3D permutation market)
 * RUN     a single digit appearing anywhere in the relevant result
 *
 * These three mirror the match modes the project already declares in
 * config('lottery.markets.<key>.match_mode'): 'exact', 'permutation' and
 * 'digit_contains'. configuredMatchMode() names the configured mode a selection
 * type corresponds to so a mismatch between the domain vocabulary and the
 * configuration can be detected instead of assumed.
 *
 * NO MATCHING AND NO COMBINATION GENERATION HERE
 * Nothing in this enum matches a number, expands a permutation or decides how
 * many bet items a Tod selection produces. Whether a Tod bet materialises one
 * row or six is an unresolved business rule and is reported as
 * SPECIFICATION REQUIRED by App\Services\Betting\LotteryNumberService.
 */
enum BetSelectionType: string
{
    case Direct = 'direct';
    case Tod = 'tod';
    case Run = 'run';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Tod => 'Tod',
            self::Run => 'Run',
        };
    }

    /**
     * The value of config('lottery.markets.<key>.match_mode') this selection type
     * is expected to correspond to.
     */
    public function configuredMatchMode(): string
    {
        return match ($this) {
            self::Direct => 'exact',
            self::Tod => 'permutation',
            self::Run => 'digit_contains',
        };
    }

    /**
     * Does the chosen digit order matter.
     */
    public function requiresExactOrder(): bool
    {
        return $this === self::Direct;
    }

    /**
     * Is this selection matched by permutation of the chosen digits.
     */
    public function allowsPermutation(): bool
    {
        return $this === self::Tod;
    }

    /**
     * Is this selection matched by containment of a single digit.
     */
    public function matchesByContainment(): bool
    {
        return $this === self::Run;
    }

    /**
     * Does this selection type need a combination rule that the project has not
     * yet specified.
     *
     * True for Tod: config declares match_mode 'permutation' and
     * allows_permutation true, which establishes that order does not matter, but
     * it does not establish how many bet items a Tod selection materialises, nor
     * how a number with repeated digits (for example '112', which has three
     * distinct permutations rather than six) is priced. Those rules must be
     * supplied before Tod can be sold.
     */
    public function requiresUnspecifiedCombinationRule(): bool
    {
        return $this === self::Tod;
    }

    /**
     * Resolve a match mode string from configuration back to a selection type.
     *
     * Returns null for an unrecognised mode so the caller can report it instead
     * of falling back to Direct.
     */
    public static function fromMatchMode(?string $matchMode): ?self
    {
        if ($matchMode === null) {
            return null;
        }

        $normalised = mb_strtolower(trim($matchMode));

        foreach (self::cases() as $case) {
            if ($case->configuredMatchMode() === $normalised) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
