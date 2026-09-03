<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\RiskDecision;
use App\Enums\RiskLevel;
use App\Exceptions\HotNumberException;
use App\Exceptions\NumberLimitExceededException;
use App\Exceptions\RiskConfigurationException;
use App\Exceptions\RiskException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Turns a risk assessment, or a risk failure, into a decision with a stable reason.
 *
 * NO HIDDEN REJECTIONS
 * Every refusal produced here carries a machine-readable reason code and a
 * human-readable message. There is no path that returns Reject with a null reason,
 * and there is no path that silently downgrades a refusal to an allow. The codes are
 * stable API surface:
 *
 *   NUMBER_LIMIT_EXCEEDED  a ceiling would be breached
 *   NUMBER_BLOCKED         the number must not sell (block list, or Suspended limit)
 *   HOT_NUMBER             the number passed the heat threshold and heat is enforced
 *   INVALID_LIMIT          a limit row exists but is unusable
 *   INVALID_AMOUNT         the stake is malformed, non-positive, or over-precise
 *   INVALID_NUMBER         the number is malformed or of an unsupported length
 *   MISSING_LIMIT          no limit row and no reservable capacity
 *   NOT_RESERVABLE         capacity exists on paper but cannot be atomically held
 *   RISK_MISCONFIGURED     required configuration is absent or unusable
 *   RISK_EVALUATION_FAILED an unexpected risk-domain failure
 *   RISK_ENGINE_DISABLED   config('risk.enabled') is false
 *
 * FAIL CLOSED
 * config('risk.fail_open') is false in the audited configuration. When the evaluator
 * itself fails, the decision is Reject. If an operator sets fail_open to true, the
 * decision becomes Allow but the reason code and the message are still returned
 * verbatim, so the risk taken is recorded rather than erased.
 *
 * WHAT THIS CLASS IS NOT
 * It is not a controller, it defines no route, it builds no HTTP response and it maps
 * nothing to a status code. It performs no query and takes no lock: it reads a
 * structure produced by NumberLimitEngine and returns a verdict.
 */
class RiskDecisionService
{
    public const REASON_ALLOWED = 'ALLOWED';

    public const REASON_NOT_RESERVABLE = 'NOT_RESERVABLE';

    public const REASON_ENGINE_DISABLED = 'RISK_ENGINE_DISABLED';

    public const REASON_EVALUATION_FAILED = 'RISK_EVALUATION_FAILED';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly RiskLevelCalculator $levels,
    ) {
    }

    /**
     * Whether the risk engine is switched on.
     */
    public function isEnabled(): bool
    {
        return $this->config->get('risk.enabled') === true;
    }

    /**
     * Whether an evaluator failure should still allow the bet.
     */
    public function failsOpen(): bool
    {
        return $this->config->get('risk.fail_open') === true;
    }

    /**
     * Decide on the outcome of NumberLimitEngine::assess().
     *
     * $requireReservable expresses what the caller intends to do next. A caller about
     * to take money needs capacity it can actually hold, so a number with no limit row
     * is refused with MISSING_LIMIT rather than allowed against a configuration value
     * that cannot be locked. A caller producing a quote can pass false.
     *
     * @param  array<string, mixed>  $assessment  output of NumberLimitEngine::assess()
     * @return array{
     *     decision: string,
     *     allowed: bool,
     *     reason_code: string,
     *     reason: string,
     *     level: string,
     *     number: string|null,
     *     bet_type: string|null,
     *     draw_id: int|null,
     *     number_limit_id: int|null,
     *     limit_source: string|null,
     *     reservable: bool,
     *     hot: bool,
     *     level_before: string,
     *     requires_override: bool,
     *     override_permission: string|null,
     *     details: array<string, mixed>
     * }
     */
    public function decide(array $assessment, bool $requireReservable = true): array
    {
        if (! $this->isEnabled()) {
            // Disabled means "do not evaluate", not "reject everything". The reason is
            // still reported so the bypass is visible in logs.
            return $this->verdict(
                RiskDecision::Allow,
                self::REASON_ENGINE_DISABLED,
                'Risk evaluation is disabled by configuration; no ceiling was checked.',
                $assessment,
            );
        }

        $errors = is_array($assessment['errors'] ?? null) ? $assessment['errors'] : [];
        $level = $this->levelOf($assessment);

        // 1. Hard blocks come first: they are independent of any amount.
        if (($assessment['blocked'] ?? false) === true) {
            $code = is_string($assessment['block_reason_code'] ?? null)
                ? $assessment['block_reason_code']
                : HotNumberException::REASON_BLOCKED;

            return $this->verdict(
                RiskDecision::Reject,
                $code,
                sprintf(
                    'Number %s is not available for this draw.',
                    is_string($assessment['number'] ?? null) ? $assessment['number'] : 'requested',
                ),
                $assessment,
            );
        }

        // 2. A missing or unusable limit is never read as unlimited.
        if (($assessment['limit_source'] ?? 'none') === 'none' || $errors !== []) {
            return $this->verdict(
                RiskDecision::Reject,
                RiskConfigurationException::REASON_MISSING_LIMIT,
                $this->firstError($errors)
                    ?? 'No usable number limit is configured for this number, so the bet cannot be '
                       .'accepted. Refusing to treat the number as unlimited.',
                $assessment,
            );
        }

        if (($assessment['limit_source'] ?? null) === 'config_fallback' && $requireReservable) {
            return $this->verdict(
                RiskDecision::Reject,
                RiskConfigurationException::REASON_MISSING_LIMIT,
                'Only a configured fallback ceiling is available for this number. A configuration '
                .'value cannot be locked or accumulated, so capacity cannot be reserved.',
                $assessment,
            );
        }

        // 3. Ceiling arithmetic. This is exact and is the authoritative refusal.
        if (($assessment['fits'] ?? false) !== true) {
            return $this->verdict(
                RiskDecision::Reject,
                NumberLimitExceededException::REASON_CODE,
                $this->describeBreach($assessment),
                $assessment,
            );
        }

        if ($requireReservable && ($assessment['reservable'] ?? false) !== true) {
            return $this->verdict(
                RiskDecision::Reject,
                self::REASON_NOT_RESERVABLE,
                'Capacity appears available but cannot be atomically reserved for this number.',
                $assessment,
            );
        }

        // 4. Severity. Automatic blocking and manual review are configuration-driven.
        try {
            $blockAt = $this->levels->autoBlockLevel();
            $reviewAt = $this->levels->alertLevel();
        } catch (RiskConfigurationException $exception) {
            return $this->fromException($exception, $assessment);
        }

        // Automatic blocking is driven by the band the number is ALREADY in, not by the
        // band it would reach. Blocking on the projected band would refuse a bet the
        // configured ceiling explicitly permits: a 150.00 stake taking a number from
        // 800.00 to 950.00 of a 1000.00 ceiling projects to 95% utilisation, which is
        // the configured critical threshold, yet it fits and must be allowed. The bet
        // AFTER it is the one automatic blocking is for.
        $levelBefore = $this->levelBeforeOf($assessment);

        if ($this->levels->autoBlockEnabled() && $levelBefore->atLeast($blockAt)) {
            return $this->verdict(
                RiskDecision::Reject,
                NumberLimitExceededException::REASON_CODE,
                sprintf(
                    'Exposure already carried on this number is %s, at or above the configured '
                    .'automatic block level of %s, so no further liability is accepted.',
                    $levelBefore->label(),
                    $blockAt->label(),
                ),
                $assessment,
            );
        }

        // Heat is an ALERT signal, not a refusal. A hot number with capacity left must
        // still sell, because the configured ceiling is the authority on how much may be
        // sold; refusing at risk.thresholds.suspicious would silently shrink every
        // ceiling to 80% of its configured value. The heat is reported in the verdict
        // and RiskAlertService records it, so the operator sees it without the player
        // being refused a bet the configuration permits.
        //
        // The one exception is a number that is already at or above the block level
        // while risk.auto_block.enabled is false. Allowing that silently would ignore
        // the operator's own severity configuration, so it is escalated to review
        // instead of being refused outright or waved through.
        if (! $this->levels->autoBlockEnabled() && $levelBefore->atLeast($blockAt)) {
            return $this->verdict(
                RiskDecision::Review,
                HotNumberException::REASON_HOT,
                sprintf(
                    'Exposure already carried on this number is %s, at or above the configured '
                    .'block level of %s, but automatic blocking is disabled, so this bet needs '
                    .'manual review.',
                    $levelBefore->label(),
                    $blockAt->label(),
                ),
                $assessment,
            );
        }

        $reason = 'Exposure remains within the configured ceilings for this number.';

        if (($assessment['hot'] ?? false) === true) {
            $reason .= sprintf(
                ' The number is running hot: exposure has passed the configured suspicious '
                .'threshold and is graded %s, and an alert was recorded, but capacity remains so '
                .'the bet is accepted.',
                $level->label(),
            );
        } elseif ($level->atLeast($reviewAt)) {
            $reason .= sprintf(' Projected exposure is graded %s.', $level->label());
        }

        return $this->verdict(
            RiskDecision::Allow,
            self::REASON_ALLOWED,
            $reason,
            $assessment,
        );
    }

    /**
     * Decide from a caught risk-domain exception.
     *
     * The exception's own reason code is preserved verbatim; nothing is re-labelled.
     * When fail_open is true the verdict becomes Allow, but the reason code and
     * message survive so the bypass is auditable.
     *
     * @param  array<string, mixed>  $assessment  best-known assessment context, may be empty
     * @return array<string, mixed>
     */
    public function fromException(RiskException $exception, array $assessment = []): array
    {
        $decision = $this->failsOpen() ? RiskDecision::Allow : RiskDecision::Reject;

        $verdict = $this->verdict(
            $decision,
            $exception->reasonCode(),
            $exception->getMessage(),
            $assessment,
        );

        $verdict['details']['exception'] = $exception->toArray();

        return $verdict;
    }

    /**
     * Decide from an unexpected non-risk failure.
     *
     * Kept separate from fromException() so a genuine bug is never dressed up as a
     * domain rejection with a specific business code.
     *
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    public function fromUnexpectedFailure(\Throwable $exception, array $assessment = []): array
    {
        $decision = $this->failsOpen() ? RiskDecision::Allow : RiskDecision::Reject;

        $verdict = $this->verdict(
            $decision,
            self::REASON_EVALUATION_FAILED,
            'Risk evaluation could not be completed.',
            $assessment,
        );

        // Only the class name is exposed. The raw message of an unexpected exception
        // can carry query text or connection details and is not safe to return.
        $verdict['details']['exception'] = ['type' => $exception::class];

        return $verdict;
    }

    /**
     * Combine per-number verdicts into one verdict for a whole multi-number request.
     *
     * The most restrictive outcome wins, and the reason returned is the reason of the
     * first entry that produced it, so the caller learns which number caused the
     * refusal rather than a generic summary.
     *
     * @param  array<string, array<string, mixed>>  $verdicts  keyed by canonical number
     * @return array<string, mixed>
     */
    public function combine(array $verdicts): array
    {
        if ($verdicts === []) {
            return [
                'decision' => RiskDecision::Reject->value,
                'allowed' => false,
                'reason_code' => self::REASON_EVALUATION_FAILED,
                'reason' => 'No numbers were assessed, so nothing can be allowed.',
                'level' => RiskLevel::Low->value,
                'number' => null,
                'bet_type' => null,
                'draw_id' => null,
                'number_limit_id' => null,
                'limit_source' => null,
                'reservable' => false,
                'hot' => false,
                'level_before' => RiskLevel::Low->value,
                'requires_override' => false,
                'override_permission' => null,
                'details' => ['per_number' => []],
            ];
        }

        $decisions = [];
        $worstLevel = RiskLevel::Low;

        foreach ($verdicts as $verdict) {
            $decision = RiskDecision::tryFrom((string) ($verdict['decision'] ?? '')) ?? RiskDecision::Reject;
            $decisions[] = $decision;

            $level = RiskLevel::tryFrom((string) ($verdict['level'] ?? '')) ?? RiskLevel::Low;

            if ($level->atLeast($worstLevel)) {
                $worstLevel = $level;
            }
        }

        $overall = RiskDecision::mostRestrictive($decisions);

        // Attribute the outcome to the first number that produced it.
        $governing = null;

        foreach ($verdicts as $verdict) {
            if ((string) ($verdict['decision'] ?? '') === $overall->value) {
                $governing = $verdict;

                break;
            }
        }

        $governing ??= reset($verdicts);

        return [
            'decision' => $overall->value,
            'allowed' => $overall->isAllowed(),
            'reason_code' => is_string($governing['reason_code'] ?? null)
                ? $governing['reason_code']
                : self::REASON_EVALUATION_FAILED,
            'reason' => is_string($governing['reason'] ?? null)
                ? $governing['reason']
                : 'Risk evaluation produced no reason.',
            'level' => $worstLevel->value,
            'number' => $governing['number'] ?? null,
            'bet_type' => $governing['bet_type'] ?? null,
            'draw_id' => $governing['draw_id'] ?? null,
            'number_limit_id' => $governing['number_limit_id'] ?? null,
            'limit_source' => $governing['limit_source'] ?? null,
            'reservable' => ($governing['reservable'] ?? false) === true,
            'hot' => ($governing['hot'] ?? false) === true,
            'level_before' => is_string($governing['level_before'] ?? null)
                ? $governing['level_before']
                : RiskLevel::Low->value,
            'requires_override' => $overall->blocksBet() && $this->overrideAllowed(),
            'override_permission' => $this->overridePermission(),
            'details' => ['per_number' => $verdicts],
        ];
    }

    /**
     * Whether configuration permits an operator override of a refusal.
     */
    public function overrideAllowed(): bool
    {
        return $this->config->get('risk.override.allowed') === true;
    }

    /**
     * The permission an operator needs to override, or null when overrides are off.
     */
    public function overridePermission(): ?string
    {
        if (! $this->overrideAllowed()) {
            return null;
        }

        $permission = $this->config->get('risk.override.required_permission');

        return is_string($permission) && $permission !== '' ? $permission : null;
    }

    /**
     * Build the verdict structure.
     *
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    private function verdict(
        RiskDecision $decision,
        string $reasonCode,
        string $reason,
        array $assessment,
    ): array {
        return [
            'decision' => $decision->value,
            'allowed' => $decision->isAllowed(),
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'level' => $this->levelOf($assessment)->value,
            'number' => is_string($assessment['number'] ?? null) ? $assessment['number'] : null,
            'bet_type' => is_string($assessment['bet_type'] ?? null) ? $assessment['bet_type'] : null,
            'draw_id' => is_int($assessment['draw_id'] ?? null) ? $assessment['draw_id'] : null,
            'number_limit_id' => is_int($assessment['number_limit_id'] ?? null)
                ? $assessment['number_limit_id']
                : null,
            'limit_source' => is_string($assessment['limit_source'] ?? null)
                ? $assessment['limit_source']
                : null,
            'reservable' => ($assessment['reservable'] ?? false) === true,
            'hot' => ($assessment['hot'] ?? false) === true,
            'level_before' => $this->levelBeforeOf($assessment)->value,
            'requires_override' => $decision->blocksBet() && $this->overrideAllowed(),
            'override_permission' => $this->overridePermission(),
            'details' => [
                'level_before' => is_string($assessment['level_before'] ?? null)
                    ? $assessment['level_before']
                    : null,
                'stake' => $assessment['stake'] ?? null,
                'multiplier' => $assessment['multiplier'] ?? null,
                'potential_payout' => $assessment['potential_payout'] ?? null,
                'tightest_exposure_type' => $assessment['tightest_exposure_type'] ?? null,
                'exposures' => $assessment['exposures'] ?? [],
                'grades' => $assessment['grades'] ?? [],
                'alerts' => $assessment['alerts'] ?? [],
                'errors' => $assessment['errors'] ?? [],
            ],
        ];
    }

    /**
     * The severity band recorded in an assessment, defaulting to Low.
     *
     * @param  array<string, mixed>  $assessment
     */
    private function levelOf(array $assessment): RiskLevel
    {
        $level = $assessment['level'] ?? null;

        return is_string($level)
            ? (RiskLevel::tryFrom($level) ?? RiskLevel::Low)
            : RiskLevel::Low;
    }

    /**
     * The severity band the number is already in, before the candidate bet.
     *
     * Falls back to the projected band when the engine did not report one, so a
     * missing key can never quietly downgrade a critical number to Low.
     *
     * @param  array<string, mixed>  $assessment
     */
    private function levelBeforeOf(array $assessment): RiskLevel
    {
        $level = $assessment['level_before'] ?? null;

        if (is_string($level) && RiskLevel::tryFrom($level) instanceof RiskLevel) {
            return RiskLevel::from($level);
        }

        return $this->levelOf($assessment);
    }

    /**
     * Describe which ceiling was breached, with exact figures.
     *
     * @param  array<string, mixed>  $assessment
     */
    private function describeBreach(array $assessment): string
    {
        $exposures = is_array($assessment['exposures'] ?? null) ? $assessment['exposures'] : [];

        foreach ($exposures as $exposure) {
            if (! is_array($exposure) || ($exposure['fits'] ?? true) === true) {
                continue;
            }

            return sprintf(
                'Accepting this bet would take %s on number %s to %s against a ceiling of %s '
                .'(remaining capacity %s).',
                is_string($exposure['exposure_type'] ?? null) ? $exposure['exposure_type'] : 'exposure',
                is_string($assessment['number'] ?? null) ? $assessment['number'] : 'requested',
                is_string($exposure['projected'] ?? null) ? $exposure['projected'] : 'an unknown amount',
                is_string($exposure['ceiling'] ?? null) ? $exposure['ceiling'] : 'an unknown ceiling',
                is_string($exposure['remaining_before'] ?? null) ? $exposure['remaining_before'] : 'unknown',
            );
        }

        return 'Accepting this bet would exceed the configured limit for this number.';
    }

    /**
     * The first error message from an assessment, when one exists.
     *
     * @param  array<mixed>  $errors
     */
    private function firstError(array $errors): ?string
    {
        foreach ($errors as $error) {
            if (is_string($error) && $error !== '') {
                return $error;
            }
        }

        return null;
    }
}
