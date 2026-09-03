<?php

namespace App\Enums;

use App\Exceptions\RiskConfigurationException;

/**
 * Severity band of a risk assessment.
 *
 * PHASE 3.1 NOTE
 * This enum already existed in the Phase 1 foundation and is consumed by
 * App\Models\AuditLog (the `audit_logs.risk_level` column casts to it) and by
 * config/risk.php ('levels', 'auto_block.block_at_level', 'alerts.notify_at_level'),
 * so its four cases and their string values are fixed by existing data and are
 * NOT changed here. Only additive, non-breaking members are introduced:
 *
 *  - fromUtilisation()  exact-decimal classification for money-derived ratios
 *  - fromConfiguredThresholds()  reads config/risk.php instead of hard-coding
 *  - atLeast()          ordering comparison used by the alert notify threshold
 *  - weight()           deterministic ordinal for atLeast()
 *
 * WHY A SECOND CLASSIFIER
 * fromScore() takes a float and is kept verbatim for backward compatibility with
 * existing callers. The Phase 3.1 risk engine must never route money through a
 * float, so exposure utilisation arrives as an exact decimal string and is
 * classified with bccomp in fromUtilisation(). The two entry points agree on the
 * same boundaries; fromUtilisation() is simply the exact-arithmetic path.
 */
enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Scale used when comparing utilisation ratios with bccomp.
     *
     * Six decimal places on a 0-1 ratio distinguishes one part per million of a
     * limit, which is far finer than any DECIMAL(20,2) money column can express.
     */
    private const RATIO_SCALE = 6;

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low Risk',
            self::Medium => 'Medium Risk',
            self::High => 'High Risk',
            self::Critical => 'Critical Risk',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'green',
            self::Medium => 'yellow',
            self::High => 'orange',
            self::Critical => 'red',
        };
    }

    public function requiresAction(): bool
    {
        return in_array($this, [self::High, self::Critical]);
    }

    public function blocksTransaction(): bool
    {
        return $this === self::Critical;
    }

    public function scoreRange(): array
    {
        return match ($this) {
            self::Low => [0.0, 0.3],
            self::Medium => [0.3, 0.6],
            self::High => [0.6, 0.8],
            self::Critical => [0.8, 1.0],
        };
    }

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score < 0.3 => self::Low,
            $score < 0.6 => self::Medium,
            $score < 0.8 => self::High,
            default => self::Critical,
        };
    }

    /**
     * Deterministic ordinal so severities can be compared without a float.
     *
     * Used by atLeast(); the numbers are internal ordering only and are never
     * persisted or exposed as a score.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    /**
     * True when this level is as severe as, or more severe than, $floor.
     *
     * config/risk.php expresses 'alerts.notify_at_level' and
     * 'auto_block.block_at_level' as a single level meaning "this and above",
     * which is exactly this comparison.
     */
    public function atLeast(self $floor): bool
    {
        return $this->weight() >= $floor->weight();
    }

    /**
     * Classify an exact-decimal utilisation ratio against fixed boundaries.
     *
     * $utilisation is a decimal STRING in the range '0' upwards, where '1' means
     * the configured ceiling is exactly consumed. Values above '1' are possible
     * only when a ceiling was already breached by data written outside the
     * engine, and they classify as Critical.
     *
     * The boundaries mirror fromScore(): [0,0.3) Low, [0.3,0.6) Medium,
     * [0.6,0.8) High, [0.8,inf) Critical. Comparison uses bccomp so '0.30' and
     * '0.3000001' are distinguished exactly and no binary rounding can move a
     * value across a boundary.
     *
     * @param  string  $utilisation  exact decimal ratio, e.g. '0.95'
     */
    public static function fromUtilisation(string $utilisation): self
    {
        $ratio = self::normaliseRatio($utilisation);

        return match (true) {
            bccomp($ratio, '0.3', self::RATIO_SCALE) < 0 => self::Low,
            bccomp($ratio, '0.6', self::RATIO_SCALE) < 0 => self::Medium,
            bccomp($ratio, '0.8', self::RATIO_SCALE) < 0 => self::High,
            default => self::Critical,
        };
    }

    /**
     * Classify against the boundaries declared in config/risk.php.
     *
     * The configuration file documents that 'thresholds.medium' and
     * 'thresholds.high' mirror this enum, and adds 'thresholds.critical'
     * (0.95 by default) which fromScore() does not model. Callers that must
     * honour the operator-configured bands use this method; callers that want
     * the enum's own fixed bands use fromUtilisation().
     *
     * A missing or non-monotonic threshold set is a configuration fault and is
     * reported rather than silently replaced with a guess, because guessing here
     * would change what "critical" means for real money.
     *
     * @param  string  $utilisation  exact decimal ratio, e.g. '0.95'
     * @param  array<string, mixed>  $thresholds  config('risk.thresholds')
     *
     * @throws RiskConfigurationException
     */
    public static function fromConfiguredThresholds(string $utilisation, array $thresholds): self
    {
        $medium = self::thresholdToDecimal($thresholds, 'medium');
        $high = self::thresholdToDecimal($thresholds, 'high');
        $critical = self::thresholdToDecimal($thresholds, 'critical');

        if (bccomp($medium, $high, self::RATIO_SCALE) >= 0
            || bccomp($high, $critical, self::RATIO_SCALE) >= 0) {
            throw RiskConfigurationException::withCode(
                'risk_thresholds_not_ascending',
                'Risk thresholds must satisfy medium < high < critical.',
                ['medium' => $medium, 'high' => $high, 'critical' => $critical],
            );
        }

        $ratio = self::normaliseRatio($utilisation);

        return match (true) {
            bccomp($ratio, $medium, self::RATIO_SCALE) < 0 => self::Low,
            bccomp($ratio, $high, self::RATIO_SCALE) < 0 => self::Medium,
            bccomp($ratio, $critical, self::RATIO_SCALE) < 0 => self::High,
            default => self::Critical,
        };
    }

    /**
     * Validate and normalise an exact decimal ratio string.
     *
     * @throws RiskConfigurationException when the value is not an exact decimal
     */
    private static function normaliseRatio(string $utilisation): string
    {
        $raw = trim($utilisation);

        if ($raw === '' || preg_match('/^[+-]?[0-9]{1,24}(?:\.[0-9]{1,12})?$/', $raw) !== 1) {
            throw RiskConfigurationException::withCode(
                'risk_utilisation_malformed',
                'Risk utilisation must be an exact decimal string.',
            );
        }

        if (! extension_loaded('bcmath')) {
            throw RiskConfigurationException::withCode(
                'risk_exact_arithmetic_unavailable',
                'The bcmath extension is required to classify risk utilisation exactly. '
                .'Refusing to fall back to binary floating point.',
            );
        }

        // A negative ratio cannot occur from a non-negative exposure over a
        // positive ceiling; treat it as a fault instead of clamping silently.
        if (bccomp($raw, '0', self::RATIO_SCALE) < 0) {
            throw RiskConfigurationException::withCode(
                'risk_utilisation_negative',
                'Risk utilisation cannot be negative.',
                ['utilisation' => $raw],
            );
        }

        return bcadd($raw, '0', self::RATIO_SCALE);
    }

    /**
     * Read one threshold from configuration as an exact decimal string.
     *
     * config/risk.php stores thresholds as PHP floats because they are ratios,
     * not money. They are converted here through a fixed-precision sprintf so
     * that the float never participates in a comparison; only its decimal
     * rendering does.
     *
     * @param  array<string, mixed>  $thresholds
     *
     * @throws RiskConfigurationException
     */
    private static function thresholdToDecimal(array $thresholds, string $key): string
    {
        if (! array_key_exists($key, $thresholds)) {
            throw RiskConfigurationException::missingKey('risk.thresholds.'.$key);
        }

        $value = $thresholds[$key];

        if (is_string($value)) {
            return self::normaliseRatio($value);
        }

        if (is_int($value)) {
            return self::normaliseRatio((string) $value);
        }

        if (is_float($value) && is_finite($value)) {
            return self::normaliseRatio(sprintf('%.'.self::RATIO_SCALE.'F', $value));
        }

        throw RiskConfigurationException::withCode(
            'risk_threshold_invalid_type',
            sprintf('Risk threshold "%s" must be a number.', $key),
            ['key' => $key],
        );
    }
}
