<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\ExposureType;
use App\Enums\RiskLevel;
use App\Exceptions\RiskConfigurationException;
use App\Services\Finance\Money;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Turns an exposure utilisation figure into a severity band.
 *
 * THRESHOLDS COME FROM CONFIGURATION, NOT FROM THIS FILE
 * config/risk.php already declares the bands, and its own comment states that the
 * boundaries mirror App\Enums\RiskLevel so the enum and the configuration cannot
 * disagree:
 *
 *     'thresholds' => [
 *         'medium'     => 0.3,
 *         'high'       => 0.6,
 *         'suspicious' => 0.8,   // env RISK_SUSPICIOUS_THRESHOLD
 *         'critical'   => 0.95,
 *         'block'      => 1.0,
 *     ]
 *
 * No production threshold is invented here. When a required threshold is absent or
 * the set is not strictly ascending, the calculator REPORTS a configuration fault
 * instead of substituting a plausible number, because a wrong "critical" boundary
 * silently changes which bets get blocked.
 *
 * WHY NOT RiskLevel::fromScore()
 * fromScore() takes a float and hard-codes 0.3/0.6/0.8. It is left untouched for
 * existing callers. Risk utilisation is derived from money, so it arrives here as
 * an exact decimal string and is compared with bccomp against the configured
 * boundaries. No float participates in any comparison; the configured floats are
 * rendered to fixed-precision decimal strings before use.
 *
 * SUSPICIOUS AND BLOCK
 * 'suspicious' sits between high and critical and is reported as a separate flag
 * rather than as a fifth band, because RiskLevel has exactly four cases and adding
 * one would break the audit_logs.risk_level cast. 'block' (1.0) marks the point at
 * which the ceiling is fully consumed and is used only as a sanity boundary; the
 * actual rejection is decided by the exact ceiling comparison in
 * NumberLimitEngine, not by a ratio, so a rounding artefact can never allow or
 * refuse a bet on its own.
 */
class RiskLevelCalculator
{
    private const SCALE = MoneyExposureCalculator::RATIO_SCALE;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly MoneyExposureCalculator $money,
    ) {
    }

    /**
     * Severity band for an exact-decimal utilisation ratio.
     *
     * @param  string  $utilisation  e.g. '0.950000'
     *
     * @throws RiskConfigurationException
     */
    public function fromUtilisation(string $utilisation): RiskLevel
    {
        return RiskLevel::fromConfiguredThresholds($utilisation, $this->thresholds());
    }

    /**
     * Severity band for an amount against a ceiling.
     *
     * @throws RiskConfigurationException
     */
    public function fromAmounts(Money $current, Money $ceiling): RiskLevel
    {
        return $this->fromUtilisation($this->money->utilisation($current, $ceiling));
    }

    /**
     * Severity band implied by a completed exposure assessment.
     *
     * Reads 'utilisation_after', because the band must describe the state the
     * system would be in if the bet were accepted, not the state it is in now.
     *
     * @param  array<string, mixed>  $assessment  one entry from ExposureCalculator::assessAll()
     *
     * @throws RiskConfigurationException
     */
    public function fromAssessment(array $assessment): RiskLevel
    {
        $utilisation = $assessment['utilisation_after'] ?? null;

        if (! is_string($utilisation)) {
            throw RiskConfigurationException::invalidLimit(
                'an exposure assessment without utilisation_after cannot be graded',
            );
        }

        return $this->fromUtilisation($utilisation);
    }

    /**
     * The worst band across every assessed exposure type.
     *
     * A number that is comfortable on stake but critical on payout liability is
     * critical: the house is exposed by the worst of its ceilings, not the average.
     *
     * @param  array<string, array<string, mixed>>  $assessments
     *
     * @throws RiskConfigurationException
     */
    public function worstOf(array $assessments): RiskLevel
    {
        $worst = RiskLevel::Low;

        foreach ($assessments as $assessment) {
            $level = $this->fromAssessment($assessment);

            if ($level->atLeast($worst)) {
                $worst = $level;
            }
        }

        return $worst;
    }

    /**
     * True when utilisation has passed the configured suspicious threshold.
     *
     * @throws RiskConfigurationException
     */
    public function isSuspicious(string $utilisation): bool
    {
        return bccomp(
            $this->normalise($utilisation),
            $this->threshold('suspicious'),
            self::SCALE,
        ) >= 0;
    }

    /**
     * True when utilisation has reached the configured block boundary.
     *
     * Advisory only. The authoritative refusal is the exact Money comparison in
     * NumberLimitEngine, which cannot be moved by a ratio's precision.
     *
     * @throws RiskConfigurationException
     */
    public function isAtBlockBoundary(string $utilisation): bool
    {
        return bccomp(
            $this->normalise($utilisation),
            $this->threshold('block'),
            self::SCALE,
        ) >= 0;
    }

    /**
     * True when utilisation has entered the warning band.
     *
     * config('risk.exposure.warning_percentage') is an integer percentage (80),
     * not a ratio, so it is converted exactly before comparison.
     *
     * @throws RiskConfigurationException
     */
    public function isInWarningBand(string $utilisation): bool
    {
        return bccomp($this->normalise($utilisation), $this->warningRatio(), self::SCALE) >= 0;
    }

    /**
     * The warning band boundary as an exact decimal ratio.
     *
     * @throws RiskConfigurationException
     */
    public function warningRatio(): string
    {
        $percent = $this->config->get('risk.exposure.warning_percentage');

        if ($percent === null) {
            throw RiskConfigurationException::missingKey('risk.exposure.warning_percentage');
        }

        if (! is_int($percent)) {
            throw RiskConfigurationException::invalidKey(
                'risk.exposure.warning_percentage',
                'the warning percentage must be an integer',
                is_string($percent) ? $percent : null,
            );
        }

        return $this->money->percentToRatio($percent, 'risk.exposure.warning_percentage');
    }

    /**
     * How much of the ceiling a single request would consume, as a ratio.
     *
     * A single bet that eats a large slice of a number's remaining ceiling in one
     * step is the signature of stake dumping, which is what
     * RiskAlertType::RapidExposureGrowth reports.
     *
     * @throws RiskConfigurationException
     */
    public function growthRatio(Money $increment, Money $ceiling): string
    {
        return $this->money->utilisation($increment, $ceiling);
    }

    /**
     * True when one request would move utilisation by more than the warning band's
     * worth of the ceiling in a single step.
     *
     * The warning percentage is reused deliberately rather than inventing a second
     * threshold: config/risk.php declares no dedicated growth threshold, and making
     * one up would be exactly the arbitrary production number this phase must not
     * introduce. This is documented as a derived, conservative reuse.
     *
     * @throws RiskConfigurationException
     */
    public function isRapidGrowth(Money $increment, Money $ceiling): bool
    {
        return bccomp(
            $this->growthRatio($increment, $ceiling),
            $this->warningRatio(),
            self::SCALE,
        ) >= 0;
    }

    /**
     * A full, log-safe grading of one assessment.
     *
     * @param  array<string, mixed>  $assessment  one entry from ExposureCalculator::assessAll()
     * @return array{
     *     exposure_type: string,
     *     level_before: string,
     *     level_after: string,
     *     utilisation_before: string,
     *     utilisation_after: string,
     *     warning_ratio: string,
     *     in_warning_band: bool,
     *     suspicious: bool,
     *     at_block_boundary: bool,
     *     rapid_growth: bool,
     *     requires_action: bool
     * }
     *
     * @throws RiskConfigurationException
     */
    public function grade(array $assessment, ?Money $increment = null, ?Money $ceiling = null): array
    {
        $before = $assessment['utilisation_before'] ?? null;
        $after = $assessment['utilisation_after'] ?? null;

        if (! is_string($before) || ! is_string($after)) {
            throw RiskConfigurationException::invalidLimit(
                'an exposure assessment without utilisation figures cannot be graded',
            );
        }

        $levelAfter = $this->fromUtilisation($after);

        return [
            'exposure_type' => is_string($assessment['exposure_type'] ?? null)
                ? $assessment['exposure_type']
                : ExposureType::PotentialPayout->value,
            'level_before' => $this->fromUtilisation($before)->value,
            'level_after' => $levelAfter->value,
            'utilisation_before' => $before,
            'utilisation_after' => $after,
            'warning_ratio' => $this->warningRatio(),
            'in_warning_band' => $this->isInWarningBand($after),
            'suspicious' => $this->isSuspicious($after),
            'at_block_boundary' => $this->isAtBlockBoundary($after),
            'rapid_growth' => $increment instanceof Money && $ceiling instanceof Money
                ? $this->isRapidGrowth($increment, $ceiling)
                : false,
            'requires_action' => $levelAfter->requiresAction(),
        ];
    }

    /**
     * The level at which configuration says a bet must be blocked automatically.
     *
     * @throws RiskConfigurationException
     */
    public function autoBlockLevel(): RiskLevel
    {
        return $this->configuredLevel('risk.auto_block.block_at_level');
    }

    /**
     * The level at which configuration says an alert must be raised.
     *
     * @throws RiskConfigurationException
     */
    public function alertLevel(): RiskLevel
    {
        return $this->configuredLevel('risk.alerts.notify_at_level');
    }

    /**
     * Whether automatic blocking is switched on.
     */
    public function autoBlockEnabled(): bool
    {
        return $this->config->get('risk.auto_block.enabled') === true
            && $this->config->get('risk.auto_block.block_bet') === true;
    }

    /**
     * Read a RiskLevel out of configuration.
     *
     * @throws RiskConfigurationException
     */
    private function configuredLevel(string $key): RiskLevel
    {
        $value = $this->config->get($key);

        if (! is_string($value) || trim($value) === '') {
            throw RiskConfigurationException::missingKey($key);
        }

        $level = RiskLevel::tryFrom(trim($value));

        if (! $level instanceof RiskLevel) {
            throw RiskConfigurationException::invalidKey(
                $key,
                'the configured value is not a recognised risk level',
                trim($value),
            );
        }

        return $level;
    }

    /**
     * All configured thresholds.
     *
     * @return array<string, mixed>
     *
     * @throws RiskConfigurationException
     */
    private function thresholds(): array
    {
        $thresholds = $this->config->get('risk.thresholds');

        if (! is_array($thresholds) || $thresholds === []) {
            throw RiskConfigurationException::missingKey('risk.thresholds');
        }

        /** @var array<string, mixed> $thresholds */
        return $thresholds;
    }

    /**
     * One configured threshold as an exact decimal string.
     *
     * The configuration stores ratios as PHP floats. They are rendered through a
     * fixed-precision sprintf so the float itself never takes part in a comparison,
     * only its decimal rendering does.
     *
     * @throws RiskConfigurationException
     */
    private function threshold(string $key): string
    {
        $thresholds = $this->thresholds();

        if (! array_key_exists($key, $thresholds)) {
            throw RiskConfigurationException::missingKey('risk.thresholds.'.$key);
        }

        $value = $thresholds[$key];

        if (is_string($value) || is_int($value)) {
            return $this->normalise((string) $value);
        }

        if (is_float($value) && is_finite($value)) {
            return $this->normalise(sprintf('%.'.self::SCALE.'F', $value));
        }

        throw RiskConfigurationException::invalidKey(
            'risk.thresholds.'.$key,
            'the threshold must be a number',
        );
    }

    /**
     * Validate and normalise an exact decimal ratio.
     *
     * @throws RiskConfigurationException
     */
    private function normalise(string $ratio): string
    {
        $raw = trim($ratio);

        if ($raw === '' || preg_match('/^[+-]?[0-9]{1,24}(?:\.[0-9]{1,12})?$/', $raw) !== 1) {
            throw RiskConfigurationException::invalidAmount(
                'a risk ratio must be an exact decimal string',
                $raw,
            );
        }

        if (bccomp($raw, '0', self::SCALE) < 0) {
            throw RiskConfigurationException::invalidAmount(
                'a risk ratio cannot be negative',
                $raw,
            );
        }

        return bcadd($raw, '0', self::SCALE);
    }
}
