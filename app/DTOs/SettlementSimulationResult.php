<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DrawLifecycleState;
use App\Enums\SettlementSimulationStatus;

/**
 * The outcome of ONE simulated settlement run over ONE draw.
 *
 * Aggregates the per selection records (App\DTOs\SettlementSelectionResult) and adds
 * the run level facts: which draw, which official numbers, how many selections were
 * evaluated, how many matched, the total simulated prize, and whether this run
 * actually did the work or found it already done.
 *
 * IDEMPOTENCY IS VISIBLE IN THE OBJECT
 * alreadySettled distinguishes the two legitimate outcomes of calling settlement:
 *
 *   alreadySettled = false   this run performed the settlement
 *   alreadySettled = true    the draw was already settled, so the run wrote NOTHING
 *                            and re-read the stored outcome instead
 *
 * Running settlement twice therefore yields two results whose selection records and
 * totals are identical, the second carrying alreadySettled = true and
 * selectionsWritten = 0. That is what requirement E asks for, and it is enforced by
 * the stored draw state plus the unique keys on draw_results.draw_id and
 * winning_numbers, not by a cache entry.
 *
 * NON-MONETARY BY CONSTRUCTION
 * totalSimulatedPrize is an audit total. No wallet was credited or debited, no
 * ledger entry was written, no financial transaction was created, no payouts row was
 * created and no payment gateway was called. The object has no walletId, no
 * financialTransactionId and no payoutId field, and mode is the hard coded constant
 * App\Services\Draw\DrawSettlementSimulationService::MODE, which is 'simulation' and
 * is not configurable, so no setting can turn a simulation into a payment.
 *
 * EXACT DECIMALS ONLY
 * totalSimulatedPrize and totalStake are decimal STRINGS summed with bcadd. No
 * (float), (double), intval(), floatval() or round() appears in this class.
 */
final readonly class SettlementSimulationResult
{
    /**
     * @param  int  $drawId  the draw settled
     * @param  string  $drawNumber  its human readable number
     * @param  DrawLifecycleState  $stateBefore  lifecycle state when the run began
     * @param  DrawLifecycleState  $stateAfter  lifecycle state when the run ended
     * @param  string  $firstPrize  the published first prize, a digit string
     * @param  string  $bottomTwo  the published bottom two, a digit string
     * @param  list<SettlementSelectionResult>  $selections  one record per selection
     * @param  int  $selectionsEvaluated  how many selections the run examined
     * @param  int  $selectionsWritten  how many bet_items rows this run actually wrote
     * @param  int  $betsUpdated  how many bets rows this run actually wrote
     * @param  int  $winningSelections  how many selections matched
     * @param  string  $totalStake  the summed stake, an exact decimal string
     * @param  string  $totalSimulatedPrize  the summed simulated prize, an exact decimal string
     * @param  string  $currency  the currency code of the totals
     * @param  bool  $alreadySettled  true when the draw was already settled and this run wrote nothing
     * @param  string  $mode  always 'simulation'
     * @param  string  $settledAt  ISO 8601 timestamp of the settlement
     * @param  array<string, scalar|null>  $context  diagnostic context only
     */
    public function __construct(
        public int $drawId,
        public string $drawNumber,
        public DrawLifecycleState $stateBefore,
        public DrawLifecycleState $stateAfter,
        public string $firstPrize,
        public string $bottomTwo,
        public array $selections,
        public int $selectionsEvaluated,
        public int $selectionsWritten,
        public int $betsUpdated,
        public int $winningSelections,
        public string $totalStake,
        public string $totalSimulatedPrize,
        public string $currency,
        public bool $alreadySettled,
        public string $mode,
        public string $settledAt,
        public array $context = [],
    ) {}

    /**
     * Whether this run performed the settlement rather than finding it already done.
     */
    public function performedWork(): bool
    {
        return ! $this->alreadySettled;
    }

    /**
     * Whether this run wrote nothing at all.
     *
     * True for a repeated run. This is the assertion the idempotency test makes.
     */
    public function wroteNothing(): bool
    {
        return $this->selectionsWritten === 0 && $this->betsUpdated === 0;
    }

    /**
     * Whether the run is a pure simulation.
     *
     * True whenever mode is 'simulation', which is the only value the settlement
     * service can produce because it is a class constant rather than a setting.
     */
    public function isSimulation(): bool
    {
        return $this->mode === 'simulation';
    }

    /**
     * @return list<SettlementSelectionResult>
     */
    public function selections(): array
    {
        return $this->selections;
    }

    /**
     * Only the matched selections.
     *
     * @return list<SettlementSelectionResult>
     */
    public function winners(): array
    {
        return array_values(array_filter(
            $this->selections,
            static fn (SettlementSelectionResult $selection): bool => $selection->isWinner(),
        ));
    }

    /**
     * Only the unmatched selections.
     *
     * @return list<SettlementSelectionResult>
     */
    public function losers(): array
    {
        return array_values(array_filter(
            $this->selections,
            static fn (SettlementSelectionResult $selection): bool => ! $selection->isWinner(),
        ));
    }

    /**
     * The records for one market.
     *
     * @return list<SettlementSelectionResult>
     */
    public function forMarket(string $marketKey): array
    {
        return array_values(array_filter(
            $this->selections,
            static fn (SettlementSelectionResult $selection): bool => $selection->marketKey === $marketKey,
        ));
    }

    /**
     * The record for one selection, or null when this run did not include it.
     */
    public function forBetItem(int $betItemId): ?SettlementSelectionResult
    {
        foreach ($this->selections as $selection) {
            if ($selection->betItemId === $betItemId) {
                return $selection;
            }
        }

        return null;
    }

    /**
     * How many selections carry each settlement status.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(SettlementSimulationStatus::values(), 0);

        foreach ($this->selections as $selection) {
            $counts[$selection->status->value]++;
        }

        return $counts;
    }

    /**
     * Whether the summed simulated prize equals the sum of the per selection prizes.
     *
     * A BCMath cross-check that the total was not computed some other way.
     */
    public function totalMatchesSelections(): bool
    {
        $sum = '0.00';

        foreach ($this->selections as $selection) {
            $sum = bcadd($sum, $selection->simulatedPrize, 2);
        }

        return bccomp($sum, $this->totalSimulatedPrize, 2) === 0;
    }

    /**
     * Whether every selection was charged exactly once.
     */
    public function everySelectionChargedOnce(): bool
    {
        foreach ($this->selections as $selection) {
            if (! $selection->chargedOnce() || ! $selection->payoutIsSinglyCharged()) {
                return false;
            }
        }

        return true;
    }

    /**
     * A comparison key that must be identical across repeated runs.
     *
     * Deliberately excludes alreadySettled, selectionsWritten, betsUpdated and
     * settledAt, which describe the RUN, and includes only what describes the
     * OUTCOME. Two runs over the same draw must produce the same value here.
     *
     * @return array<string, mixed>
     */
    public function idempotencyFingerprint(): array
    {
        $selections = [];

        foreach ($this->selections as $selection) {
            $selections[$selection->betItemId] = [
                'market' => $selection->marketKey,
                'selection' => $selection->selection,
                'winning' => $selection->winningValue,
                'matched' => $selection->matched,
                'multiplier' => $selection->multiplier->value(),
                'simulated_prize' => $selection->simulatedPrize,
            ];
        }

        ksort($selections);

        return [
            'draw_id' => $this->drawId,
            'first_prize' => $this->firstPrize,
            'bottom_two' => $this->bottomTwo,
            'state_after' => $this->stateAfter->value,
            'selections_evaluated' => $this->selectionsEvaluated,
            'winning_selections' => $this->winningSelections,
            'total_stake' => $this->totalStake,
            'total_simulated_prize' => $this->totalSimulatedPrize,
            'selections' => $selections,
        ];
    }

    /**
     * The run in report form, with the non-monetary guarantees stated explicitly.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'draw_number' => $this->drawNumber,
            'state_before' => $this->stateBefore->value,
            'state_after' => $this->stateAfter->value,
            'first_prize' => $this->firstPrize,
            'bottom_two' => $this->bottomTwo,
            'selections_evaluated' => $this->selectionsEvaluated,
            'selections_written' => $this->selectionsWritten,
            'bets_updated' => $this->betsUpdated,
            'winning_selections' => $this->winningSelections,
            'total_stake' => $this->totalStake,
            'total_simulated_prize' => $this->totalSimulatedPrize,
            'currency' => $this->currency,
            'already_settled' => $this->alreadySettled,
            'wrote_nothing' => $this->wroteNothing(),
            'mode' => $this->mode,
            'settled_at' => $this->settledAt,
            'status_counts' => $this->statusCounts(),
            'total_matches_selections' => $this->totalMatchesSelections(),
            'every_selection_charged_once' => $this->everySelectionChargedOnce(),
            'non_monetary_guarantees' => [
                'wallet_balance_modified' => false,
                'wallet_hold_created' => false,
                'ledger_entry_created' => false,
                'financial_transaction_created' => false,
                'payout_row_created' => false,
                'payment_gateway_called' => false,
                'deposit_created' => false,
                'withdrawal_created' => false,
            ],
            'selections' => array_map(
                static fn (SettlementSelectionResult $selection): array => $selection->toArray(),
                $this->selections,
            ),
            'context' => $this->context,
        ];
    }
}
