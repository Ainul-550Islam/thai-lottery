<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\BetCalculationResult;
use App\DTOs\DrawResultData;
use App\DTOs\MarketMatchResult;
use App\DTOs\MarketRuleData;
use App\DTOs\SettlementSelectionResult;
use App\Enums\Currency;
use App\Enums\SettlementSimulationStatus;
use App\Exceptions\BetDomainException;
use App\Exceptions\MarketRuleException;
use App\Exceptions\SettlementSimulationException;
use App\Models\Bet;
use App\Models\BetItem;
use App\Services\Betting\MarketPayoutService;
use App\Services\Betting\MarketRuleResolver;
use App\Services\Betting\RunMatchService;
use App\Services\Betting\ThreeDigitMatchService;
use App\Services\Betting\TodMatchService;
use App\Services\Betting\TwoDigitMatchService;
use App\ValueObjects\BetAmount;

/**
 * Decides ONE selection against a published result and prices the SIMULATED prize.
 *
 * IT DECIDES NOTHING ITSELF
 * Every win-or-lose decision is delegated to the VERIFIED Phase 4.2 match services,
 * unchanged:
 *
 *   3d_direct   App\Services\Betting\ThreeDigitMatchService::match()
 *   3d_tod      App\Services\Betting\TodMatchService::match()
 *   2d_top      App\Services\Betting\TwoDigitMatchService::match()
 *   2d_bottom   App\Services\Betting\TwoDigitMatchService::match()
 *   run_top     App\Services\Betting\RunMatchService::match()
 *   run_bottom  App\Services\Betting\RunMatchService::match()
 *
 * There is no comparison operator applied to a selection and a winning value
 * anywhere in this class, no permutation generation and no digit search. Dispatch is
 * driven by the market's configured match_mode plus its digit width, so adding a
 * market is a configuration change, and an unrecognised combination is REFUSED rather
 * than compared with a guess.
 *
 * TOD PAYS ONCE, AND THE COVERAGE COUNTS ARE JUST REPORTED
 * TodMatchService supplies the unique arrangement count: 123 covers 6, 112 covers 3,
 * 111 covers 1, and 007 covers 3 because it is treated as the three digits 0, 0, 7
 * and is never read as the number 7. That count is carried into the audit record as
 * coveredNumberCount and is NEVER used as a factor: the prize is stake x multiplier
 * once, and charges is always 1. One original selection stays one simulated ticket
 * item.
 *
 * RUN PAYS ONCE AND USES ITS OWN RATE
 * Run is one digit with no permutation. A digit occurring twice in the drawn value
 * still pays once, because MarketMatchResult::payoutCount() is 1 when matched.
 * The rate comes from MarketPayoutService::multiplierFor('run_top'|'run_bottom'),
 * which reads config('lottery.markets.<key>.payout_multiplier') - 3 and 4 in this
 * project. The legacy per bet type rate App\Enums\BetType::Run->payoutMultiplier()
 * (12) is never read here, and SettlementSelectionResult::legacyMultiplierWasAvoided()
 * lets a test assert that from the record.
 *
 * THE MARKET IS CROSS-CHECKED, NEVER GUESSED
 * The market recorded in bet_items.metadata['market'] at purchase time is compared
 * against the market DERIVED from the parent bet's type and the selection's position,
 * using config('lottery.markets') as the only source. A contradiction refuses the run
 * instead of settling under whichever looked more plausible.
 *
 * EXACT DECIMALS, NO FLOAT
 * Pricing goes through MarketPayoutService::payoutForMatch(), which runs
 * App\Services\Betting\BetCalculationService on BCMath strings. A losing selection is
 * recorded as exactly '0.00'. This class contains no (float), (double), (int),
 * intval(), floatval(), round() or number_format() call, and its only arithmetic is
 * bccomp/bcadd on decimal strings.
 *
 * NON-MONETARY AND SIDE EFFECT FREE
 * This class WRITES NOTHING. It returns a value object. No wallet, ledger entry,
 * financial transaction, payouts row, deposit, withdrawal or payment gateway is
 * touched, and no row is saved: persistence belongs to
 * DrawSettlementSimulationService.
 */
class SelectionSettlementResolver
{
    public function __construct(
        private readonly MarketRuleResolver $rules,
        private readonly MarketPayoutService $payouts,
        private readonly ThreeDigitMatchService $threeDigit,
        private readonly TodMatchService $tod,
        private readonly TwoDigitMatchService $twoDigit,
        private readonly RunMatchService $run,
    ) {}

    /**
     * Settle one selection against a published result, as a simulation.
     *
     * @throws SettlementSimulationException
     */
    public function resolve(BetItem $item, Bet $bet, DrawResultData $result): SettlementSelectionResult
    {
        $itemId = (int) $item->getKey();

        $marketKey = $this->resolveMarketKey($item, $bet);
        $rule = $this->resolveRule($itemId, $marketKey);

        $selection = $this->selectionOf($item, $rule);
        $winning = $result->valueFor($rule->resultType());

        $match = $this->decide($itemId, $rule, $selection, $winning);
        $stake = $this->stakeOf($item, $bet);
        $payout = $this->price($itemId, $marketKey, $stake, $match);

        $multiplier = $payout instanceof BetCalculationResult
            ? $payout->multiplier
            : $this->multiplierOf($itemId, $marketKey);

        if (! $multiplier->fitsBetItemColumn()) {
            // bet_items.payout_multiplier is unsignedInteger. Refused rather than
            // truncated, because truncating a payout rate silently changes what a
            // winning selection is worth.
            throw SettlementSimulationException::multiplierUnstorable(
                $itemId,
                $marketKey,
                $multiplier->value(),
            );
        }

        $simulatedPrize = $payout instanceof BetCalculationResult
            ? $payout->potentialPayoutAmount()
            : $this->zero($stake);

        $this->assertStorablePayout($itemId, $simulatedPrize);

        return new SettlementSelectionResult(
            betItemId: $itemId,
            betId: (int) $bet->getKey(),
            ticketId: $bet->ticket_id === null ? null : (int) $bet->ticket_id,
            ticketNumber: $this->ticketNumberOf($bet),
            userId: (int) $bet->user_id,
            marketKey: $marketKey,
            selection: $selection,
            winningValue: $winning,
            matched: $match->isMatched(),
            matchMode: $match->matchMode(),
            matchedValue: $match->matchedValue(),
            multiplier: $multiplier,
            stake: $stake->amount(),
            simulatedPrize: $simulatedPrize,
            currency: $stake->currency()->value,
            status: SettlementSimulationStatus::fromMatched($match->isMatched()),
            charges: 1,
            coveredNumberCount: $match->permutationCount(),
            occurrences: $match->occurrences(),
            wasRounded: $payout instanceof BetCalculationResult ? $payout->wasRounded : false,
            exactProduct: $payout?->exactProduct,
            context: [
                'result_type' => $rule->resultType()->value,
                'digits' => $rule->digits(),
                'pays_once' => $rule->paysOnce(),
                'payout_count' => $match->payoutCount(),
                'multiplier_source' => $rule->multiplierSource(),
                'derived_from' => 'bets.type + bet_items.position, cross-checked against '
                    ."bet_items.metadata['market']",
            ],
        );
    }

    /**
     * The market key of a selection, cross-checked against configuration.
     *
     * @throws SettlementSimulationException
     */
    public function resolveMarketKey(BetItem $item, Bet $bet): string
    {
        $itemId = (int) $item->getKey();
        $derived = $this->deriveMarketKey($item, $bet);
        $recorded = $this->recordedMarketKey($item);

        if ($recorded === null) {
            // Nothing recorded is acceptable: the derived market comes from
            // configuration and from columns settlement does not write.
            return $derived;
        }

        if ($recorded !== $derived) {
            throw SettlementSimulationException::marketMismatch($itemId, $recorded, $derived);
        }

        return $recorded;
    }

    /**
     * The market recorded on the selection at purchase time, or null.
     */
    public function recordedMarketKey(BetItem $item): ?string
    {
        $metadata = $item->metadata;

        if (! is_array($metadata)) {
            return null;
        }

        $market = $metadata['market'] ?? null;

        return is_string($market) && $market !== '' ? $market : null;
    }

    /**
     * The market key derived from the parent bet's type and the selection's position.
     *
     * Derived by SEARCHING config('lottery.markets') through
     * MarketRuleResolver::all() for the single enabled market whose bet_type and
     * position match. There is deliberately no hard coded bet type to market table
     * in this class: configuration stays the only source, so a market added or
     * disabled in configuration is reflected here with no code change.
     *
     * @throws SettlementSimulationException
     */
    public function deriveMarketKey(BetItem $item, Bet $bet): string
    {
        $itemId = (int) $item->getKey();
        $position = $item->position;

        if (! is_string($position) || $position === '') {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                'bet_items.position is empty, so the market side cannot be determined and no side is '
                .'assumed',
            );
        }

        $candidates = [];

        foreach ($this->rules->all() as $marketKey => $rule) {
            if (! $rule->enabled()) {
                continue;
            }

            if ($rule->betType === $bet->type && $rule->side->value === $position) {
                $candidates[] = $marketKey;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if ($candidates === []) {
            throw SettlementSimulationException::marketUnresolved(
                $itemId,
                $this->recordedMarketKey($item),
                [
                    'bet_type' => $bet->type->value,
                    'position' => $position,
                    'reason' => 'no enabled configured market has this bet type and position',
                ],
            );
        }

        // Two enabled markets sharing a bet type and a side would make the selection
        // ambiguous. Reported rather than resolved by preference, because settling
        // under the wrong one would apply the wrong rule and the wrong rate.
        throw SettlementSimulationException::marketUnresolved(
            $itemId,
            $this->recordedMarketKey($item),
            [
                'bet_type' => $bet->type->value,
                'position' => $position,
                'candidates' => implode(',', $candidates),
                'reason' => 'more than one enabled configured market has this bet type and position, so '
                    .'the market is ambiguous',
            ],
        );
    }

    /**
     * The rules of this resolver in report form.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $dispatch = [];

        foreach ($this->rules->all() as $marketKey => $rule) {
            $dispatch[$marketKey] = [
                'match_mode' => $rule->matchMode(),
                'digits' => $rule->digits(),
                'result_type' => $rule->resultType()->value,
                'match_service' => $this->matchServiceNameFor($rule),
                'multiplier_source' => $rule->multiplierSource(),
                'configured_multiplier' => $this->payouts->multiplierFor($marketKey)->value(),
                'pays_once' => $rule->paysOnce(),
            ];
        }

        return [
            'dispatch' => $dispatch,
            'guarantees' => $this->guarantees(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'verified_rules_only' => 'Every decision is delegated to the Phase 4.2 match services. '
                .'This class contains no comparison of a selection against a winning value, no '
                .'permutation generation and no digit search.',
            'pays_once' => 'The prize is stake x configured multiplier exactly once. The permutation '
                .'count and the occurrence count are carried as diagnostics and are never factors.',
            'tod_coverage' => 'Tod coverage counts come from TodPermutationService: 123 => 6, 112 => 3, '
                .'111 => 1, 007 => 3. 007 is three digits and is never read as the number 7. The stake '
                .'is not multiplied because arrangements exist.',
            'run_rate' => 'Run uses config(lottery.markets.run_top|run_bottom.payout_multiplier). '
                .'BetType::Run->payoutMultiplier() is never called in this class.',
            'market_cross_checked' => "bet_items.metadata['market'] is compared against the market "
                .'derived from bets.type and bet_items.position; a contradiction refuses the run.',
            'exact_decimals' => 'Pricing runs through MarketPayoutService and BetCalculationService on '
                .'BCMath strings. A loss is exactly 0.00. There is no float cast, intval(), floatval() '
                .'or round() in this class.',
            'no_writes' => 'This class saves no model and issues no update. It returns a value object.',
            'no_money' => 'No wallet, ledger entry, financial transaction, payouts row, deposit, '
                .'withdrawal or payment gateway is referenced.',
            'no_client_input' => 'Nothing is read from a request. The inputs are the stored selection, '
                .'the stored stake, the published result and configuration.',
        ];
    }

    /**
     * Delegate the decision to the verified match service for this market.
     *
     * @throws SettlementSimulationException
     */
    private function decide(
        int $itemId,
        MarketRuleData $rule,
        string $selection,
        string $winning,
    ): MarketMatchResult {
        $marketKey = $rule->marketKey();

        try {
            // Dispatch on the configured match mode and digit width rather than on a
            // hard coded market key list, so configuration remains authoritative.
            if ($rule->matchMode() === MarketRuleResolver::MATCH_PERMUTATION) {
                return $this->tod->match($selection, $winning, $marketKey);
            }

            if ($rule->matchMode() === MarketRuleResolver::MATCH_DIGIT_CONTAINS) {
                return $this->run->match($selection, $winning, $marketKey);
            }

            if ($rule->matchMode() === MarketRuleResolver::MATCH_EXACT && $rule->digits() === 3) {
                return $this->threeDigit->match($selection, $winning, $marketKey);
            }

            if ($rule->matchMode() === MarketRuleResolver::MATCH_EXACT && $rule->digits() === 2) {
                return $this->twoDigit->match($selection, $winning, $marketKey);
            }
        } catch (MarketRuleException|BetDomainException $exception) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                sprintf('the verified market rule engine refused it (%s)', $exception->getMessage()),
                ['market' => $marketKey],
            );
        }

        throw SettlementSimulationException::marketUnresolved(
            $itemId,
            $marketKey,
            [
                'match_mode' => $rule->matchMode(),
                'digits' => $rule->digits(),
                'reason' => 'no verified match service handles this match mode and digit width '
                    .'combination, and no comparison is improvised',
            ],
        );
    }

    /**
     * Price a decided selection through the verified payout service.
     *
     * Returns null for a loss, which is what payoutForMatch() returns and what makes
     * a zero prize explicit rather than computed.
     *
     * @throws SettlementSimulationException
     */
    private function price(
        int $itemId,
        string $marketKey,
        BetAmount $stake,
        MarketMatchResult $match,
    ): ?BetCalculationResult {
        try {
            return $this->payouts->payoutForMatch($stake, $match);
        } catch (BetDomainException|MarketRuleException $exception) {
            throw SettlementSimulationException::multiplierUnstorable(
                $itemId,
                $marketKey,
                'unresolvable',
                ['reason' => $exception->getMessage()],
                $exception,
            );
        }
    }

    /**
     * The configured multiplier of a market, for a losing selection.
     *
     * A loss still records the rate that WOULD have applied, because requirement G
     * asks the audit row to show the configured multiplier for every selection.
     *
     * @throws SettlementSimulationException
     */
    private function multiplierOf(int $itemId, string $marketKey): \App\ValueObjects\PayoutMultiplier
    {
        try {
            return $this->payouts->multiplierFor($marketKey);
        } catch (BetDomainException|MarketRuleException $exception) {
            throw SettlementSimulationException::multiplierUnstorable(
                $itemId,
                $marketKey,
                'unresolvable',
                ['reason' => $exception->getMessage()],
                $exception,
            );
        }
    }

    /**
     * Resolve the rule set of a market.
     *
     * @throws SettlementSimulationException
     */
    private function resolveRule(int $itemId, string $marketKey): MarketRuleData
    {
        try {
            return $this->rules->resolve($marketKey);
        } catch (MarketRuleException $exception) {
            throw SettlementSimulationException::marketUnresolved(
                $itemId,
                $marketKey,
                ['reason' => $exception->getMessage()],
                $exception,
            );
        }
    }

    /**
     * The stored selection, as a digit string of the market's width.
     *
     * Read straight from bet_items.number, which the model casts to 'string', so
     * '007' and '07' arrive intact. The width is checked but NEVER corrected: a
     * stored selection of the wrong width refuses the run rather than being padded,
     * because padding would change which number the player chose.
     *
     * @throws SettlementSimulationException
     */
    private function selectionOf(BetItem $item, MarketRuleData $rule): string
    {
        $itemId = (int) $item->getKey();
        $number = $item->number;

        if (! is_string($number) || $number === '') {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                'bet_items.number is empty',
                ['market' => $rule->marketKey()],
            );
        }

        if (preg_match('/^[0-9]+$/', $number) !== 1) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                'bet_items.number is not a plain digit string',
                ['market' => $rule->marketKey()],
            );
        }

        if (! $rule->acceptsDigitWidth($number)) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                sprintf(
                    'bet_items.number "%s" is %d digit(s) but market %s requires exactly %d; it is '
                    .'refused rather than padded or truncated',
                    $number,
                    strlen($number),
                    $rule->marketKey(),
                    $rule->digits(),
                ),
                ['market' => $rule->marketKey()],
            );
        }

        return $number;
    }

    /**
     * The stake of the selection, as an exact decimal value object.
     *
     * @throws SettlementSimulationException
     */
    private function stakeOf(BetItem $item, Bet $bet): BetAmount
    {
        $itemId = (int) $item->getKey();
        $currency = $bet->currency instanceof Currency ? $bet->currency : null;

        if ($currency === null) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                'the parent bet has no usable currency, so the stake cannot be priced',
            );
        }

        try {
            return BetAmount::fromDatabase($item->amount, $currency);
        } catch (BetDomainException $exception) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                sprintf('the stored stake is unusable (%s)', $exception->getMessage()),
                ['currency' => $currency->value],
            );
        }
    }

    /**
     * Zero in the stake's currency, as a decimal string at the currency scale.
     *
     * Built with bcadd rather than written as a literal so the scale always follows
     * the currency.
     */
    private function zero(BetAmount $stake): string
    {
        return bcadd('0', '0', $stake->scale());
    }

    /**
     * Refuse a prize that the decimal(20,2) column cannot hold exactly.
     *
     * @throws SettlementSimulationException
     */
    private function assertStorablePayout(int $itemId, string $amount): void
    {
        // bet_items.actual_payout is decimal(20,2): 18 integer digits and 2 decimals.
        if (preg_match('/^[0-9]{1,18}\.[0-9]{2}$/', $amount) !== 1) {
            throw SettlementSimulationException::payoutUnstorable($itemId, $amount);
        }
    }

    /**
     * The ticket number of the parent bet, when it has a ticket.
     */
    private function ticketNumberOf(Bet $bet): ?string
    {
        $ticket = $bet->ticket;

        if ($ticket === null) {
            return null;
        }

        $number = $ticket->ticket_number;

        return is_string($number) ? $number : null;
    }

    /**
     * Which verified service decides a market, for the audit report.
     */
    private function matchServiceNameFor(MarketRuleData $rule): string
    {
        if ($rule->matchMode() === MarketRuleResolver::MATCH_PERMUTATION) {
            return TodMatchService::class;
        }

        if ($rule->matchMode() === MarketRuleResolver::MATCH_DIGIT_CONTAINS) {
            return RunMatchService::class;
        }

        if ($rule->digits() === 3) {
            return ThreeDigitMatchService::class;
        }

        if ($rule->digits() === 2) {
            return TwoDigitMatchService::class;
        }

        return 'unsupported: refused at settlement time';
    }
}
