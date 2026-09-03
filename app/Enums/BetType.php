<?php

namespace App\Enums;

/**
 * The four bet type families sold by the platform.
 *
 * PHASE 4.2 COMPATIBILITY FIX
 * ---------------------------
 * This enum is a type-level (family-level) descriptor. It existed before the
 * market rule engine and is still consumed by:
 *
 *   - App\Services\Risk\NumberNormalizationService  (digit agreement assertion)
 *   - App\Services\Risk\NumberLimitEngine           (calls that assertion)
 *   - App\Services\Betting\LotteryNumberService     (digit rule audit)
 *   - App\Services\Betting\PayoutMultiplierService  (multiplier audit)
 *   - App\Models\WinningNumber, App\Models\Bet, App\Models\BetItem (casts)
 *
 * Before Phase 4.2 the digit counts returned here disagreed with the
 * authoritative configuration in config/lottery.php:
 *
 *   tod: enum said 2, configuration says 3
 *   run: enum said 2, configuration says 1
 *
 * App\Services\Risk\NumberNormalizationService::assertBetTypeDigitsAgreeWithConfig()
 * refuses to canonicalise a number while that disagreement exists, and
 * App\Services\Risk\NumberLimitEngine calls it on every assessment. The effect
 * was that no Tod or Run selection could ever reach the Phase 3.1 risk engine,
 * which made the Phase 4.2 market rules unreachable end to end.
 *
 * The Phase 4.2 market-domain resolution, which is the authoritative rule set,
 * is:
 *
 *   3D TOD    : 3 digits, permutation YES (unique permutations only)
 *   RUN TOP   : 1 digit,  permutation NO
 *   RUN BOTTOM: 1 digit,  permutation NO
 *
 * digits() and allowsPermutation() are therefore corrected here so that the
 * type-level descriptor agrees with the authoritative market rules. No case was
 * removed, no method was removed, and no method signature changed, so every
 * existing caller keeps working.
 *
 * payoutMultiplier() is deliberately NOT changed. It returns a single integer
 * per family, and Run is sold at two different configured rates (run_top = 3,
 * run_bottom = 4). One integer cannot express two market rates, so this method
 * stays as a legacy family-level fallback and is NOT authoritative. The
 * authoritative payout source is declared by config('lottery.payouts.multiplier_source')
 * as 'lottery.markets' and is read through
 * App\Services\Betting\PayoutMultiplierService and
 * App\Services\Betting\MarketPayoutService. See PHASE-4.2-REPORT.md.
 */
enum BetType: string
{
    case TwoD = '2d';
    case ThreeD = '3d';
    case Tod = 'tod';
    case Run = 'run';

    public function label(): string
    {
        return match ($this) {
            self::TwoD => '2D Bet',
            self::ThreeD => '3D Bet',
            self::Tod => 'Tod Bet',
            self::Run => 'Run Bet',
        };
    }

    /**
     * Digits a selection of this family carries.
     *
     * These values agree with config('lottery.types.*.digits') and with
     * config('lottery.markets.*.digits'). Tod is a three digit selection whose
     * order does not matter; Run is a single digit selection.
     */
    public function digits(): int
    {
        return match ($this) {
            self::TwoD => 2,
            self::ThreeD => 3,
            self::Tod => 3,
            self::Run => 1,
        };
    }

    /**
     * Legacy family-level payout multiplier.
     *
     * NOT authoritative. Kept unchanged for backward compatibility. Run is sold
     * at two configured market rates (run_top = 3, run_bottom = 4) which a
     * single integer cannot represent. Always price a selection through
     * App\Services\Betting\MarketPayoutService, which reads the authoritative
     * source declared by config('lottery.payouts.multiplier_source').
     */
    public function payoutMultiplier(): int
    {
        return match ($this) {
            self::TwoD => 90,
            self::ThreeD => 900,
            self::Tod => 45,
            self::Run => 12,
        };
    }

    /**
     * Whether the digits must match in the exact position order.
     *
     * Run is neither an exact-order market nor a permutation market: a single
     * digit is tested for containment in the drawn result, so it is absent from
     * both lists. This agrees with config('lottery.types.run.requires_exact_order')
     * being false and config('lottery.types.run.allows_permutation') being false.
     */
    public function requiresExactOrder(): bool
    {
        return in_array($this, [self::TwoD, self::ThreeD]);
    }

    /**
     * Whether permutations of the selected digits are generated.
     *
     * Tod only. Run was removed in Phase 4.2: the authoritative market rule is
     * RUN = NO PERMUTATION. Permutations for Tod are unique permutations, and a
     * Tod selection is one selection with one stake and one possible payout.
     */
    public function allowsPermutation(): bool
    {
        return in_array($this, [self::Tod]);
    }
}
