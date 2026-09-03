<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetCalculationResult;
use App\DTOs\BetSelectionData;
use App\Exceptions\BetDomainException;
use App\Exceptions\RiskConfigurationException;
use App\Exceptions\UnsupportedBetMarketException;
use App\Services\Finance\Money;
use App\Services\Risk\MoneyExposureCalculator;
use App\ValueObjects\BetAmount;
use App\ValueObjects\PayoutMultiplier;

/**
 * Computes the potential payout for a selection.
 *
 * THE FORMULA
 *   potential_payout = stake x multiplier
 *
 * The worked example from the specification is exact: stake 10.55 at multiplier
 * 70.0000 gives 738.50. Not 738.4999999, not 738.5000001, not 738.5 rendered from a
 * float. That holds because the arithmetic is bcmul on decimal strings via
 * App\Services\Risk\MoneyExposureCalculator, which is the same engine Phase 3.1 uses
 * to measure exposure. Reusing it is deliberate: if this service computed liability
 * differently from the risk engine, a bet could be priced at one figure and
 * risk-checked at another, and the limits would be measuring something the player
 * never bought.
 *
 * ROUNDING IS UPWARD, AND IS RECORDED
 * A product such as 10.555 x 3 = 31.665 does not land on a whole satang. The liability
 * is taken UP to the currency scale, because the house must never reserve less than it
 * might owe. When that happens the result carries wasRounded = true and the exact
 * untruncated product, so the adjustment is auditable instead of invisible. Rounding
 * DOWN is never done, and the stake itself is never rounded at all.
 *
 * NO FLOAT
 * There is no (float), no (double), no floatval, no round(), no ceil() on a float and
 * no number_format in this service.
 *
 * NO SIDE EFFECT WHATSOEVER
 * Pricing is a pure computation. This service does not debit a wallet, does not lock a
 * balance, does not create a hold, does not post a ledger entry, does not reserve
 * exposure and does not write any row. It does not create a Bet, a BetItem, a Ticket
 * or a Payout. Calling it a thousand times changes nothing in the database.
 *
 * IT NEVER CHOOSES A MULTIPLIER
 * A rate must be handed to it, or resolved through
 * App\Services\Betting\PayoutMultiplierService, which refuses to guess where the
 * project's declared sources contradict one another. This service will not price a
 * market whose rate is unspecified.
 *
 * PHASE 4.2 MODIFICATION
 * One method was added, calculateOnce(), and one entry was added to guarantees().
 * Nothing was removed, no signature changed and no existing behaviour changed, so
 * every Phase 4.1 caller is unaffected.
 *
 * calculateOnce() exists to make the market payout frequency rule explicit at the
 * point where money is computed. Both market rules that could otherwise leak into
 * pricing are frequency rules:
 *
 *   A 3D TOD selection covering six unique permutations is ONE selection with ONE
 *   stake. A stake of 10.00 is 10.00, never 60.00, and a match pays once rather
 *   than once per matching permutation.
 *
 *   A RUN selection whose digit occurs three times in the drawn result pays once,
 *   not three times.
 *
 * calculateOnce() therefore prices exactly one payout and records the frequency on
 * the result. It deliberately accepts no count, no quantity and no factor argument,
 * so there is no parameter through which a permutation count or an occurrence count
 * could ever multiply a payout.
 */
class BetCalculationService
{
    public function __construct(
        private readonly MoneyExposureCalculator $money,
        private readonly PayoutMultiplierService $multipliers,
    ) {
    }

    /**
     * Price a stake at an explicit multiplier.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws BetDomainException when the arithmetic cannot be performed exactly
     */
    public function calculate(
        BetAmount $stake,
        PayoutMultiplier $multiplier,
        ?string $marketKey = null,
        array $context = [],
    ): BetCalculationResult {
        try {
            $exact = $this->money->exactProduct($stake->money(), $multiplier->value());
            $payout = $this->money->potentialPayout($stake->money(), $multiplier->value());
            $wasRounded = $this->money->hasInexactProduct($stake->money(), $multiplier->value());
        } catch (RiskConfigurationException $exception) {
            throw BetDomainException::invalidMultiplier(
                sprintf('the shared exposure calculator refused it (%s)', $exception->getMessage()),
                ['multiplier' => $multiplier->value(), 'market' => $marketKey],
            );
        }

        return new BetCalculationResult(
            $stake,
            $multiplier,
            $payout,
            $exact,
            $wasRounded,
            $marketKey,
            $context + [
                'formula' => 'potential_payout = stake x multiplier',
                'rounding' => 'exact product taken UP to the currency scale so the house never '
                    .'under-reserves',
                'engine' => MoneyExposureCalculator::class,
            ],
        );
    }

    /**
     * Price exactly one payout for a selection, at an explicit rate.
     *
     * Identical arithmetic to calculate(), with the payout frequency rule stated on
     * the result. Used by App\Services\Betting\MarketPayoutService for every market,
     * so a Tod selection and a repeated Run digit are both priced once.
     *
     * There is no count parameter by design: the permutation count and the
     * occurrence count are diagnostics carried on
     * App\DTOs\MarketMatchResult and are never factors in the product.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws BetDomainException when the arithmetic cannot be performed exactly
     */
    public function calculateOnce(
        BetAmount $stake,
        PayoutMultiplier $multiplier,
        ?string $marketKey = null,
        array $context = [],
    ): BetCalculationResult {
        return $this->calculate($stake, $multiplier, $marketKey, $context + [
            'payout_frequency' => 1,
            'frequency_rule' => 'A matched selection pays exactly once; permutation and occurrence counts '
                .'never scale the stake or the payout.',
        ]);
    }

    /**
     * Price a market by resolving its multiplier from the authoritative source.
     *
     * A contradictory market is refused by the resolver, so this method cannot price
     * one by accident.
     *
     * @throws BetDomainException
     * @throws UnsupportedBetMarketException
     */
    public function calculateForMarket(BetAmount $stake, string $marketKey): BetCalculationResult
    {
        $multiplier = $this->multipliers->resolve($marketKey);

        return $this->calculate($stake, $multiplier, $marketKey, [
            'multiplier_source' => $this->multipliers->declaredSource(),
        ]);
    }

    /**
     * Price a selection.
     *
     * Uses the multiplier already attached to the selection when validation resolved
     * one, and otherwise resolves it from the selection's market.
     *
     * @throws BetDomainException
     * @throws UnsupportedBetMarketException
     */
    public function calculateForSelection(BetSelectionData $selection): BetCalculationResult
    {
        $multiplier = $selection->multiplier;

        if (! $multiplier instanceof PayoutMultiplier) {
            $marketKey = $selection->marketKey;

            if ($marketKey === null) {
                throw BetDomainException::specificationRequired(
                    'the selection carries neither a resolved multiplier nor a resolved market key, '
                    .'so no rate can be applied without inventing one',
                    ['market' => $selection->market->value],
                );
            }

            $multiplier = $this->multipliers->resolve($marketKey);
        }

        $stake = $selection->stake;

        return $this->calculate($stake, $multiplier, $selection->marketKey, [
            'draw_id' => $selection->drawId,
            'number' => $selection->number?->value() ?? $selection->rawNumber,
            'stake' => $stake->amount(),
        ]);
    }

    /**
     * Price several selections.
     *
     * @param  iterable<BetSelectionData>  $selections
     * @return list<BetCalculationResult>
     *
     * @throws BetDomainException
     * @throws UnsupportedBetMarketException
     */
    public function calculateAll(iterable $selections): array
    {
        $results = [];

        foreach ($selections as $selection) {
            $results[] = $this->calculateForSelection($selection);
        }

        return $results;
    }

    /**
     * Total liability across priced selections, or null for an empty set.
     *
     * @param  iterable<BetCalculationResult>  $results
     */
    public function totalPotentialPayout(iterable $results): ?Money
    {
        return BetCalculationResult::totalPotentialPayout($results);
    }

    /**
     * Total stake across priced selections, or null for an empty set.
     *
     * @param  iterable<BetCalculationResult>  $results
     */
    public function totalStake(iterable $results): ?Money
    {
        return BetCalculationResult::totalStake($results);
    }

    /**
     * The exact product before any scale adjustment, for a proof or a report.
     *
     * @throws BetDomainException
     */
    public function exactProduct(BetAmount $stake, PayoutMultiplier $multiplier): string
    {
        try {
            return $this->money->exactProduct($stake->money(), $multiplier->value());
        } catch (RiskConfigurationException $exception) {
            throw BetDomainException::invalidMultiplier(
                sprintf('the shared exposure calculator refused it (%s)', $exception->getMessage()),
                ['multiplier' => $multiplier->value()],
            );
        }
    }

    /**
     * Does pricing this stake at this rate require rounding up.
     *
     * @throws BetDomainException
     */
    public function requiresRounding(BetAmount $stake, PayoutMultiplier $multiplier): bool
    {
        try {
            return $this->money->hasInexactProduct($stake->money(), $multiplier->value());
        } catch (RiskConfigurationException $exception) {
            throw BetDomainException::invalidMultiplier(
                sprintf('the shared exposure calculator refused it (%s)', $exception->getMessage()),
                ['multiplier' => $multiplier->value()],
            );
        }
    }

    /**
     * A statement of what this service guarantees, for the validation report.
     *
     * @return array<string, string|bool>
     */
    public function guarantees(): array
    {
        return [
            'formula' => 'potential_payout = stake x multiplier',
            'arithmetic' => 'bcmath decimal strings via '.MoneyExposureCalculator::class,
            'working_scale' => (string) MoneyExposureCalculator::WORKING_SCALE,
            'multiplier_scale' => (string) PayoutMultiplier::SCALE,
            'rounding' => 'upward to the currency scale, recorded on the result',
            'payout_frequency' => 'one payout per matched selection; no count argument exists that could '
                .'scale a payout',
            'uses_float' => false,
            'mutates_wallet' => false,
            'creates_financial_transaction' => false,
            'creates_bet' => false,
            'creates_bet_item' => false,
            'creates_ticket' => false,
            'creates_payout' => false,
            'reserves_exposure' => false,
        ];
    }
}
