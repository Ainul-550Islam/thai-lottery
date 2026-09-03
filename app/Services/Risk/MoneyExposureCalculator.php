<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\Currency;
use App\Exceptions\RiskConfigurationException;
use App\Services\Finance\Money;

/**
 * Exact-decimal arithmetic for risk exposure figures.
 *
 * WHY THIS EXISTS
 * App\Services\Finance\Money already provides plus/minus/compare and refuses
 * floats, but it has no multiplication and no division, because Phase 2 never
 * needed either. Risk does: potential payout is stake x multiplier, and
 * utilisation is current / ceiling. Rather than add arithmetic to the audited
 * Money value object (which is outside the Phase 3.1 file list), the risk domain
 * gets its own calculator that consumes and returns Money, so the currency and
 * scale guarantees of Phase 2 still hold at every boundary.
 *
 * NO FLOAT RULE
 * Every operation here is bcmath on decimal strings. There is no (float), no
 * (double), no round() used as a financial correction, and no intval() anywhere.
 * A float argument is impossible: strict_types turns one into a TypeError.
 *
 * MULTIPLIER PRECISION — SCHEMA FACT
 * The schema stores multipliers in two different precisions:
 *   bet_items.payout_multiplier  UNSIGNED INTEGER   (whole numbers only)
 *   payouts.multiplier           DECIMAL(20,4)      (up to four decimals)
 * config/lottery.php only ever configures whole-number multipliers today
 * (2d 90, 3d 900, tod 45, run_top 3, run_bottom 4). This calculator accepts an
 * exact decimal multiplier with up to four decimal places so it matches the wider
 * of the two columns, and documents the integer limitation of bet_items rather
 * than pretending it does not exist.
 *
 * ROUNDING POSITION
 * stake x multiplier is computed at full precision. When the exact product needs
 * more decimal places than the currency scale allows (only possible with a
 * fractional multiplier), the result is taken UP to the next unit of the currency
 * scale, never down. That is a deliberate conservative ceiling on a liability, not
 * a rounding correction: it can only ever overstate what the house might owe, so
 * it cannot let a bet through that a ceiling should have stopped. With the
 * whole-number multipliers actually configured, the product is already exact and
 * this adjustment never engages; hasInexactProduct() reports when it would.
 */
class MoneyExposureCalculator
{
    /**
     * Working scale for intermediate products and ratios.
     *
     * Twelve places matches Money::MAX_INPUT_SCALE, so an intermediate value can
     * always be handed back to Money::of() without tripping its precision guard.
     */
    public const WORKING_SCALE = 12;

    /**
     * Scale used for utilisation ratios. Six places on a 0-1 ratio resolves one
     * part per million of a ceiling, finer than any DECIMAL(20,2) can express.
     */
    public const RATIO_SCALE = 6;

    /**
     * Largest number of decimal places a multiplier may carry, matching
     * payouts.multiplier DECIMAL(20,4).
     */
    public const MULTIPLIER_SCALE = 4;

    /**
     * Optional sign is absent on purpose: a multiplier is never negative.
     */
    private const MULTIPLIER_PATTERN = '/^[0-9]{1,16}(?:\.[0-9]{1,4})?$/';

    public function __construct()
    {
        Money::assertExactArithmeticIsAvailable();
    }

    /**
     * Potential payout for a stake at a multiplier.
     *
     * @param  Money  $stake  the amount wagered
     * @param  string|int  $multiplier  exact decimal or integer, never a float
     *
     * @throws RiskConfigurationException when the multiplier is malformed
     */
    public function potentialPayout(Money $stake, string|int $multiplier): Money
    {
        $stake->assertNotNegative('stake');

        $exact = $this->exactProduct($stake, $multiplier);
        $currency = $stake->currency();
        $scale = $stake->scale();

        return Money::of($this->ceilToScale($exact, $scale), $currency, $scale);
    }

    /**
     * The product at full precision, before any scale adjustment.
     *
     * Exposed so a caller can prove to itself whether the conservative ceiling
     * engaged, and so the report can show the untouched figure.
     *
     * @throws RiskConfigurationException
     */
    public function exactProduct(Money $stake, string|int $multiplier): string
    {
        return bcmul(
            $stake->amount(),
            $this->normaliseMultiplier($multiplier),
            self::WORKING_SCALE,
        );
    }

    /**
     * True when stake x multiplier cannot be expressed exactly at the currency
     * scale, meaning potentialPayout() took the value up to the next unit.
     *
     * @throws RiskConfigurationException
     */
    public function hasInexactProduct(Money $stake, string|int $multiplier): bool
    {
        $exact = $this->exactProduct($stake, $multiplier);

        return bccomp($exact, bcadd($exact, '0', $stake->scale()), self::WORKING_SCALE) !== 0;
    }

    /**
     * Combined liability: stake plus potential payout.
     *
     * ExposureType::CombinedExposure has no database column, so this figure is
     * reporting only and must never be written to number_limits.
     */
    public function combinedExposure(Money $stake, Money $potentialPayout): Money
    {
        return $stake->plus($potentialPayout);
    }

    /**
     * Sum a list of amounts in one currency.
     *
     * An empty list yields zero in $currency rather than throwing, because "no
     * exposure recorded yet" is a legitimate state for a fresh draw.
     *
     * @param  iterable<Money>  $amounts
     */
    public function sum(iterable $amounts, Currency $currency, ?int $scale = null): Money
    {
        $total = Money::zero($currency, $scale);

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /**
     * Projected total if $increment is accepted on top of $current.
     */
    public function project(Money $current, Money $increment): Money
    {
        return $current->plus($increment);
    }

    /**
     * Remaining headroom under a ceiling, floored at zero.
     *
     * The floor is applied by an explicit comparison, not by rounding: a row whose
     * accumulated amount already exceeds its ceiling (possible because the schema
     * has no CHECK tying current_payout_exposure to maximum_payout_exposure)
     * reports zero remaining, and the true negative figure stays visible through
     * surplus().
     */
    public function remainingCapacity(Money $ceiling, Money $current): Money
    {
        $remaining = $ceiling->minus($current);

        return $remaining->isNegative()
            ? Money::zero($ceiling->currency(), $ceiling->scale())
            : $remaining;
    }

    /**
     * How far $current is ABOVE $ceiling, or zero when it is not.
     *
     * This is the figure remainingCapacity() deliberately hides, kept available so
     * an already-breached row can be reported honestly instead of looking merely
     * full.
     */
    public function surplus(Money $ceiling, Money $current): Money
    {
        $surplus = $current->minus($ceiling);

        return $surplus->isNegative()
            ? Money::zero($ceiling->currency(), $ceiling->scale())
            : $surplus;
    }

    /**
     * Whether $projected fits within $ceiling. Equality fits: a limit of 1000.00
     * accepts a projected 1000.00 and refuses 1000.01.
     */
    public function fitsWithinCeiling(Money $projected, Money $ceiling): bool
    {
        return $projected->isLessThanOrEqualTo($ceiling);
    }

    /**
     * Utilisation of a ceiling as an exact decimal ratio string.
     *
     * A zero ceiling cannot be divided by. It is reported as a configuration
     * fault rather than returned as ratio 0 (which would read as "empty") or
     * ratio 1 (which would read as "full"), because both readings are guesses.
     * The stake ceiling can never be zero (CHECK max_amount > 0); a zero payout
     * ceiling would have to have been written deliberately.
     *
     * @return string exact decimal at RATIO_SCALE, e.g. '0.950000'
     *
     * @throws RiskConfigurationException
     */
    public function utilisation(Money $current, Money $ceiling): string
    {
        $current->assertSameCurrency($ceiling);

        if ($ceiling->isZero()) {
            throw RiskConfigurationException::invalidLimit(
                'a ceiling of zero cannot be used to compute utilisation',
                ['ceiling' => $ceiling->amount(), 'current' => $current->amount()],
            );
        }

        if ($ceiling->isNegative()) {
            throw RiskConfigurationException::invalidLimit(
                'a negative ceiling is not a usable limit',
                ['ceiling' => $ceiling->amount()],
            );
        }

        $ratio = bcdiv($current->amount(), $ceiling->amount(), self::RATIO_SCALE);

        return bccomp($ratio, '0', self::RATIO_SCALE) < 0
            ? bcadd('0', '0', self::RATIO_SCALE)
            : $ratio;
    }

    /**
     * Utilisation expressed as a percentage string, for display and for comparison
     * against config('risk.exposure.warning_percentage') which is an integer
     * percentage rather than a ratio.
     *
     * @throws RiskConfigurationException
     */
    public function utilisationPercent(Money $current, Money $ceiling): string
    {
        return bcmul($this->utilisation($current, $ceiling), '100', 2);
    }

    /**
     * Convert an integer percentage from configuration into an exact ratio.
     *
     * @throws RiskConfigurationException when the percentage is out of range
     */
    public function percentToRatio(int $percent, string $configKey): string
    {
        if ($percent < 0 || $percent > 100) {
            throw RiskConfigurationException::invalidKey(
                $configKey,
                'a percentage must be between 0 and 100',
                $percent,
            );
        }

        return bcdiv((string) $percent, '100', self::RATIO_SCALE);
    }

    /**
     * Build a Money value from an exact decimal string read out of configuration
     * or a request, refusing malformed and over-precise input.
     *
     * Money::of() already rejects malformed strings and precision loss, but its
     * failure is a FinancialException. Risk callers need a risk-domain fault with
     * the INVALID_AMOUNT reason code, so the translation happens here in one
     * place. Excess precision is REPORTED, never rounded away.
     *
     * @throws RiskConfigurationException
     */
    public function money(string $amount, Currency $currency, ?int $scale = null): Money
    {
        $raw = trim($amount);
        $scale ??= $currency->scale();

        if ($raw === '') {
            throw RiskConfigurationException::invalidAmount('the amount is empty', $amount);
        }

        if (preg_match('/^[+-]?[0-9]{1,24}(?:\.[0-9]+)?$/', $raw) !== 1) {
            throw RiskConfigurationException::invalidAmount(
                'the amount is not an exact decimal value',
                $raw,
            );
        }

        $fractionalDigits = str_contains($raw, '.')
            ? strlen(substr($raw, strpos($raw, '.') + 1))
            : 0;

        if ($fractionalDigits > $scale) {
            throw RiskConfigurationException::invalidAmount(
                sprintf(
                    'the amount carries %d decimal places but %s permits %d; refusing to round it',
                    $fractionalDigits,
                    $currency->value,
                    $scale,
                ),
                $raw,
            );
        }

        try {
            return Money::of($raw, $currency, $scale);
        } catch (\App\Exceptions\FinancialException $exception) {
            throw new RiskConfigurationException(
                'Invalid monetary amount: '.$exception->getMessage(),
                RiskConfigurationException::REASON_INVALID_AMOUNT,
                ['submitted' => $raw],
                $exception,
            );
        }
    }

    /**
     * A stake must be strictly positive: the bets table enforces
     * CHECK (stake_amount > 0), so a zero stake could never be persisted and is
     * refused here before any money moves.
     *
     * @throws RiskConfigurationException
     */
    public function assertPositiveStake(Money $stake): Money
    {
        if (! $stake->isPositive()) {
            throw RiskConfigurationException::invalidAmount(
                'a stake must be greater than zero',
                $stake->amount(),
            );
        }

        return $stake;
    }

    /**
     * Read a Money value straight out of a number_limits column.
     *
     * Eloquent's decimal:2 cast returns a string at column scale, and a NULL
     * column becomes zero, which is the correct reading of current_amount or
     * current_payout_exposure. It is NOT the correct reading of a ceiling column,
     * so callers must check a nullable ceiling for NULL before calling this.
     */
    public function fromColumn(string|int|null $value, Currency $currency, ?int $scale = null): Money
    {
        return Money::fromDatabase($value, $currency, $scale);
    }

    /**
     * Validate and normalise a multiplier to MULTIPLIER_SCALE decimal places.
     *
     * @throws RiskConfigurationException
     */
    public function normaliseMultiplier(string|int $multiplier): string
    {
        $raw = is_int($multiplier) ? (string) $multiplier : trim($multiplier);

        if ($raw === '' || preg_match(self::MULTIPLIER_PATTERN, $raw) !== 1) {
            throw RiskConfigurationException::invalidKey(
                'payout_multiplier',
                sprintf(
                    'a multiplier must be a non-negative exact decimal with at most %d decimal places',
                    self::MULTIPLIER_SCALE,
                ),
                $raw === '' ? null : $raw,
            );
        }

        return bcadd($raw, '0', self::MULTIPLIER_SCALE);
    }

    /**
     * True when this multiplier can be stored in bet_items.payout_multiplier,
     * which is an UNSIGNED INTEGER and would truncate a fractional value.
     *
     * @throws RiskConfigurationException
     */
    public function multiplierFitsBetItemColumn(string|int $multiplier): bool
    {
        $normalised = $this->normaliseMultiplier($multiplier);

        return bccomp($normalised, bcadd($normalised, '0', 0), self::MULTIPLIER_SCALE) === 0;
    }

    /**
     * Take a non-negative decimal string UP to the given scale.
     *
     * bcadd() truncates, so the truncated value is compared against the original
     * and one unit of the target scale is added when anything was lost. Applied
     * only to non-negative liabilities, where up means conservative.
     */
    private function ceilToScale(string $value, int $scale): string
    {
        $truncated = bcadd($value, '0', $scale);

        if (bccomp($value, $truncated, self::WORKING_SCALE) === 0) {
            return $truncated;
        }

        if (bccomp($value, '0', self::WORKING_SCALE) < 0) {
            // Negative values are not liabilities; truncation toward zero is
            // already the conservative direction and no unit is added.
            return $truncated;
        }

        return bcadd($truncated, bcdiv('1', bcpow('10', (string) $scale), $scale), $scale);
    }
}
