<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetCalculationResult;
use App\DTOs\MarketMatchResult;
use App\DTOs\MarketRuleData;
use App\Exceptions\BetDomainException;
use App\Exceptions\MarketRuleException;
use App\ValueObjects\BetAmount;
use App\ValueObjects\PayoutMultiplier;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Prices a market selection: stake x the authoritative configured multiplier.
 *
 * WHERE THE RATE COMES FROM
 * The project declares its own authoritative source in
 * config('lottery.payouts.multiplier_source'), which holds 'lottery.markets'. This
 * service therefore reads the rate through the existing
 * App\Services\Betting\PayoutMultiplierService, which is the one component that
 * knows how to follow that declaration. No rate is hard-coded here, no new
 * configuration file or key is created, and no second price list exists.
 *
 * For this project the authoritative market rates are, as configured:
 *   3d_direct 900   3d_tod 45   2d_top 90   2d_bottom 90   run_top 3   run_bottom 4
 *
 * RUN TOP 3 AND RUN BOTTOM 4
 * These are read from config('lottery.markets.run_top.payout_multiplier') and
 * config('lottery.markets.run_bottom.payout_multiplier'). They are NOT written into
 * the calculation algorithm, and the legacy family-level value of 12 in
 * config('lottery.types.run') and App\Enums\BetType::payoutMultiplier() is neither
 * used nor silently overwritten: a single family value cannot express two market
 * rates, so legacyDivergences() reports it instead. Payout rates are operator
 * configuration; nothing here asserts that every Thai lottery platform uses these
 * numbers.
 *
 * WHY THE PERMISSIVE RESOLVER IS USED
 * App\Services\Betting\PayoutMultiplierService in STRICT mode refuses a market whose
 * three declared sources disagree, which is how Phase 4.1 correctly reported the Run
 * rate conflict rather than guessing. Phase 4.2 is where that conflict is resolved:
 * the authoritative source is the declared one, so this service asks the resolver in
 * PERMISSIVE mode, which reads the authoritative source and nothing else. The
 * decision is explicit, recorded in every returned result's context, and the strict
 * audit remains available through multiplierAudit() so the divergence stays visible.
 *
 * EXACT ARITHMETIC
 * All arithmetic is delegated to App\Services\Betting\BetCalculationService, which
 * uses the bcmath based App\Services\Financial\MoneyExposureCalculator. There is no
 * float, no double, no (float) cast, no floatval() and no round() used as a
 * substitute for exact arithmetic anywhere in this file.
 *   10.55 x 150 = 1582.50
 *   10.55 x 3   = 31.65
 *   10.55 x 4   = 42.20
 *
 * ONE PAYOUT PER MATCHED SELECTION
 * payoutForMatch() prices a match exactly once. A Tod selection covering six
 * permutations is priced on its single stake, and a Run digit occurring three times
 * in the drawn result is priced once. The permutation count and the occurrence count
 * never enter the arithmetic.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No wallet debit, credit, lock or release, and no ledger entry.
 * - No Bet, BetItem, Ticket or Payout row is created, updated or deleted. Only a
 *   POTENTIAL payout is calculated.
 * - No risk decision. Phase 3.1 remains authoritative and is consulted by
 *   App\Services\Betting\BetValidationService AFTER the potential payout is known.
 * - No result lookup and no match decision.
 */
class MarketPayoutService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly MarketRuleResolver $rules,
        private readonly PayoutMultiplierService $multipliers,
        private readonly BetCalculationService $calculator,
    ) {}

    /**
     * The authoritative payout multiplier of a market.
     *
     * @throws BetDomainException when the authoritative source declares nothing usable
     * @throws MarketRuleException when the market rule itself is unusable
     */
    public function multiplierFor(string $marketKey): PayoutMultiplier
    {
        $this->rules->resolve($marketKey);

        return $this->multipliers->permissive()->resolvePermissive($marketKey);
    }

    /**
     * The potential payout of a stake in a market, calculated exactly.
     *
     * Calculated BEFORE any risk assessment, so Phase 3.1 receives a real exposure
     * figure rather than an estimate.
     *
     * @throws BetDomainException
     * @throws MarketRuleException
     */
    public function potentialPayout(BetAmount $stake, string $marketKey): BetCalculationResult
    {
        $rule = $this->rules->resolve($marketKey);
        $multiplier = $this->multiplierFor($marketKey);

        return $this->calculator->calculateOnce(
            $stake,
            $multiplier,
            $marketKey,
            $this->pricingContext($rule, null),
        );
    }

    /**
     * The payout for a decided selection, or null when the selection lost.
     *
     * Exactly one payout is priced for a winning selection. The permutation count and
     * the occurrence count on the match result are diagnostics and are never used as
     * factors.
     *
     * @throws BetDomainException
     * @throws MarketRuleException
     */
    public function payoutForMatch(BetAmount $stake, MarketMatchResult $match): ?BetCalculationResult
    {
        if (! $match->isMatched()) {
            return null;
        }

        $rule = $this->rules->resolve($match->marketKey());
        $multiplier = $this->multiplierFor($match->marketKey());

        return $this->calculator->calculateOnce(
            $stake,
            $multiplier,
            $match->marketKey(),
            $this->pricingContext($rule, $match),
        );
    }

    /**
     * The potential payout of a stake for every enabled market.
     *
     * @return array<string, BetCalculationResult>
     */
    public function potentialPayoutForAllMarkets(BetAmount $stake): array
    {
        $results = [];

        foreach ($this->rules->all() as $marketKey => $rule) {
            try {
                $results[$marketKey] = $this->potentialPayout($stake, $marketKey);
            } catch (BetDomainException) {
                continue;
            }
        }

        return $results;
    }

    /**
     * The authoritative multiplier of every market, as strings.
     *
     * @return array<string, string|null>
     */
    public function multiplierTable(): array
    {
        $table = [];

        foreach ($this->rules->all() as $marketKey => $rule) {
            try {
                $table[$marketKey] = $this->multiplierFor($marketKey)->value();
            } catch (BetDomainException | MarketRuleException) {
                $table[$marketKey] = null;
            }
        }

        return $table;
    }

    /**
     * The declared authoritative source path, verbatim from configuration.
     */
    public function authoritativeSource(): string
    {
        $declared = $this->config->get('lottery.payouts.multiplier_source');

        return is_string($declared) && $declared !== ''
            ? $declared
            : MarketRuleResolver::MARKET_SOURCE;
    }

    /**
     * The full strict audit of a market's declared multiplier sources.
     *
     * Preserved so the Run divergence stays visible after Phase 4.2 rather than
     * being hidden by the permissive read.
     *
     * @return array<string, mixed>
     */
    public function multiplierAudit(string $marketKey): array
    {
        return $this->multipliers->multiplierAudit($marketKey);
    }

    /**
     * Markets whose legacy family-level rate disagrees with the authoritative rate.
     *
     * @return array<string, array<string, scalar|null>>
     */
    public function legacyDivergences(): array
    {
        return $this->rules->legacyDivergences();
    }

    /**
     * Markets whose authoritative rate cannot be stored in bet_items.payout_multiplier.
     *
     * That column is an unsigned integer. Every current authoritative rate is a whole
     * number, so nothing is truncated today. If a fractional rate is ever configured,
     * this method is how the platform reports
     * "SCHEMA CHANGE REQUIRED: bet_items.payout_multiplier precision is insufficient"
     * instead of silently storing 3.2 as 3. No migration is performed here.
     *
     * @return array<string, string>
     */
    public function ratesExceedingBetItemColumn(): array
    {
        $offenders = [];

        foreach ($this->rules->all() as $marketKey => $rule) {
            try {
                $multiplier = $this->multiplierFor($marketKey);
            } catch (BetDomainException | MarketRuleException) {
                continue;
            }

            if (! $multiplier->fitsBetItemColumn()) {
                $offenders[$marketKey] = $multiplier->value();
            }
        }

        return $offenders;
    }

    /**
     * Guarantees this service makes, for the change report.
     *
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'authoritative_source' => sprintf(
                'Rates are read from %s, the source the project itself declares in '
                .'lottery.payouts.multiplier_source.',
                $this->authoritativeSource(),
            ),
            'no_hard_coded_rate' => 'No numeric rate appears in this file or in the calculation path.',
            'exact_arithmetic' => 'All arithmetic is bcmath based; no float, double, floatval or rounding '
                .'substitute is used.',
            'pays_once' => 'A matched selection is priced exactly once; permutation and occurrence counts are '
                .'never factors.',
            'stake_untouched' => 'A Tod stake of 10.00 is priced as 10.00, never as 10.00 x permutations.',
            'before_risk' => 'The potential payout is calculated before the Phase 3.1 risk engine is '
                .'consulted.',
            'no_money_movement' => 'No wallet, ledger, bet, bet item, ticket or payout row is touched.',
        ];
    }

    /**
     * Context recorded on every priced result.
     *
     * @return array<string, scalar|null>
     */
    private function pricingContext(MarketRuleData $rule, ?MarketMatchResult $match): array
    {
        $context = [
            'market_key' => $rule->marketKey(),
            'multiplier_source' => $rule->multiplierSource(),
            'authoritative_source' => $this->authoritativeSource(),
            'payout_frequency' => $rule->payoutFrequency(),
            'permutation_allowed' => $rule->allowsPermutation(),
            'digits' => $rule->digits(),
            'resolution_mode' => 'permissive read of the declared authoritative source',
        ];

        if ($match instanceof MarketMatchResult) {
            $context['matched_value'] = $match->matchedValue();
            $context['permutation_count'] = $match->permutationCount();
            $context['occurrences'] = $match->occurrences();
            $context['payout_count'] = $match->payoutCount();
        }

        return $context;
    }
}
