<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\BetType;
use App\Enums\ExposureType;
use App\Enums\NumberLimitStatus;
use App\Enums\RiskAlertType;
use App\Enums\RiskLevel;
use App\Exceptions\HotNumberException;
use App\Exceptions\RiskConfigurationException;
use App\Models\NumberLimit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Decides whether a number is too hot to keep selling, or blocked outright.
 *
 * NO NEW TABLE
 * There is no hot_numbers table and none is created. Heat is DERIVED, every time,
 * from data that already exists:
 *
 *   number_limits.current_payout_exposure  against maximum_payout_exposure
 *   number_limits.current_amount           against max_amount
 *   number_limits.status                   the operator's own hold
 *   config('risk.blocked_numbers.global')  the static block list
 *
 * Deriving heat instead of storing it means a hot number cannot go stale, cannot
 * disagree with the exposure counters, and needs no invalidation.
 *
 * BLOCKED VERSUS HOT — TWO DIFFERENT THINGS
 * blocked  the number must not sell at all, regardless of amount. Causes: it is on
 *          the global blocked list, or its limit row is Suspended or Removed, or
 *          its ceiling is fully consumed while
 *          config('risk.exposure.block_on_exceeded') is true.
 * hot      the number still has capacity but utilisation has passed the suspicious
 *          threshold. Selling continues; the condition is loud, not fatal.
 *
 * THRESHOLD SOURCE
 * The heat boundary is config('risk.thresholds.suspicious') (0.8, overridable via
 * RISK_SUSPICIOUS_THRESHOLD). No new threshold is invented and no arbitrary number
 * is hard-coded here; a missing threshold is reported by RiskLevelCalculator rather
 * than replaced with a guess.
 *
 * NUMBERS ARE STRINGS
 * Comparison against the blocked list is strict string comparison, honouring
 * config('risk.blocked_numbers.compare_as_string'), so '007' never matches '7'.
 */
class HotNumberService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly NumberNormalizationService $numbers,
        private readonly NumberLimitResolver $resolver,
        private readonly ExposureCalculator $exposure,
        private readonly RiskLevelCalculator $levels,
    ) {
    }

    /**
     * Whether a number carries enough action to be considered hot.
     *
     * A missing limit row is NOT hot: there is no exposure recorded against it, and
     * calling it hot would conflate "no capacity configured" (a MISSING_LIMIT
     * rejection) with "too much action" (a heat warning). The missing-limit case is
     * handled by the resolver and the decision service.
     *
     * @throws RiskConfigurationException when the number or configuration is invalid
     */
    public function isHot(int $drawId, BetType $betType, string $rawNumber): bool
    {
        $limit = $this->resolver->resolveCanonical($drawId, $betType, $rawNumber);

        return $limit instanceof NumberLimit && $this->isLimitHot($limit);
    }

    /**
     * Whether a resolved limit row is hot.
     *
     * @throws RiskConfigurationException
     */
    public function isLimitHot(NumberLimit $limit): bool
    {
        return $this->heatOf($limit)['is_hot'];
    }

    /**
     * Whether a number must not sell at all.
     *
     * @throws RiskConfigurationException
     */
    public function isBlocked(int $drawId, BetType $betType, string $rawNumber): bool
    {
        $number = $this->numbers->normalize($rawNumber, $betType);

        if ($this->isGloballyBlocked($number)) {
            return true;
        }

        $limit = $this->resolver->resolve($drawId, $betType, $number);

        if (! $limit instanceof NumberLimit) {
            // No row is not a block; it is a missing limit. The distinction is kept
            // so the rejection reason code stays accurate.
            return false;
        }

        return $this->isLimitBlocked($limit);
    }

    /**
     * Whether a resolved limit row must not sell.
     *
     * @throws RiskConfigurationException
     */
    public function isLimitBlocked(NumberLimit $limit): bool
    {
        if ($this->resolver->statusOf($limit)->isBlocking()) {
            return true;
        }

        if (! $this->blockOnExceeded()) {
            return false;
        }

        foreach (ExposureType::enforced() as $type) {
            if ($this->exposure->remainingCapacity($limit, $type)->isZero()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a canonical number sits on the static global block list.
     */
    public function isGloballyBlocked(string $number): bool
    {
        if ($this->config->get('risk.blocked_numbers.enabled') !== true) {
            return false;
        }

        $blocked = $this->config->get('risk.blocked_numbers.global');

        if (! is_array($blocked) || $blocked === []) {
            return false;
        }

        return $this->numbers->containsNumber($blocked, $number);
    }

    /**
     * Full derived heat and block picture for a number.
     *
     * This is the method the engine calls: one query for the limit row, then pure
     * arithmetic. It never writes, never locks and never throws for a blocked or hot
     * number; it REPORTS. Turning the report into an exception is
     * assertSellable()'s job, so a caller that only wants to display heat is not
     * forced to catch anything.
     *
     * @return array{
     *     draw_id: int,
     *     bet_type: string,
     *     number: string,
     *     limit_found: bool,
     *     number_limit_id: int|null,
     *     status: string|null,
     *     is_globally_blocked: bool,
     *     is_blocked: bool,
     *     is_hot: bool,
     *     block_reason_code: string|null,
     *     hot_threshold: string,
     *     utilisation: string|null,
     *     tightest_exposure_type: string|null,
     *     level: string,
     *     alerts: list<string>
     * }
     *
     * @throws RiskConfigurationException
     */
    public function evaluate(int $drawId, BetType $betType, string $rawNumber): array
    {
        $number = $this->numbers->normalize($rawNumber, $betType);
        $globallyBlocked = $this->isGloballyBlocked($number);
        $limit = $this->resolver->resolve($drawId, $betType, $number);

        $report = [
            'draw_id' => $drawId,
            'bet_type' => $betType->value,
            'number' => $number,
            'limit_found' => $limit instanceof NumberLimit,
            'number_limit_id' => $limit instanceof NumberLimit ? (int) $limit->getKey() : null,
            'status' => null,
            'is_globally_blocked' => $globallyBlocked,
            'is_blocked' => $globallyBlocked,
            'is_hot' => false,
            'block_reason_code' => $globallyBlocked ? HotNumberException::REASON_BLOCKED : null,
            'hot_threshold' => $this->hotThreshold(),
            'utilisation' => null,
            'tightest_exposure_type' => null,
            'level' => RiskLevel::Low->value,
            'alerts' => [],
        ];

        if (! $limit instanceof NumberLimit) {
            return $report;
        }

        $status = $this->resolver->statusOf($limit);
        $report['status'] = $status->value;

        if ($status->isBlocking()) {
            $report['is_blocked'] = true;
            $report['block_reason_code'] ??= $status->rejectionReasonCode();
        }

        $heat = $this->heatOf($limit);

        $report['is_hot'] = $heat['is_hot'];
        $report['utilisation'] = $heat['utilisation'];
        $report['tightest_exposure_type'] = $heat['exposure_type'];
        $report['level'] = $heat['level'];

        if ($heat['capacity_consumed'] && $this->blockOnExceeded()) {
            $report['is_blocked'] = true;
            $report['block_reason_code'] ??= NumberLimitStatus::Exceeded->rejectionReasonCode();
        }

        $report['alerts'] = $this->alertsFor($heat, $report['is_blocked']);

        return $report;
    }

    /**
     * Refuse a number that must not sell, and optionally refuse a hot one.
     *
     * $rejectHotNumbers is false by default because heat is a warning, not a
     * capacity refusal: a hot number with room left is still sellable, and blocking
     * it would reject bets the configured ceilings permit. A caller that wants the
     * stricter behaviour opts in explicitly.
     *
     * @throws HotNumberException when the number must not sell
     * @throws RiskConfigurationException when the number or configuration is invalid
     */
    public function assertSellable(
        int $drawId,
        BetType $betType,
        string $rawNumber,
        bool $rejectHotNumbers = false,
    ): array {
        $report = $this->evaluate($drawId, $betType, $rawNumber);

        if ($report['is_globally_blocked']) {
            throw HotNumberException::globallyBlocked($report['number'], $betType->value);
        }

        if ($report['is_blocked']) {
            throw HotNumberException::blockedByStatus(
                $report['number'],
                $betType->value,
                $report['status'] === null
                    ? NumberLimitStatus::Exceeded
                    : NumberLimitStatus::from($report['status']),
                $report['number_limit_id'],
                $drawId,
            );
        }

        if ($rejectHotNumbers && $report['is_hot']) {
            throw HotNumberException::tooHot(
                $report['number'],
                $betType->value,
                (string) $report['utilisation'],
                $report['hot_threshold'],
                $report['number_limit_id'],
                $drawId,
            );
        }

        return $report;
    }

    /**
     * Every hot number on a draw, derived on demand.
     *
     * Iterates active limit rows for the draw in primary-key order so the result is
     * deterministic. Intended for an operator dashboard, not for the bet path.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RiskConfigurationException
     */
    public function hotNumbersForDraw(int $drawId, ?BetType $betType = null): array
    {
        $query = NumberLimit::query()
            ->where('draw_id', $drawId)
            ->orderBy('id');

        if ($betType instanceof BetType) {
            $query->where('bet_type', $betType->value);
        }

        $hot = [];

        foreach ($query->cursor() as $limit) {
            $heat = $this->heatOf($limit);

            if (! $heat['is_hot']) {
                continue;
            }

            $hot[] = [
                'number_limit_id' => (int) $limit->getKey(),
                'number' => (string) $limit->getAttribute('number'),
                'bet_type' => $heat['bet_type'],
                'utilisation' => $heat['utilisation'],
                'exposure_type' => $heat['exposure_type'],
                'level' => $heat['level'],
                'capacity_consumed' => $heat['capacity_consumed'],
            ];
        }

        return $hot;
    }

    /**
     * The utilisation ratio above which a number counts as hot.
     *
     * @throws RiskConfigurationException
     */
    public function hotThreshold(): string
    {
        $thresholds = $this->config->get('risk.thresholds');

        if (! is_array($thresholds) || ! array_key_exists('suspicious', $thresholds)) {
            throw RiskConfigurationException::missingKey('risk.thresholds.suspicious');
        }

        $value = $thresholds['suspicious'];

        if (is_string($value) || is_int($value)) {
            return bcadd((string) $value, '0', MoneyExposureCalculator::RATIO_SCALE);
        }

        if (is_float($value) && is_finite($value)) {
            return bcadd(
                sprintf('%.'.MoneyExposureCalculator::RATIO_SCALE.'F', $value),
                '0',
                MoneyExposureCalculator::RATIO_SCALE,
            );
        }

        throw RiskConfigurationException::invalidKey(
            'risk.thresholds.suspicious',
            'the suspicious threshold must be a number',
        );
    }

    /**
     * Whether a consumed ceiling stops the number selling.
     */
    public function blockOnExceeded(): bool
    {
        return $this->config->get('risk.exposure.block_on_exceeded') === true;
    }

    /**
     * Derived heat of one limit row across every enforced exposure type.
     *
     * The row is hot when its worst utilisation has reached the hot threshold, and
     * its capacity is consumed when any enforced ceiling has zero headroom left.
     *
     * @return array{
     *     bet_type: string,
     *     is_hot: bool,
     *     capacity_consumed: bool,
     *     utilisation: string,
     *     exposure_type: string,
     *     level: string
     * }
     *
     * @throws RiskConfigurationException
     */
    private function heatOf(NumberLimit $limit): array
    {
        $threshold = $this->hotThreshold();
        $scale = MoneyExposureCalculator::RATIO_SCALE;

        $worstUtilisation = bcadd('0', '0', $scale);
        $worstType = ExposureType::PotentialPayout;
        $consumed = false;

        foreach (ExposureType::enforced() as $type) {
            $utilisation = $this->exposure->utilisation($limit, $type);

            if (bccomp($utilisation, $worstUtilisation, $scale) > 0) {
                $worstUtilisation = $utilisation;
                $worstType = $type;
            }

            if ($this->exposure->remainingCapacity($limit, $type)->isZero()) {
                $consumed = true;
            }
        }

        $betType = $limit->getAttribute('bet_type');

        return [
            'bet_type' => $betType instanceof BetType ? $betType->value : (string) $betType,
            'is_hot' => bccomp($worstUtilisation, $threshold, $scale) >= 0,
            'capacity_consumed' => $consumed,
            'utilisation' => $worstUtilisation,
            'exposure_type' => $worstType->value,
            'level' => $this->levels->fromUtilisation($worstUtilisation)->value,
        ];
    }

    /**
     * Alert types implied by a heat reading.
     *
     * @param  array<string, mixed>  $heat
     * @return list<string>
     *
     * @throws RiskConfigurationException
     */
    private function alertsFor(array $heat, bool $blocked): array
    {
        $alerts = [];
        $utilisation = is_string($heat['utilisation'] ?? null) ? $heat['utilisation'] : null;

        if ($blocked && ($heat['capacity_consumed'] ?? false) === true) {
            $alerts[] = RiskAlertType::LimitExceeded->value;
        }

        if (($heat['is_hot'] ?? false) === true) {
            $alerts[] = RiskAlertType::HotNumber->value;
        }

        if ($utilisation !== null && $this->levels->isInWarningBand($utilisation)
            && ! in_array(RiskAlertType::LimitExceeded->value, $alerts, true)) {
            $alerts[] = RiskAlertType::LimitNear->value;
        }

        $level = $heat['level'] ?? RiskLevel::Low->value;

        if ($level === RiskLevel::Critical->value) {
            $alerts[] = RiskAlertType::CriticalExposure->value;
        } elseif ($level === RiskLevel::High->value) {
            $alerts[] = RiskAlertType::HighExposure->value;
        }

        return array_values(array_unique($alerts));
    }
}
