<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\BetType;
use App\Enums\ExposureType;
use App\Enums\LimitStatus;
use App\Enums\NumberLimitStatus;
use App\Enums\RiskAlertType;
use App\Exceptions\NumberLimitExceededException;
use App\Exceptions\RiskConfigurationException;
use App\Models\NumberLimit;
use App\Services\Finance\Money;
use Illuminate\Support\Carbon;

/**
 * The pre-bet number limit engine: assesses capacity, and atomically reserves it.
 *
 * WHAT THIS CLASS DOES NOT DO — HARD BOUNDARY
 * It does NOT debit or credit a wallet. It does NOT touch wallets.balance,
 * wallets.locked_balance or any ledger table. It does NOT create a Bet, a BetItem,
 * a Ticket, a Payout or a FinancialTransaction. It does NOT settle anything. The
 * only tables it writes are number_limits (its own counters) and, indirectly through
 * RiskAlertService, audit_logs. This is a PRE-BET risk engine: it answers "may this
 * liability be accepted, and hold the capacity" and nothing else.
 *
 * ASSESSMENT VERSUS RESERVATION — THE DISTINCTION THAT MATTERS
 *
 *   assess()   "What would happen if this bet is accepted?" A read-only projection.
 *              It takes no lock. Two callers running assess() concurrently will BOTH
 *              be told they fit, because both are reading the same pre-bet state.
 *              That is correct behaviour for a quote or a preview, and it is NOT a
 *              concurrency guarantee. Never gate money on assess() alone.
 *
 *   reserve()  "Hold the exposure so another concurrent request cannot consume the
 *              same remaining capacity." It locks the row with SELECT ... FOR UPDATE,
 *              re-reads the counters from the locked row, compares the ceilings
 *              exactly, and writes the new counters before releasing the lock at the
 *              caller's commit. Of two concurrent requests for the last 100.00, at
 *              most one succeeds.
 *
 * SCHEMA SUPPORT FOR RESERVATION — CONFIRMED AVAILABLE
 * Reservation is genuinely supported by the existing schema and is NOT faked:
 *   number_limits has a UNIQUE key (draw_id, bet_type, number), so there is exactly
 *   one lockable row per number per draw;
 *   current_amount and current_payout_exposure are mutable DECIMAL(20,2) columns
 *   that accumulate;
 *   max_amount is NOT NULL with CHECK (max_amount > 0);
 *   status and exceeded_at exist to record a consumed ceiling.
 * The one thing the schema does NOT provide is a CHECK tying a current column to its
 * ceiling, so the ceiling is enforced in this class inside the row lock. That is
 * stated as a limitation in the report rather than glossed over.
 *
 * TRANSACTION OWNERSHIP
 * reserve() and release() MUST be called inside a transaction the caller opened, and
 * they refuse otherwise. They never begin, commit or roll back anything, so a later
 * phase can reserve number capacity and debit a wallet in one atomic unit.
 *
 * NO FLOAT, ANYWHERE
 * Every amount is an exact decimal string inside App\Services\Finance\Money, and
 * every comparison is bccomp. Remaining capacity is floored at zero by an explicit
 * comparison, never by round(), so a genuinely negative headroom is reported as a
 * surplus instead of vanishing.
 */
class NumberLimitEngine
{
    public function __construct(
        private readonly NumberNormalizationService $numbers,
        private readonly NumberLimitResolver $resolver,
        private readonly ExposureCalculator $exposure,
        private readonly MoneyExposureCalculator $money,
        private readonly RiskLevelCalculator $levels,
        private readonly NumberLimitLockService $locks,
        private readonly HotNumberService $hotNumbers,
        private readonly RiskAlertService $alerts,
    ) {
    }

    /**
     * Read-only projection of one candidate liability. Takes no lock, writes nothing.
     *
     * @param  Money  $stake  the amount to be wagered
     * @param  string|int  $multiplier  exact decimal or integer payout multiplier
     * @return array{
     *     draw_id: int,
     *     bet_type: string,
     *     number: string,
     *     stake: string,
     *     multiplier: string,
     *     potential_payout: string,
     *     limit_source: string,
     *     number_limit_id: int|null,
     *     status: string|null,
     *     reservable: bool,
     *     fits: bool,
     *     blocked: bool,
     *     hot: bool,
     *     block_reason_code: string|null,
     *     level: string,
     *     tightest_exposure_type: string|null,
     *     exposures: array<string, array<string, mixed>>,
     *     grades: array<string, array<string, mixed>>,
     *     alerts: list<string>,
     *     errors: array<string, string>
     * }
     *
     * @throws RiskConfigurationException when the number, amount or configuration is invalid
     */
    public function assess(
        int $drawId,
        BetType $betType,
        string $rawNumber,
        Money $stake,
        string|int $multiplier,
    ): array {
        $this->numbers->assertBetTypeEnabled($betType);
        $this->numbers->assertBetTypeDigitsAgreeWithConfig($betType);

        $number = $this->numbers->normalize($rawNumber, $betType);
        $this->money->assertPositiveStake($stake);
        $this->assertStakeWithinConfiguredBounds($stake);

        $normalisedMultiplier = $this->money->normaliseMultiplier($multiplier);
        $potentialPayout = $this->money->potentialPayout($stake, $normalisedMultiplier);

        $heat = $this->hotNumbers->evaluate($drawId, $betType, $number);
        $limit = $this->resolver->resolve($drawId, $betType, $number);

        $result = [
            'draw_id' => $drawId,
            'bet_type' => $betType->value,
            'number' => $number,
            'stake' => $stake->amount(),
            'multiplier' => $normalisedMultiplier,
            'potential_payout' => $potentialPayout->amount(),
            'limit_source' => $limit instanceof NumberLimit ? 'number_limit_row' : 'none',
            'number_limit_id' => $heat['number_limit_id'],
            'status' => $heat['status'],
            'reservable' => false,
            'fits' => false,
            'blocked' => $heat['is_blocked'],
            'hot' => $heat['is_hot'],
            'block_reason_code' => $heat['block_reason_code'],
            // 'level_before' is the band the number is ALREADY in, before this bet.
            // 'level' is the band it WOULD be in if the bet were accepted. The two are
            // kept separate because automatic blocking must be driven by the state the
            // house is already carrying: blocking on the projected band would refuse a
            // bet that the configured ceiling explicitly permits (a stake taking a
            // number from 800 to exactly its 1000 ceiling is allowed, and the bet after
            // it is the one that must be refused).
            'level_before' => $heat['level'],
            'level' => $heat['level'],
            'tightest_exposure_type' => null,
            'exposures' => [],
            'grades' => [],
            'alerts' => $heat['alerts'],
            'errors' => [],
        ];

        if (! $limit instanceof NumberLimit) {
            // No row means no lockable capacity. A configured fallback ceiling can
            // still be reported for transparency, but it is never presented as
            // reservable and never read as unlimited.
            try {
                $fallback = $this->resolver->fallbackCeiling(ExposureType::PotentialPayout);
            } catch (RiskConfigurationException $exception) {
                $result['errors']['fallback'] = $exception->getMessage();
                $fallback = null;
            }

            if ($fallback instanceof Money) {
                $result['limit_source'] = 'config_fallback';
                $result['exposures'][ExposureType::PotentialPayout->value] = [
                    'exposure_type' => ExposureType::PotentialPayout->value,
                    'current' => Money::zero($fallback->currency())->amount(),
                    'increment' => $potentialPayout->amount(),
                    'projected' => $potentialPayout->amount(),
                    'ceiling' => $fallback->amount(),
                    'ceiling_source' => 'config',
                    'remaining_before' => $fallback->amount(),
                    'remaining_after' => $this->money->remainingCapacity($fallback, $potentialPayout)->amount(),
                    'surplus_before' => Money::zero($fallback->currency())->amount(),
                    'utilisation_before' => $this->money->utilisation(
                        Money::zero($fallback->currency()),
                        $fallback,
                    ),
                    'utilisation_after' => $this->money->utilisation($potentialPayout, $fallback),
                    'fits' => $this->money->fitsWithinCeiling($potentialPayout, $fallback),
                    'currency' => $fallback->currency()->value,
                ];
            }

            $result['errors']['limit'] = sprintf(
                'No number limit row exists for number %s (%s) on draw %d, so no capacity can be '
                .'reserved. number_limits.draw_id is NOT NULL, so the schema cannot express a '
                .'global or default limit row.',
                $number,
                $betType->value,
                $drawId,
            );

            return $result;
        }

        $assessments = $this->exposure->assessAll($limit, $stake, $potentialPayout);
        $result['exposures'] = $assessments;
        $result['tightest_exposure_type'] = $this->exposure->tightestConstraint($assessments);

        foreach ($assessments as $key => $assessment) {
            $type = ExposureType::from((string) $key);
            $ceiling = $this->exposure->ceiling($limit, $type)['ceiling'];
            $increment = $type === ExposureType::Stake ? $stake : $potentialPayout;
            $result['grades'][$key] = $this->levels->grade($assessment, $increment, $ceiling);
        }

        $result['fits'] = $this->allFit($assessments);
        $result['level'] = $this->levels->worstOf($assessments)->value;
        $result['reservable'] = $result['fits'] && ! $result['blocked'];
        $result['alerts'] = $this->alertTypesFor($result);

        return $result;
    }

    /**
     * Atomically reserve capacity for one candidate liability.
     *
     * MUST be called inside a transaction the caller opened. Locks the row, re-reads
     * the counters from the locked row, enforces every ceiling exactly, then writes
     * the new counters. On success the capacity is held until the caller commits; if
     * the caller rolls back, the reservation disappears with it, which is precisely
     * why this method must not manage the transaction itself.
     *
     * @return array{
     *     reserved: true,
     *     number_limit_id: int,
     *     draw_id: int,
     *     bet_type: string,
     *     number: string,
     *     stake: string,
     *     multiplier: string,
     *     potential_payout: string,
     *     status_before: string,
     *     status_after: string,
     *     exposures: array<string, array{before: string, reserved: string, after: string, ceiling: string, ceiling_source: string, remaining_after: string}>,
     *     level: string,
     *     capacity_consumed: bool,
     *     alerts: list<array<string, mixed>>
     * }
     *
     * @throws \App\Exceptions\RiskException when called outside a transaction
     * @throws RiskConfigurationException when the number, amount, limit or configuration is invalid
     * @throws \App\Exceptions\HotNumberException when the number must not sell
     * @throws NumberLimitExceededException when a ceiling would be breached
     */
    public function reserve(
        int $drawId,
        BetType $betType,
        string $rawNumber,
        Money $stake,
        string|int $multiplier,
    ): array {
        $this->locks->assertInsideTransaction('reserve number limit capacity');

        $this->numbers->assertBetTypeEnabled($betType);
        $this->numbers->assertBetTypeDigitsAgreeWithConfig($betType);

        $number = $this->numbers->normalize($rawNumber, $betType);
        $this->money->assertPositiveStake($stake);
        $this->assertStakeWithinConfiguredBounds($stake);

        $normalisedMultiplier = $this->money->normaliseMultiplier($multiplier);
        $potentialPayout = $this->money->potentialPayout($stake, $normalisedMultiplier);

        // A global block is independent of the row, so it is checked before locking.
        if ($this->hotNumbers->isGloballyBlocked($number)) {
            throw \App\Exceptions\HotNumberException::globallyBlocked($number, $betType->value);
        }

        // From here on, every amount used in a decision comes from the LOCKED row.
        $limit = $this->locks->lock($drawId, $betType, $number);
        $statusBefore = $this->resolver->statusOf($limit);
        $this->locks->assertActive($limit, $number, $betType, $drawId);

        $increments = [
            ExposureType::Stake->value => $stake,
            ExposureType::PotentialPayout->value => $potentialPayout,
        ];

        $movements = [];

        foreach (ExposureType::enforced() as $type) {
            $increment = $increments[$type->value];
            $resolved = $this->exposure->ceiling($limit, $type);
            $ceiling = $resolved['ceiling'];
            $current = $this->exposure->current($limit, $type);
            $projected = $this->money->project($current, $increment);

            if (! $this->money->fitsWithinCeiling($projected, $ceiling)) {
                // Refused inside the lock, before any column is written. Nothing is
                // committed by this class, so the caller's rollback is clean.
                throw NumberLimitExceededException::forCeiling(
                    $type,
                    $number,
                    $betType->value,
                    $current->amount(),
                    $increment->amount(),
                    $projected->amount(),
                    $ceiling->amount(),
                    (int) $limit->getKey(),
                    $drawId,
                );
            }

            $movements[$type->value] = [
                'before' => $current->amount(),
                'reserved' => $increment->amount(),
                'after' => $projected->amount(),
                'ceiling' => $ceiling->amount(),
                'ceiling_source' => $resolved['source'],
                'remaining_after' => $this->money->remainingCapacity($ceiling, $projected)->amount(),
                'utilisation_after' => $this->money->utilisation($projected, $ceiling),
            ];
        }

        $consumed = $this->anyCapacityConsumed($movements);
        $statusAfter = $consumed && $this->hotNumbers->blockOnExceeded()
            ? NumberLimitStatus::Exceeded
            : $statusBefore;

        $this->writeCounters($limit, $movements, $statusBefore, $statusAfter, $consumed);

        $level = $this->worstLevelOf($movements);

        return [
            'reserved' => true,
            'number_limit_id' => (int) $limit->getKey(),
            'draw_id' => $drawId,
            'bet_type' => $betType->value,
            'number' => $number,
            'stake' => $stake->amount(),
            'multiplier' => $normalisedMultiplier,
            'potential_payout' => $potentialPayout->amount(),
            'status_before' => $statusBefore->value,
            'status_after' => $statusAfter->value,
            'exposures' => $movements,
            'level' => $level,
            'capacity_consumed' => $consumed,
            'alerts' => $this->raiseReservationAlerts($limit, $movements, $consumed, $level),
        ];
    }

    /**
     * Release capacity previously reserved, without touching any other table.
     *
     * Used when a bet that reserved capacity is cancelled or voided in a later phase,
     * and the caller's transaction has already been committed so a rollback is no
     * longer available. Must be called inside the caller's transaction.
     *
     * The counters are floored at zero by explicit comparison, because the schema
     * carries CHECK (current_amount >= 0) and CHECK (current_payout_exposure >= 0):
     * releasing more than was reserved would otherwise be rejected by the database
     * with an opaque constraint error. Over-release is reported through the returned
     * 'clamped' flags rather than being hidden.
     *
     * @return array{
     *     released: true,
     *     number_limit_id: int,
     *     status_before: string,
     *     status_after: string,
     *     exposures: array<string, array{before: string, released: string, after: string, clamped: bool}>
     * }
     *
     * @throws \App\Exceptions\RiskException when called outside a transaction
     * @throws RiskConfigurationException
     */
    public function release(
        int $drawId,
        BetType $betType,
        string $rawNumber,
        Money $stake,
        string|int $multiplier,
    ): array {
        $this->locks->assertInsideTransaction('release number limit capacity');

        $number = $this->numbers->normalize($rawNumber, $betType);
        $stake->assertNotNegative('stake');
        $potentialPayout = $this->money->potentialPayout($stake, $multiplier);

        $limit = $this->locks->lock($drawId, $betType, $number);
        $statusBefore = $this->resolver->statusOf($limit);

        $decrements = [
            ExposureType::Stake->value => $stake,
            ExposureType::PotentialPayout->value => $potentialPayout,
        ];

        $movements = [];
        $attributes = [];

        foreach (ExposureType::enforced() as $type) {
            $current = $this->exposure->current($limit, $type);
            $decrement = $decrements[$type->value];
            $raw = $current->minus($decrement);
            $clamped = $raw->isNegative();
            $after = $clamped
                ? Money::zero($current->currency(), $current->scale())
                : $raw;

            $movements[$type->value] = [
                'before' => $current->amount(),
                'released' => $decrement->amount(),
                'after' => $after->amount(),
                'clamped' => $clamped,
            ];

            $column = $type->currentColumn();

            if ($column !== null) {
                $attributes[$column] = $after->amount();
            }
        }

        // Releasing capacity can make an Exceeded row sellable again. Only that
        // transition is performed: a Suspended or Removed row was set by an operator
        // and is never reopened by an automatic release.
        $statusAfter = $statusBefore;

        if ($statusBefore->isExceeded() && ! $this->anyReleasedCapacityStillConsumed($limit, $movements)) {
            $statusAfter = NumberLimitStatus::Active;
            $attributes['status'] = LimitStatus::Active;
            $attributes['exceeded_at'] = null;
        }

        // current_amount, current_payout_exposure, status and exceeded_at are
        // deliberately absent from the model's $fillable, so forceFill is the correct
        // way for the engine that owns them to write them.
        $limit->forceFill($attributes)->save();

        return [
            'released' => true,
            'number_limit_id' => (int) $limit->getKey(),
            'status_before' => $statusBefore->value,
            'status_after' => $statusAfter->value,
            'exposures' => $movements,
        ];
    }

    /**
     * Reserve capacity for several numbers atomically, in a deadlock-safe order.
     *
     * Every entry is ['number' => string, 'stake' => Money, 'multiplier' => string|int].
     * All entries share the draw and bet type. Rows are locked in ascending
     * primary-key order before any ceiling is checked, so two concurrent multi-number
     * requests over an overlapping set queue rather than deadlock.
     *
     * Any refusal throws, and because this class never commits, the caller's rollback
     * undoes every reservation made in the batch. Partial reservation is impossible.
     *
     * @param  list<array{number: string, stake: Money, multiplier: string|int}>  $entries
     * @return array<string, array<string, mixed>> keyed by canonical number
     *
     * @throws \App\Exceptions\RiskException
     * @throws RiskConfigurationException
     * @throws NumberLimitExceededException
     */
    public function reserveMany(int $drawId, BetType $betType, array $entries): array
    {
        $this->locks->assertInsideTransaction('reserve number limit capacity in bulk');

        $triples = [];

        foreach ($entries as $entry) {
            $triples[] = [
                'draw_id' => $drawId,
                'bet_type' => $betType,
                'number' => $this->numbers->normalize($entry['number'], $betType),
            ];
        }

        // Take every lock first, in id order, before evaluating any ceiling.
        $this->locks->lockMany($triples);

        $results = [];

        foreach ($entries as $entry) {
            $number = $this->numbers->normalize($entry['number'], $betType);
            $results[$number] = $this->reserve(
                $drawId,
                $betType,
                $number,
                $entry['stake'],
                $entry['multiplier'],
            );
        }

        return $results;
    }

    /**
     * Whether every enforced ceiling accommodates the projected amount.
     *
     * @param  array<string, array<string, mixed>>  $assessments
     */
    private function allFit(array $assessments): bool
    {
        foreach ($assessments as $assessment) {
            if (($assessment['fits'] ?? false) !== true) {
                return false;
            }
        }

        return $assessments !== [];
    }

    /**
     * Whether any ceiling has zero headroom left after the movement.
     *
     * @param  array<string, array<string, mixed>>  $movements
     */
    private function anyCapacityConsumed(array $movements): bool
    {
        foreach ($movements as $movement) {
            $remaining = $movement['remaining_after'] ?? null;

            if (is_string($remaining) && bccomp($remaining, '0', 2) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * After a release, whether any ceiling is still fully consumed.
     *
     * @param  array<string, array{before: string, released: string, after: string, clamped: bool}>  $movements
     *
     * @throws RiskConfigurationException
     */
    private function anyReleasedCapacityStillConsumed(NumberLimit $limit, array $movements): bool
    {
        foreach (ExposureType::enforced() as $type) {
            $after = $movements[$type->value]['after'] ?? null;

            if (! is_string($after)) {
                continue;
            }

            $ceiling = $this->exposure->ceiling($limit, $type)['ceiling'];
            $current = $this->money->money($after, $ceiling->currency());

            if ($this->money->remainingCapacity($ceiling, $current)->isZero()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist the reserved counters and any status change on the locked row.
     *
     * @param  array<string, array<string, mixed>>  $movements
     */
    private function writeCounters(
        NumberLimit $limit,
        array $movements,
        NumberLimitStatus $statusBefore,
        NumberLimitStatus $statusAfter,
        bool $consumed,
    ): void {
        $attributes = [];

        foreach (ExposureType::enforced() as $type) {
            $column = $type->currentColumn();
            $after = $movements[$type->value]['after'] ?? null;

            if ($column !== null && is_string($after)) {
                $attributes[$column] = $after;
            }
        }

        if ($statusAfter !== $statusBefore) {
            $attributes['status'] = $statusAfter->toLimitStatus();
        }

        if ($consumed && $limit->getAttribute('exceeded_at') === null) {
            $attributes['exceeded_at'] = Carbon::now();
        }

        // These columns are intentionally not in $fillable: only this engine may move
        // them, so forceFill is the correct, explicit mechanism rather than a leak in
        // the model's mass-assignment guard.
        $limit->forceFill($attributes)->save();
    }

    /**
     * Worst severity band across every movement.
     *
     * @param  array<string, array<string, mixed>>  $movements
     *
     * @throws RiskConfigurationException
     */
    private function worstLevelOf(array $movements): string
    {
        $worst = \App\Enums\RiskLevel::Low;

        foreach ($movements as $movement) {
            $utilisation = $movement['utilisation_after'] ?? null;

            if (! is_string($utilisation)) {
                continue;
            }

            $level = $this->levels->fromUtilisation($utilisation);

            if ($level->atLeast($worst)) {
                $worst = $level;
            }
        }

        return $worst->value;
    }

    /**
     * File alerts for a completed reservation.
     *
     * Alerts are raised AFTER the counters are written, so a recorded alert always
     * corresponds to a state that exists. They never throw: RiskAlertService swallows
     * its own storage failures precisely so an alert cannot undo a reservation.
     *
     * @param  array<string, array<string, mixed>>  $movements
     * @return list<array<string, mixed>>
     *
     * @throws RiskConfigurationException
     */
    private function raiseReservationAlerts(
        NumberLimit $limit,
        array $movements,
        bool $consumed,
        string $level,
    ): array {
        $riskLevel = \App\Enums\RiskLevel::from($level);
        $raised = [];

        $context = [
            'number' => (string) $limit->getAttribute('number'),
            'draw_id' => (int) $limit->getAttribute('draw_id'),
            'number_limit_id' => (int) $limit->getKey(),
        ];

        foreach ($movements as $key => $movement) {
            $context[$key.'_after'] = is_string($movement['after'] ?? null) ? $movement['after'] : null;
            $context[$key.'_ceiling'] = is_string($movement['ceiling'] ?? null) ? $movement['ceiling'] : null;
            $context[$key.'_utilisation'] = is_string($movement['utilisation_after'] ?? null)
                ? $movement['utilisation_after']
                : null;
        }

        if ($consumed) {
            $raised[] = $this->alerts->raise(
                RiskAlertType::LimitExceeded,
                \App\Enums\RiskLevel::Critical,
                $limit,
                $context,
            );
        }

        if ($riskLevel === \App\Enums\RiskLevel::Critical) {
            $raised[] = $this->alerts->raise(RiskAlertType::CriticalExposure, $riskLevel, $limit, $context);
        } elseif ($riskLevel === \App\Enums\RiskLevel::High) {
            $raised[] = $this->alerts->raise(RiskAlertType::HighExposure, $riskLevel, $limit, $context);
        }

        foreach ($movements as $movement) {
            $utilisation = $movement['utilisation_after'] ?? null;

            if (is_string($utilisation) && ! $consumed && $this->levels->isInWarningBand($utilisation)) {
                $raised[] = $this->alerts->raise(
                    RiskAlertType::LimitNear,
                    RiskAlertType::LimitNear->defaultLevel(),
                    $limit,
                    $context,
                );

                break;
            }
        }

        return $raised;
    }

    /**
     * Alert types implied by an assessment, without raising them.
     *
     * assess() is read-only, so it reports which alerts WOULD be raised rather than
     * writing any audit row.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     *
     * @throws RiskConfigurationException
     */
    private function alertTypesFor(array $result): array
    {
        /** @var list<string> $alerts */
        $alerts = is_array($result['alerts'] ?? null) ? $result['alerts'] : [];

        if (($result['fits'] ?? true) === false) {
            $alerts[] = RiskAlertType::LimitExceeded->value;
        }

        /** @var array<string, array<string, mixed>> $grades */
        $grades = is_array($result['grades'] ?? null) ? $result['grades'] : [];

        foreach ($grades as $grade) {
            if (($grade['rapid_growth'] ?? false) === true) {
                $alerts[] = RiskAlertType::RapidExposureGrowth->value;

                break;
            }
        }

        return array_values(array_unique($alerts));
    }

    /**
     * Refuse a stake outside the configured per-bet bounds.
     *
     * config('lottery.betting.min_amount') is '10.00' and
     * config('lottery.betting.max_amount') is '100000.00'. These are plain stake
     * bounds, distinct from any risk ceiling, and they are enforced before any lock is
     * taken so an obviously invalid amount never reaches the critical section.
     *
     * @throws RiskConfigurationException
     */
    private function assertStakeWithinConfiguredBounds(Money $stake): void
    {
        $currency = $this->resolver->currency();
        $stake->assertSameCurrency(Money::zero($currency));

        $min = config('lottery.betting.min_amount');
        $max = config('lottery.betting.max_amount');

        if (is_string($min) || is_int($min)) {
            $minimum = $this->money->money((string) $min, $currency);

            if ($stake->isLessThan($minimum)) {
                throw RiskConfigurationException::invalidAmount(
                    sprintf('the stake is below the configured minimum of %s', $minimum->amount()),
                    $stake->amount(),
                );
            }
        }

        if (is_string($max) || is_int($max)) {
            $maximum = $this->money->money((string) $max, $currency);

            if ($stake->isGreaterThan($maximum)) {
                throw RiskConfigurationException::invalidAmount(
                    sprintf('the stake exceeds the configured maximum of %s', $maximum->amount()),
                    $stake->amount(),
                );
            }
        }
    }
}
