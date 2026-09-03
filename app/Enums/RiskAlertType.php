<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kind of risk condition an alert reports.
 *
 * PHASE 3.1 STORAGE NOTE
 * There is no risk_alerts table in the audited schema and this phase is not
 * allowed to add one, so an alert is persisted as an append-only row in
 * `audit_logs` with action = AuditAction::SecurityAlert, risk_level = the
 * severity, auditable = the App\Models\NumberLimit it concerns, and this type
 * recorded inside `audit_logs.metadata` under the key 'risk_alert_type'.
 *
 * That is why the values here are plain lowercase snake_case strings: they are
 * written into a JSON column, not into an enum-cast database column, and must
 * stay stable for log queries and dashboards built later.
 *
 * AuditAction has no dedicated 'risk_alert' case and AuditAction is outside the
 * Phase 3.1 file list, so SecurityAlert is reused rather than invented.
 */
enum RiskAlertType: string
{
    /**
     * Exposure crossed the warning band (config risk.exposure.warning_percentage)
     * but the ceiling is not yet consumed. Selling continues.
     */
    case LimitNear = 'limit_near';

    /**
     * The ceiling is consumed or would be consumed. Selling on this number stops
     * when config risk.exposure.block_on_exceeded is true.
     */
    case LimitExceeded = 'limit_exceeded';

    /**
     * The number is drawing disproportionate action relative to its own limit
     * and is treated as hot by App\Services\Risk\HotNumberService.
     */
    case HotNumber = 'hot_number';

    /**
     * Aggregate liability on the number reached the High band.
     */
    case HighExposure = 'high_exposure';

    /**
     * Aggregate liability on the number reached the Critical band.
     */
    case CriticalExposure = 'critical_exposure';

    /**
     * A single request would move utilisation by an unusually large step, which
     * is the signature of stake dumping just before a draw closes.
     */
    case RapidExposureGrowth = 'rapid_exposure_growth';

    public function label(): string
    {
        return match ($this) {
            self::LimitNear => 'Limit Near',
            self::LimitExceeded => 'Limit Exceeded',
            self::HotNumber => 'Hot Number',
            self::HighExposure => 'High Exposure',
            self::CriticalExposure => 'Critical Exposure',
            self::RapidExposureGrowth => 'Rapid Exposure Growth',
        };
    }

    /**
     * Default severity carried by this alert kind.
     *
     * The risk engine may raise the severity it actually reports (an assessment
     * that computes Critical utilisation reports Critical), but never lowers it
     * below this floor, so a LimitExceeded alert can never be filed as Low.
     */
    public function defaultLevel(): RiskLevel
    {
        return match ($this) {
            self::LimitNear => RiskLevel::Medium,
            self::HotNumber => RiskLevel::Medium,
            self::HighExposure => RiskLevel::High,
            self::RapidExposureGrowth => RiskLevel::High,
            self::LimitExceeded => RiskLevel::Critical,
            self::CriticalExposure => RiskLevel::Critical,
        };
    }

    /**
     * Whether this condition, on its own, means the number must stop selling.
     *
     * Only a consumed ceiling and a hard block do that. High or critical
     * utilisation is loud but still sellable until the ceiling is actually
     * reached, which is the difference between a warning and a rejection.
     */
    public function stopsSelling(): bool
    {
        return $this === self::LimitExceeded;
    }

    /**
     * Key used for this alert type inside audit_logs.metadata.
     */
    public static function metadataKey(): string
    {
        return 'risk_alert_type';
    }
}
