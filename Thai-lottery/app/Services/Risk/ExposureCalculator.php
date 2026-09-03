<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\ExposureType;
use App\Exceptions\RiskConfigurationException;
use App\Models\NumberLimit;
use App\Services\Finance\Money;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Answers "how much liability sits on this number, and what happens if we add
 * more" using exact decimal arithmetic only.
 *
 * AUTHORITATIVE SOURCE OF CURRENT EXPOSURE
 * The accumulated figures on the number_limits row are authoritative:
 *   stake liability   number_limits.current_amount
 *   payout liability  number_limits.current_payout_exposure
 * They are the only values a concurrent request can lock, so they are what the
 * ceiling check must read. Recomputing from bets on every request would be both
 * slower and unlockable.
 *
 * recomputeFromBetItems() exists as a RECONCILIATION tool, not as the decision
 * path. It re-derives the same two figures from bets and bet_items so an operator
 * can prove the counters have not drifted.
 *
 * SCHEMA FACT — WHY THE RECONCILIATION QUERY NEEDS A JOIN
 * bet_items stores `number`, `position`, `amount`, `payout_multiplier` and
 * `potential_payout`, but it does NOT store bet_type and does NOT store draw_id.
 * Both live on the parent bets row (bets.type, bets.draw_id), which the model
 * exposes through a bet_type accessor. Any per-draw, per-bet-type exposure figure
 * therefore has to join bet_items to bets on bet_id. There is no shortcut.
 *
 * NO ROUNDING AS A CORRECTION
 * Remaining capacity is produced by an exact subtraction and then floored at zero
 * by an explicit comparison. A row that is already over its ceiling reports
 * remaining '0.00' AND a non-zero surplus, so the breach stays visible instead of
 * being rounded out of existence.
 *
 * THIS CLASS NEVER WRITES. It performs no UPDATE, no INSERT, and takes no locks.
 * Mutation of exposure belongs to App\Services\Risk\NumberLimitEngine, inside a
 * lock held by App\Services\Risk\NumberLimitLockService.
 */
class ExposureCalculator
{
    /**
     * Bet statuses whose liability is still outstanding.
     *
     * Pending and Active are live. Won, Lost, Refunded and Cancelled are settled
     * or void: a settled bet's liability has already become a payout row or has
     * been released, so counting it again would double the exposure.
     *
     * @return list<string>
     */
    public static function liveBetStatuses(): array
    {
        return [
            BetStatus::Pending->value,
            BetStatus::Active->value,
        ];
    }

    public function __construct(
        private readonly MoneyExposureCalculator $money,
        private readonly NumberLimitResolver $resolver,
    ) {
    }

    /**
     * Accumulated exposure on a limit row for one exposure type.
     *
     * @throws RiskConfigurationException
     */
    public function current(NumberLimit $limit, ExposureType $type): Money
    {
        return $this->resolver->currentFor($limit, $type);
    }

    /**
     * The ceiling in force for a limit row and exposure type.
     *
     * @return array{ceiling: Money, source: string}
     *
     * @throws RiskConfigurationException
     */
    public function ceiling(NumberLimit $limit, ExposureType $type): array
    {
        return $this->resolver->ceilingFor($limit, $type);
    }

    /**
     * What the accumulated figure would become if $increment were accepted.
     *
     * @throws RiskConfigurationException
     */
    public function projected(NumberLimit $limit, ExposureType $type, Money $increment): Money
    {
        $increment->assertNotNegative('exposure increment');

        return $this->money->project($this->current($limit, $type), $increment);
    }

    /**
     * Headroom left under the ceiling, floored at zero.
     *
     * @throws RiskConfigurationException
     */
    public function remainingCapacity(NumberLimit $limit, ExposureType $type): Money
    {
        return $this->money->remainingCapacity(
            $this->ceiling($limit, $type)['ceiling'],
            $this->current($limit, $type),
        );
    }

    /**
     * Amount by which the accumulated figure already exceeds its ceiling.
     *
     * Non-zero only when a breach was written outside the engine, which the schema
     * permits: there is no CHECK constraint tying current_payout_exposure to
     * maximum_payout_exposure, nor current_amount to max_amount.
     *
     * @throws RiskConfigurationException
     */
    public function surplus(NumberLimit $limit, ExposureType $type): Money
    {
        return $this->money->surplus(
            $this->ceiling($limit, $type)['ceiling'],
            $this->current($limit, $type),
        );
    }

    /**
     * Utilisation of the ceiling as an exact decimal ratio string.
     *
     * @throws RiskConfigurationException
     */
    public function utilisation(NumberLimit $limit, ExposureType $type): string
    {
        return $this->money->utilisation(
            $this->current($limit, $type),
            $this->ceiling($limit, $type)['ceiling'],
        );
    }

    /**
     * Utilisation the row WOULD have if $increment were accepted.
     *
     * @throws RiskConfigurationException
     */
    public function projectedUtilisation(NumberLimit $limit, ExposureType $type, Money $increment): string
    {
        return $this->money->utilisation(
            $this->projected($limit, $type, $increment),
            $this->ceiling($limit, $type)['ceiling'],
        );
    }

    /**
     * Complete assessment of one exposure type for one candidate increment.
     *
     * ASSESSMENT ONLY: this answers "what would happen if this bet is accepted".
     * It does not reserve, does not lock, and does not write. Two callers running
     * this concurrently will both see the same current figure and both be told
     * they fit; deciding which of them actually gets the capacity is the job of
     * the locked reservation in NumberLimitEngine.
     *
     * @return array{
     *     exposure_type: string,
     *     current: string,
     *     increment: string,
     *     projected: string,
     *     ceiling: string,
     *     ceiling_source: string,
     *     remaining_before: string,
     *     remaining_after: string,
     *     surplus_before: string,
     *     utilisation_before: string,
     *     utilisation_after: string,
     *     fits: bool,
     *     currency: string
     * }
     *
     * @throws RiskConfigurationException
     */
    public function assess(NumberLimit $limit, ExposureType $type, Money $increment): array
    {
        $increment->assertNotNegative('exposure increment');

        $resolved = $this->ceiling($limit, $type);
        $ceiling = $resolved['ceiling'];
        $current = $this->current($limit, $type);
        $projected = $this->money->project($current, $increment);

        return [
            'exposure_type' => $type->value,
            'current' => $current->amount(),
            'increment' => $increment->amount(),
            'projected' => $projected->amount(),
            'ceiling' => $ceiling->amount(),
            'ceiling_source' => $resolved['source'],
            'remaining_before' => $this->money->remainingCapacity($ceiling, $current)->amount(),
            'remaining_after' => $this->money->remainingCapacity($ceiling, $projected)->amount(),
            'surplus_before' => $this->money->surplus($ceiling, $current)->amount(),
            'utilisation_before' => $this->money->utilisation($current, $ceiling),
            'utilisation_after' => $this->money->utilisation($projected, $ceiling),
            'fits' => $this->money->fitsWithinCeiling($projected, $ceiling),
            'currency' => $ceiling->currency()->value,
        ];
    }

    /**
     * Assess every enforced exposure type at once.
     *
     * $stake is the wagered amount and $potentialPayout the liability it creates;
     * they are applied to their own ceilings respectively. The key of each entry is
     * the ExposureType value.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws RiskConfigurationException
     */
    public function assessAll(NumberLimit $limit, Money $stake, Money $potentialPayout): array
    {
        $assessments = [];

        foreach (ExposureType::enforced() as $type) {
            $increment = $type === ExposureType::Stake ? $stake : $potentialPayout;
            $assessments[$type->value] = $this->assess($limit, $type, $increment);
        }

        return $assessments;
    }

    /**
     * The exposure type with the least headroom, which is what governs a decision.
     *
     * @param  array<string, array<string, mixed>>  $assessments  output of assessAll()
     *
     * @throws RiskConfigurationException
     */
    public function tightestConstraint(array $assessments): string
    {
        $tightest = null;
        $tightestUtilisation = null;

        foreach ($assessments as $key => $assessment) {
            $utilisation = $assessment['utilisation_after'] ?? null;

            if (! is_string($utilisation)) {
                continue;
            }

            if ($tightestUtilisation === null
                || bccomp($utilisation, $tightestUtilisation, MoneyExposureCalculator::RATIO_SCALE) > 0) {
                $tightest = (string) $key;
                $tightestUtilisation = $utilisation;
            }
        }

        if ($tightest === null) {
            throw RiskConfigurationException::invalidLimit(
                'no exposure assessment was produced, so no constraint can be identified',
            );
        }

        return $tightest;
    }

    /**
     * Re-derive stake and payout liability for one number from the bet tables.
     *
     * RECONCILIATION ONLY — not the decision path. Joins bet_items to bets because
     * bet_items carries neither draw_id nor bet_type.
     *
     * bet_items.potential_payout is summed rather than recomputed from
     * amount x payout_multiplier, because the stored figure is what the settlement
     * engine will actually pay. recomputeFromMultipliers() offers the other view
     * for comparison.
     *
     * @return array{stake: Money, potential_payout: Money, item_count: int}
     *
     * @throws RiskConfigurationException
     */
    public function recomputeFromBetItems(int $drawId, BetType $betType, string $number): array
    {
        $row = $this->liveBetItemQuery($drawId, $betType, $number)
            ->selectRaw(
                'COUNT(*) AS item_count, '
                .'COALESCE(SUM(bet_items.amount), 0) AS stake_total, '
                .'COALESCE(SUM(bet_items.potential_payout), 0) AS payout_total',
            )
            ->first();

        $currency = $this->resolver->currency();

        return [
            'stake' => $this->money->fromColumn(
                $row === null ? '0' : (string) $row->stake_total,
                $currency,
            ),
            'potential_payout' => $this->money->fromColumn(
                $row === null ? '0' : (string) $row->payout_total,
                $currency,
            ),
            'item_count' => $row === null ? 0 : (int) $row->item_count,
        ];
    }

    /**
     * Re-derive payout liability as amount x payout_multiplier per item.
     *
     * Kept separate from recomputeFromBetItems() so a discrepancy between the
     * stored potential_payout and the multiplier arithmetic is detectable rather
     * than averaged away. The multiplication is performed in PHP with bcmath, item
     * by item, so no SQL floating-point arithmetic is involved.
     *
     * @throws RiskConfigurationException
     */
    public function recomputeFromMultipliers(int $drawId, BetType $betType, string $number): Money
    {
        $currency = $this->resolver->currency();
        $total = Money::zero($currency);

        $items = $this->liveBetItemQuery($drawId, $betType, $number)
            ->select(['bet_items.amount', 'bet_items.payout_multiplier'])
            ->get();

        foreach ($items as $item) {
            $stake = $this->money->fromColumn((string) $item->amount, $currency);
            $total = $total->plus(
                $this->money->potentialPayout($stake, (string) $item->payout_multiplier),
            );
        }

        return $total;
    }

    /**
     * Compare the stored counters against the bet tables.
     *
     * @return array{
     *     number_limit_id: int,
     *     stake_stored: string,
     *     stake_recomputed: string,
     *     stake_matches: bool,
     *     payout_stored: string,
     *     payout_recomputed: string,
     *     payout_matches: bool,
     *     item_count: int
     * }
     *
     * @throws RiskConfigurationException
     */
    public function reconcile(NumberLimit $limit): array
    {
        $betType = $limit->getAttribute('bet_type');

        if (! $betType instanceof BetType) {
            $betType = BetType::tryFrom((string) $betType);
        }

        if (! $betType instanceof BetType) {
            throw RiskConfigurationException::invalidLimit(
                'the limit row has an unrecognised bet type',
                ['number_limit_id' => $limit->getKey()],
            );
        }

        $recomputed = $this->recomputeFromBetItems(
            (int) $limit->getAttribute('draw_id'),
            $betType,
            (string) $limit->getAttribute('number'),
        );

        $storedStake = $this->current($limit, ExposureType::Stake);
        $storedPayout = $this->current($limit, ExposureType::PotentialPayout);

        return [
            'number_limit_id' => (int) $limit->getKey(),
            'stake_stored' => $storedStake->amount(),
            'stake_recomputed' => $recomputed['stake']->amount(),
            'stake_matches' => $storedStake->equals($recomputed['stake']),
            'payout_stored' => $storedPayout->amount(),
            'payout_recomputed' => $recomputed['potential_payout']->amount(),
            'payout_matches' => $storedPayout->equals($recomputed['potential_payout']),
            'item_count' => $recomputed['item_count'],
        ];
    }

    /**
     * Total live stake on a whole draw, for config('risk.exposure.max_per_draw').
     *
     * Read from bets.stake_amount rather than from draws.total_amount_wagered,
     * because that column is a settlement-time aggregate and is not maintained by
     * this phase.
     *
     * @throws RiskConfigurationException
     */
    public function drawStakeExposure(int $drawId): Money
    {
        $total = DB::table('bets')
            ->where('bets.draw_id', $drawId)
            ->whereNull('bets.deleted_at')
            ->whereIn('bets.status', self::liveBetStatuses())
            ->selectRaw('COALESCE(SUM(bets.stake_amount), 0) AS stake_total')
            ->value('stake_total');

        return $this->money->fromColumn(
            $total === null ? '0' : (string) $total,
            $this->resolver->currency(),
        );
    }

    /**
     * Total live payout liability on a whole draw.
     *
     * @throws RiskConfigurationException
     */
    public function drawPayoutExposure(int $drawId): Money
    {
        $total = DB::table('bets')
            ->where('bets.draw_id', $drawId)
            ->whereNull('bets.deleted_at')
            ->whereIn('bets.status', self::liveBetStatuses())
            ->selectRaw('COALESCE(SUM(bets.potential_payout), 0) AS payout_total')
            ->value('payout_total');

        return $this->money->fromColumn(
            $total === null ? '0' : (string) $total,
            $this->resolver->currency(),
        );
    }

    /**
     * Live bet_items for one draw, bet type and canonical number.
     *
     * The number comparison is a string comparison against a varchar column, so
     * '007' matches only '007'. Soft-deleted bets and bet items are excluded, and
     * so are settled or void bets.
     */
    private function liveBetItemQuery(int $drawId, BetType $betType, string $number): QueryBuilder
    {
        return DB::table('bet_items')
            ->join('bets', 'bets.id', '=', 'bet_items.bet_id')
            ->where('bets.draw_id', $drawId)
            ->where('bets.type', $betType->value)
            ->whereIn('bets.status', self::liveBetStatuses())
            ->whereNull('bets.deleted_at')
            ->whereNull('bet_items.deleted_at')
            ->where('bet_items.number', $number);
    }
}
