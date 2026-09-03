<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetCalculationResult;
use App\DTOs\BetSelectionData;
use App\DTOs\BetValidationResult;
use App\Enums\BetMarket;
use App\Enums\BetSelectionType;
use App\Enums\BetSide;
use App\Enums\BetType;
use App\Enums\BetValidationCode;
use App\Exceptions\BetDomainException;
use App\Exceptions\InvalidBetAmountException;
use App\Exceptions\InvalidLotteryNumberException;
use App\Exceptions\MarketRuleException;
use App\Exceptions\RiskException;
use App\Exceptions\UnsupportedBetMarketException;
use App\Models\Draw;
use App\Services\Risk\RiskAssessmentService;
use App\ValueObjects\BetAmount;
use App\ValueObjects\LotteryNumber;
use App\ValueObjects\PayoutMultiplier;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Validates a betting selection against every rule the project actually declares.
 *
 * VALIDATION ONLY - THIS IS THE HARD BOUNDARY
 * This service reads. It does not write. It does not debit a wallet, does not lock a
 * balance, does not create a hold, does not post a ledger entry, does not reserve
 * number-limit exposure, and does not create a Bet, BetItem, Ticket or Payout. The
 * risk engine is consulted with requireReservable = false, which is Phase 3.1's own
 * read-only inspection mode: it evaluates the selection against the limits without
 * touching current_stake_exposure or current_payout_exposure.
 *
 * The one consequence of that boundary must be stated plainly: an ACCEPT here is a
 * verdict about the moment it was computed, not a promise about the future. Between
 * this call and a purchase the draw can close, a number can fill and a limit can
 * tighten. The purchasing phase MUST re-validate and reserve inside its own
 * transaction and lock. Treating this result as a reservation would oversell numbers.
 *
 * VALIDATION ORDER (the specified 1-11)
 *   1. the draw exists
 *   2. the draw is valid for betting
 *   3. the market is supported
 *   4. the side is valid for the market
 *   5. the selection type is valid for the market
 *   6. the number is normalised / canonical
 *   7. the digit length is correct for the market
 *   8. the stake is valid
 *   9. the multiplier is valid
 *  10. the market-specific rule is valid
 *  11. the Phase 3 risk engine can be consulted
 *
 * The order matters and is enforced: a cheap structural refusal must never be
 * reported as a risk refusal, and the risk engine must never be asked about a
 * selection that is not even well formed. The failing step number is carried on the
 * result.
 *
 * NO RULE IS INVENTED
 * Where a rule is not declared by the project, the selection is refused with
 * SPECIFICATION REQUIRED and the missing rule is named. Nothing is guessed.
 *
 * PHASE 4.2 MODIFICATION
 * Phase 4.1 refused 3D Tod and both Run markets because three market rules were
 * undeclared: the Tod combination rule, the Run digit rule and the Run multiplier.
 * Phase 4.2 declares all three, so those refusals are no longer correct. What
 * changed here, and nothing else:
 *
 *   1. Two dependencies were appended to the constructor:
 *      App\Services\Betting\MarketRuleResolver and
 *      App\Services\Betting\MarketPayoutService. Existing parameters keep their
 *      order and type, so container resolution and any positional construction of
 *      the six original arguments is unaffected.
 *
 *   2. STEP 9 resolves the rate through MarketPayoutService, which reads the source
 *      the project itself declares in config('lottery.payouts.multiplier_source').
 *      Previously it used PayoutMultiplierService in STRICT mode, which refuses a
 *      market whose three declared sources disagree - correct while the Run rate was
 *      unspecified, wrong once the authoritative rate is declared. The strict audit
 *      is still reported by marketReadiness() and by
 *      MarketPayoutService::multiplierAudit(), so the legacy divergence stays
 *      visible instead of disappearing.
 *
 *   3. STEP 10 validates against the resolved App\DTOs\MarketRuleData instead of
 *      refusing Tod through BetSelectionType::requiresUnspecifiedCombinationRule()
 *      and instead of treating the legacy digit audit as a refusal. The digit audit
 *      is still consulted and still reported, but a disagreement between the
 *      authoritative market rule and a legacy family-level descriptor is now a
 *      recorded compatibility note rather than a refusal.
 *
 *   4. The potential payout is computed with BetCalculationService::calculateOnce(),
 *      which states the payout frequency rule: a matched selection pays once, and
 *      neither a Tod permutation count nor a repeated Run digit scales it.
 *
 * Every public method, every step constant and every returned type is unchanged.
 *
 * THE RESOLVED MARKET RULES
 *   3D DIRECT  3 digits, exact, no permutation, 3 digit top result
 *   3D TOD     3 digits, unique permutations, one stake, pays once, 3 digit top
 *   2D TOP     2 digits, exact, 2 digit top result
 *   2D BOTTOM  2 digits, exact, 2 digit bottom result
 *   RUN TOP    1 digit, no permutation, contained in the 3 digit top result
 *   RUN BOTTOM 1 digit, no permutation, contained in the 2 digit bottom result
 * These are operator style rules and all payout rates remain configuration.
 *
 * RISK IS NOT REIMPLEMENTED
 * Phase 3.1 is authoritative for risk. NumberLimitEngine, NumberLimitResolver,
 * ExposureCalculator, RiskDecisionService and RiskAssessmentService are used, not
 * duplicated. There is no second risk engine here, and the engine's verdict is
 * carried through verbatim rather than re-encoded.
 */
class BetValidationService
{
    public const STEP_DRAW_EXISTS = 1;
    public const STEP_DRAW_OPEN = 2;
    public const STEP_MARKET_SUPPORTED = 3;
    public const STEP_SIDE_VALID = 4;
    public const STEP_SELECTION_TYPE_VALID = 5;
    public const STEP_NUMBER_CANONICAL = 6;
    public const STEP_DIGIT_LENGTH = 7;
    public const STEP_STAKE_VALID = 8;
    public const STEP_MULTIPLIER_VALID = 9;
    public const STEP_MARKET_RULE = 10;
    public const STEP_RISK = 11;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly LotteryNumberService $numbers,
        private readonly BetAmountService $amounts,
        private readonly PayoutMultiplierService $multipliers,
        private readonly BetCalculationService $calculator,
        private readonly RiskAssessmentService $risk,
        private readonly MarketRuleResolver $rules,
        private readonly MarketPayoutService $payouts,
    ) {
    }

    /**
     * Validate a raw request, building the selection as the checks proceed.
     *
     * Nothing throws out of this method: every refusal is a result. That is what lets
     * a caller report all of a ticket's problems at once instead of only the first.
     */
    public function validate(
        int $drawId,
        string $market,
        string $side,
        string $selectionType,
        string $rawNumber,
        string $rawStake,
        bool $consultRisk = true,
    ): BetValidationResult {
        // Structural parsing of the request vocabulary happens before step 1, since a
        // request that does not even name a market cannot be checked against a draw.
        $betMarket = BetMarket::tryFrom($market);

        if (! $betMarket instanceof BetMarket) {
            return BetValidationResult::rejected(
                BetValidationCode::InvalidMarket,
                sprintf(
                    'Market "%s" is not recognised. Supported markets: %s.',
                    $market,
                    implode(', ', array_column(BetMarket::cases(), 'value')),
                ),
                null,
                ['market' => $market],
                self::STEP_MARKET_SUPPORTED,
            );
        }

        $betSide = BetSide::tryFrom($side);

        if (! $betSide instanceof BetSide) {
            return BetValidationResult::rejected(
                BetValidationCode::InvalidSide,
                sprintf(
                    'Side "%s" is not recognised. Supported sides: %s.',
                    $side,
                    implode(', ', BetSide::values()),
                ),
                null,
                ['side' => $side],
                self::STEP_SIDE_VALID,
            );
        }

        $betSelectionType = BetSelectionType::tryFrom($selectionType);

        if (! $betSelectionType instanceof BetSelectionType) {
            return BetValidationResult::rejected(
                BetValidationCode::InvalidSelectionType,
                sprintf(
                    'Selection type "%s" is not recognised. Supported selection types: %s.',
                    $selectionType,
                    implode(', ', BetSelectionType::values()),
                ),
                null,
                ['selection_type' => $selectionType],
                self::STEP_SELECTION_TYPE_VALID,
            );
        }

        // The stake is parsed only for format here so a selection can be built; its
        // bounds are checked at step 8, in order.
        try {
            $currency = $this->amounts->currency();
            $stake = BetAmount::of($rawStake, $currency);
        } catch (InvalidBetAmountException $exception) {
            return BetValidationResult::rejected(
                BetValidationCode::InvalidAmount,
                $exception->getMessage(),
                null,
                $exception->context(),
                self::STEP_STAKE_VALID,
            );
        } catch (BetDomainException $exception) {
            return BetValidationResult::rejected(
                BetValidationCode::SpecificationRequired,
                $exception->getMessage(),
                null,
                $exception->context(),
                self::STEP_STAKE_VALID,
            );
        }

        $selection = new BetSelectionData(
            $drawId,
            $betMarket,
            $betSide,
            $betSelectionType,
            $rawNumber,
            $stake,
        );

        return $this->validateSelection($selection, $consultRisk);
    }

    /**
     * Validate an assembled selection through the specified 1-11 order.
     */
    public function validateSelection(BetSelectionData $selection, bool $consultRisk = true): BetValidationResult
    {
        // STEP 1 - the draw exists.
        $draw = Draw::query()->find($selection->drawId);

        if (! $draw instanceof Draw) {
            return BetValidationResult::rejected(
                BetValidationCode::DrawNotFound,
                sprintf('Draw %d does not exist.', $selection->drawId),
                $selection,
                ['draw_id' => $selection->drawId],
                self::STEP_DRAW_EXISTS,
            );
        }

        // STEP 2 - the draw is valid for betting.
        $drawCheck = $this->checkDraw($draw, $selection);

        if ($drawCheck instanceof BetValidationResult) {
            return $drawCheck;
        }

        // STEP 3 - the market is supported.
        $marketKey = $selection->market->resolveMarketKey($selection->selectionType, $selection->side);

        if ($marketKey === null) {
            // Distinguish WHY the combination does not resolve, so the player is told
            // the actionable thing rather than a generic refusal.
            if (! $selection->market->supportsSelectionType($selection->selectionType)) {
                return BetValidationResult::rejected(
                    BetValidationCode::InvalidSelectionType,
                    UnsupportedBetMarketException::selectionTypeNotAvailable(
                        $selection->market,
                        $selection->selectionType,
                    )->getMessage(),
                    $selection,
                    [
                        'market' => $selection->market->value,
                        'selection_type' => $selection->selectionType->value,
                    ],
                    self::STEP_SELECTION_TYPE_VALID,
                );
            }

            if (! $selection->market->supportsSide($selection->side)) {
                // This is the path a 3D Bottom request takes: 3D is TOP only, because
                // the project declares 3d_direct and 3d_tod at position 'top' and
                // declares no 3d_bottom market anywhere.
                return BetValidationResult::rejected(
                    BetValidationCode::InvalidSide,
                    UnsupportedBetMarketException::sideNotAvailable(
                        $selection->market,
                        $selection->side,
                    )->getMessage(),
                    $selection,
                    ['market' => $selection->market->value, 'side' => $selection->side->value],
                    self::STEP_SIDE_VALID,
                );
            }

            return BetValidationResult::rejected(
                BetValidationCode::UnsupportedMarket,
                UnsupportedBetMarketException::combinationNotSold(
                    $selection->market,
                    $selection->selectionType,
                    $selection->side,
                )->getMessage(),
                $selection,
                [
                    'market' => $selection->market->value,
                    'selection_type' => $selection->selectionType->value,
                    'side' => $selection->side->value,
                ],
                self::STEP_MARKET_SUPPORTED,
            );
        }

        $definition = $this->config->get('lottery.markets.'.$marketKey);

        if (! is_array($definition) || $definition === []) {
            return BetValidationResult::rejected(
                BetValidationCode::UnsupportedMarket,
                sprintf('Market %s is not available: its configuration is absent.', $marketKey),
                $selection,
                ['market_key' => $marketKey],
                self::STEP_MARKET_SUPPORTED,
            );
        }

        if (($definition['enabled'] ?? false) !== true) {
            return BetValidationResult::rejected(
                BetValidationCode::UnsupportedMarket,
                sprintf('Market %s is not available: it is disabled in configuration.', $marketKey),
                $selection,
                ['market_key' => $marketKey],
                self::STEP_MARKET_SUPPORTED,
            );
        }

        $betType = BetMarket::betTypeFor($definition);

        if (! $betType instanceof BetType) {
            return BetValidationResult::specificationRequired(
                sprintf(
                    'market %s does not declare a bet_type this project recognises, so the selection '
                    .'cannot be mapped onto bets.type',
                    $marketKey,
                ),
                ['bet type of market '.$marketKey],
                $selection,
                ['market_key' => $marketKey],
                self::STEP_MARKET_SUPPORTED,
            );
        }

        $selection = $selection->withResolvedMarket($marketKey, $betType);

        // STEP 4 - the side is valid for the market. Resolution above proves the side
        // is one this family sells; this re-reads the configured position so a
        // configuration edit that breaks the mapping is caught rather than trusted.
        $configuredSide = BetSide::fromPosition(
            is_string($definition['position'] ?? null) ? $definition['position'] : null,
        );

        if ($configuredSide !== $selection->side) {
            return BetValidationResult::specificationRequired(
                sprintf(
                    'market %s is configured at position %s but the selection asks for %s; the mapping '
                    .'between the domain side and the configured position is inconsistent',
                    $marketKey,
                    var_export($definition['position'] ?? null, true),
                    $selection->side->value,
                ),
                ['side mapping of market '.$marketKey],
                $selection,
                ['market_key' => $marketKey, 'side' => $selection->side->value],
                self::STEP_SIDE_VALID,
            );
        }

        // STEP 5 - the selection type is valid for the market, cross-checked against
        // the configured match mode.
        $configuredMatchMode = is_string($definition['match_mode'] ?? null) ? $definition['match_mode'] : null;

        if ($configuredMatchMode !== $selection->selectionType->configuredMatchMode()) {
            return BetValidationResult::specificationRequired(
                sprintf(
                    'market %s declares match_mode %s but selection type %s expects %s; the project '
                    .'does not state which governs',
                    $marketKey,
                    var_export($configuredMatchMode, true),
                    $selection->selectionType->value,
                    $selection->selectionType->configuredMatchMode(),
                ),
                ['match mode of market '.$marketKey],
                $selection,
                ['market_key' => $marketKey],
                self::STEP_SELECTION_TYPE_VALID,
            );
        }

        // STEP 6 and STEP 7 - the number is canonical and of the correct length.
        // parseStrict enforces both at once and refuses to pad or truncate, so '7'
        // for a 3D market and '99' where '099' is required are both rejected.
        try {
            $number = $this->numbers->parseStrict($selection->rawNumber, $marketKey);
        } catch (InvalidLotteryNumberException $exception) {
            $code = $exception->validationCode();

            if ($code === BetValidationCode::SpecificationRequired) {
                return BetValidationResult::specificationRequired(
                    $exception->getMessage(),
                    ['digit rule of market '.$marketKey],
                    $selection,
                    $exception->context(),
                    self::STEP_DIGIT_LENGTH,
                );
            }

            return BetValidationResult::rejected(
                $code,
                $exception->getMessage(),
                $selection,
                $exception->context(),
                $code === BetValidationCode::InvalidDigits
                    ? self::STEP_DIGIT_LENGTH
                    : self::STEP_NUMBER_CANONICAL,
            );
        } catch (UnsupportedBetMarketException $exception) {
            return BetValidationResult::rejected(
                $exception->validationCode(),
                $exception->getMessage(),
                $selection,
                $exception->context(),
                self::STEP_MARKET_SUPPORTED,
            );
        }

        if (! $this->numbers->isWithinConfiguredRange($number, $marketKey)) {
            return BetValidationResult::rejected(
                BetValidationCode::InvalidNumber,
                sprintf(
                    'Number %s is outside the configured range for market %s.',
                    $number->value(),
                    $marketKey,
                ),
                $selection->withNumber($number),
                ['number' => $number->value(), 'market_key' => $marketKey],
                self::STEP_NUMBER_CANONICAL,
            );
        }

        $selection = $selection->withNumber($number);

        // STEP 8 - the stake is valid.
        try {
            $this->amounts->validate($selection->stake);
        } catch (InvalidBetAmountException $exception) {
            return BetValidationResult::rejected(
                BetValidationCode::InvalidAmount,
                $exception->getMessage(),
                $selection,
                $exception->context(),
                self::STEP_STAKE_VALID,
            );
        } catch (BetDomainException $exception) {
            return BetValidationResult::specificationRequired(
                $exception->getMessage(),
                ['betting bounds'],
                $selection,
                $exception->context(),
                self::STEP_STAKE_VALID,
            );
        }

        // STEP 9 - the multiplier is valid. The rate is read from the source the
        // project declares as authoritative in
        // config('lottery.payouts.multiplier_source'), which is how Run Top 3 and Run
        // Bottom 4 are resolved without either rate being written into code. A market
        // that declares nothing usable at that source is still refused.
        try {
            $multiplier = $this->payouts->multiplierFor($marketKey);
        } catch (MarketRuleException $exception) {
            if ($exception->validationCode() === BetValidationCode::SpecificationRequired) {
                return BetValidationResult::specificationRequired(
                    $exception->getMessage(),
                    ['payout multiplier of market '.$marketKey],
                    $selection,
                    $exception->context(),
                    self::STEP_MULTIPLIER_VALID,
                );
            }

            return BetValidationResult::rejected(
                $exception->validationCode(),
                $exception->getMessage(),
                $selection,
                $exception->context(),
                self::STEP_MULTIPLIER_VALID,
            );
        } catch (BetDomainException $exception) {
            if ($exception->validationCode() === BetValidationCode::SpecificationRequired) {
                return BetValidationResult::specificationRequired(
                    $exception->getMessage(),
                    ['payout multiplier of market '.$marketKey],
                    $selection,
                    $exception->context(),
                    self::STEP_MULTIPLIER_VALID,
                );
            }

            return BetValidationResult::rejected(
                BetValidationCode::InvalidMultiplier,
                $exception->getMessage(),
                $selection,
                $exception->context(),
                self::STEP_MULTIPLIER_VALID,
            );
        } catch (UnsupportedBetMarketException $exception) {
            return BetValidationResult::rejected(
                $exception->validationCode(),
                $exception->getMessage(),
                $selection,
                $exception->context(),
                self::STEP_MARKET_SUPPORTED,
            );
        }

        $selection = $selection->withMultiplier($multiplier);

        // STEP 10 - the market-specific rule is valid.
        $ruleCheck = $this->checkMarketRule($selection, $marketKey, $betType);

        if ($ruleCheck instanceof BetValidationResult) {
            return $ruleCheck;
        }

        // The payout is computed here so the accepted result can report it, and
        // because a rate that cannot be applied exactly is itself a refusal reason.
        try {
            $calculation = $this->calculator->calculateOnce(
                $selection->stake,
                $multiplier,
                $marketKey,
                ['multiplier_source' => $this->payouts->authoritativeSource()],
            );
        } catch (BetDomainException $exception) {
            return BetValidationResult::rejected(
                BetValidationCode::InvalidMultiplier,
                $exception->getMessage(),
                $selection,
                $exception->context(),
                self::STEP_MULTIPLIER_VALID,
            );
        }

        // STEP 11 - the Phase 3 risk engine can be consulted.
        if (! $consultRisk) {
            return BetValidationResult::accepted($selection, $calculation, [
                'risk_consulted' => false,
                'market_rule' => $this->rules->tryResolve($marketKey)?->describe(),
                'note' => 'Risk consultation was skipped by the caller. This result says nothing about '
                    .'number limits or exposure.',
            ]);
        }

        return $this->consultRisk($selection, $calculation, $marketKey, $betType, $multiplier);
    }

    /**
     * Validate several selections, returning one result each.
     *
     * @param  iterable<BetSelectionData>  $selections
     * @return list<BetValidationResult>
     */
    public function validateAll(iterable $selections, bool $consultRisk = true): array
    {
        $results = [];

        foreach ($selections as $selection) {
            $results[] = $this->validateSelection($selection, $consultRisk);
        }

        return $results;
    }

    /**
     * The single verdict for a whole ticket: the first refusal, or the acceptance.
     *
     * @param  iterable<BetSelectionData>  $selections
     */
    public function validateTicket(iterable $selections, bool $consultRisk = true): BetValidationResult
    {
        return BetValidationResult::mostRestrictive($this->validateAll($selections, $consultRisk));
    }

    /**
     * Which markets can be validated at all right now, and which cannot.
     *
     * Reported for the audit: a market whose rule is undeclared is listed as
     * requiring a specification rather than quietly failing at request time.
     *
     * @return array<string, array{validatable: bool, reason: string}>
     */
    public function marketReadiness(): array
    {
        $readiness = [];

        foreach (BetMarket::allMarketKeys() as $marketKey) {
            $triple = BetMarket::decomposeMarketKey($marketKey);

            if ($triple === null) {
                $readiness[$marketKey] = [
                    'validatable' => false,
                    'reason' => 'the market key does not decompose into a declared family/selection/side',
                ];

                continue;
            }

            // PHASE 4.2 - readiness now follows the authoritative market rule. A
            // market is validatable when its rule resolves and its rate resolves at
            // the declared authoritative source. The strict multi-source audit is
            // still read, but a divergence between the authoritative source and a
            // legacy descriptor is reported as a compatibility note instead of making
            // the market unvalidatable.
            try {
                $rule = $this->rules->resolve($marketKey);
            } catch (MarketRuleException $exception) {
                $readiness[$marketKey] = ['validatable' => false, 'reason' => $exception->getMessage()];

                continue;
            }

            try {
                $multiplier = $this->payouts->multiplierFor($marketKey);
            } catch (MarketRuleException|UnsupportedBetMarketException|BetDomainException $exception) {
                $readiness[$marketKey] = ['validatable' => false, 'reason' => $exception->getMessage()];

                continue;
            }

            $digitAudit = $this->numbers->digitRuleAudit($marketKey);

            if (($digitAudit['specification_required'] ?? false) === true
                && ($digitAudit['market_digits'] ?? null) === null) {
                $readiness[$marketKey] = [
                    'validatable' => false,
                    'reason' => 'SPECIFICATION REQUIRED: '.(string) $digitAudit['note'],
                ];

                continue;
            }

            $notes = [];

            if (($digitAudit['specification_required'] ?? false) === true) {
                $notes[] = 'legacy digit descriptor divergence (non-blocking): '.(string) $digitAudit['note'];
            }

            $strictAudit = $this->payouts->multiplierAudit($marketKey);

            if (($strictAudit['specification_required'] ?? false) === true) {
                $notes[] = 'legacy multiplier divergence (non-blocking, authoritative source wins): '
                    .(string) $strictAudit['note'];
            }

            if (! $multiplier->fitsBetItemColumn()) {
                $notes[] = sprintf(
                    'SCHEMA CHANGE REQUIRED to persist rate %s: bet_items.payout_multiplier is an '
                    .'unsigned integer column. No migration has been created.',
                    $multiplier->value(),
                );
            }

            $readiness[$marketKey] = [
                'validatable' => true,
                'reason' => sprintf(
                    '%s; rate %s from %s%s',
                    $rule->describe(),
                    $multiplier->value(),
                    $this->payouts->authoritativeSource(),
                    $notes === [] ? '' : ' | '.implode(' | ', $notes),
                ),
            ];
        }

        return $readiness;
    }

    /**
     * A statement of what this service does NOT do, for the validation report.
     *
     * @return array<string, bool|string>
     */
    public function guarantees(): array
    {
        return [
            'mutates_wallet_balance' => false,
            'mutates_locked_balance' => false,
            'creates_wallet_hold' => false,
            'creates_financial_transaction' => false,
            'creates_ledger_entry' => false,
            'reserves_number_limit_exposure' => false,
            'creates_bet' => false,
            'creates_bet_item' => false,
            'creates_ticket' => false,
            'creates_payout' => false,
            'writes_any_row' => false,
            'reads' => 'draws (by id), config/lottery.php, and the Phase 3.1 risk engine in '
                .'read-only inspection mode',
            'risk_mode' => 'RiskAssessmentService::assessNumber(..., requireReservable: false)',
            'bypasses_risk_engine' => false,
            'has_skip_validation_option' => false,
            'has_emergency_bypass' => false,
            'market_rule_source' => 'App\Services\Betting\MarketRuleResolver over config(lottery.markets)',
            'multiplier_source' => $this->payouts->authoritativeSource(),
            'payout_frequency' => 'one payout per matched selection; permutation and occurrence counts '
                .'never scale a payout',
        ];
    }

    /**
     * Step 2: is this draw open for betting.
     *
     * The existing Draw model and DrawStatus are used as they are. draws already
     * carries status, betting_open_at and betting_close_at, and DrawStatus already
     * exposes canAcceptBets(), so the betting-open state is fully determinable from
     * the current schema and no new draw-status system is introduced and no migration
     * is created.
     */
    private function checkDraw(Draw $draw, BetSelectionData $selection): ?BetValidationResult
    {
        if ($this->config->get('lottery.betting.require_open_draw') === true && ! $draw->canAcceptBets()) {
            return BetValidationResult::rejected(
                BetValidationCode::DrawClosed,
                sprintf(
                    'Draw %s is not accepting bets (status %s).',
                    (string) $draw->draw_number,
                    is_object($draw->status) && property_exists($draw->status, 'value')
                        ? (string) $draw->status->value
                        : (string) $draw->status,
                ),
                $selection,
                ['draw_id' => $draw->id, 'draw_number' => $draw->draw_number],
                self::STEP_DRAW_OPEN,
            );
        }

        $now = now();
        $opensAt = $draw->betting_open_at;
        $closesAt = $draw->betting_close_at;

        if ($opensAt !== null && $now->lessThan($opensAt)) {
            return BetValidationResult::rejected(
                BetValidationCode::DrawClosed,
                sprintf('Betting on draw %s has not opened yet.', (string) $draw->draw_number),
                $selection,
                ['draw_id' => $draw->id, 'betting_open_at' => (string) $opensAt],
                self::STEP_DRAW_OPEN,
            );
        }

        if ($closesAt !== null && $now->greaterThanOrEqualTo($closesAt)) {
            return BetValidationResult::rejected(
                BetValidationCode::DrawClosed,
                sprintf('Betting on draw %s has closed.', (string) $draw->draw_number),
                $selection,
                ['draw_id' => $draw->id, 'betting_close_at' => (string) $closesAt],
                self::STEP_DRAW_OPEN,
            );
        }

        return null;
    }

    /**
     * Step 10: the rule specific to this market.
     *
     * Each branch either confirms a rule the project declares or refuses because the
     * rule is not declared. No branch invents one, and there is no giant if/else
     * chain of future lottery rules: a new market adds a case to the market rule map,
     * not a clause here.
     */
    private function checkMarketRule(
        BetSelectionData $selection,
        string $marketKey,
        BetType $betType,
    ): ?BetValidationResult {
        // PHASE 4.2 - the authoritative market rule is resolved rather than guessed.
        // The resolver reads config('lottery.markets.<key>') and separates the seven
        // concerns the rule actually has: market, selection width, permutation
        // behaviour, digit length, result source, multiplier source and payout
        // frequency. If the rule is malformed the resolver refuses; it never invents.
        try {
            $rule = $this->rules->resolve($marketKey);
        } catch (MarketRuleException $exception) {
            if ($exception->validationCode() === BetValidationCode::SpecificationRequired) {
                return BetValidationResult::specificationRequired(
                    $exception->getMessage(),
                    ['market rule of market '.$marketKey],
                    $selection,
                    $exception->context(),
                    self::STEP_MARKET_RULE,
                );
            }

            return BetValidationResult::rejected(
                $exception->validationCode(),
                $exception->getMessage(),
                $selection,
                $exception->context(),
                self::STEP_MARKET_RULE,
            );
        }

        // The selection must be exactly as wide as the rule declares, with leading
        // zeros intact. '07' stays '07' and a Run selection is one digit, never two.
        $number = $selection->number;

        if ($number instanceof LotteryNumber && ! $rule->acceptsDigitWidth($number->value())) {
            return BetValidationResult::rejected(
                BetValidationCode::InvalidDigits,
                sprintf(
                    'Market %s requires exactly %d digit(s); %s has %d.',
                    $marketKey,
                    $rule->digits(),
                    $number->value(),
                    strlen($number->value()),
                ),
                $selection,
                [
                    'market_key' => $marketKey,
                    'number' => $number->value(),
                    'required_digits' => $rule->digits(),
                ],
                self::STEP_DIGIT_LENGTH,
            );
        }

        // A permutation-mode market must be one the rule says permutes, and a
        // containment market must never permute. This is the Run rule stated as a
        // check: RUN = NO PERMUTATION.
        if ($selection->selectionType->allowsPermutation() && ! $rule->allowsPermutation()) {
            return BetValidationResult::rejected(
                BetValidationCode::InvalidSelectionType,
                sprintf(
                    'Market %s does not permute, so selection type %s cannot be applied to it.',
                    $marketKey,
                    $selection->selectionType->value,
                ),
                $selection,
                ['market_key' => $marketKey, 'match_mode' => $rule->matchMode()],
                self::STEP_MARKET_RULE,
            );
        }

        // The legacy family-level digit descriptors are still consulted, but a
        // disagreement is now a compatibility observation rather than a refusal: the
        // per-market rule above is authoritative. Phase 4.2 aligned
        // App\Enums\BetType::digits() with it (Tod 3, Run 1), so in the shipped
        // configuration this audit agrees; the check remains so a later configuration
        // edit that reintroduces a divergence is still detected and reported.
        $digitAudit = $this->numbers->digitRuleAudit($marketKey);

        if (($digitAudit['specification_required'] ?? false) === true
            && ($digitAudit['market_digits'] ?? null) === null) {
            // Only refuse when the AUTHORITATIVE source itself is the one missing.
            return BetValidationResult::specificationRequired(
                (string) $digitAudit['note'],
                ['digit rule of market '.$marketKey],
                $selection,
                ['market_key' => $marketKey, 'digit_sources' => $digitAudit['sources']],
                self::STEP_MARKET_RULE,
            );
        }

        // A bottom market is settled from the official bottom two-digit number, which
        // this schema stores in draw_results.metadata rather than in a column. That is
        // a recorded schema limitation, not a blocker for validation, so it is
        // reported and validation continues.
        if ($selection->side->isBottom() || $rule->isBottomMarket()) {
            $mapping = $this->numbers->resultMapping($marketKey);

            if ($mapping['source'] === null) {
                return BetValidationResult::specificationRequired(
                    sprintf('market %s declares no result source, so it cannot be settled', $marketKey),
                    ['result source of market '.$marketKey],
                    $selection,
                    ['market_key' => $marketKey],
                    self::STEP_MARKET_RULE,
                );
            }
        }

        // A fractional rate cannot be stored in bet_items.payout_multiplier, which is
        // an unsigned integer column. Refusing here is deliberate: accepting it would
        // mean a later phase silently truncating a payout rate.
        $multiplier = $selection->multiplier;

        if ($multiplier instanceof PayoutMultiplier && ! $multiplier->fitsBetItemColumn()) {
            return BetValidationResult::specificationRequired(
                sprintf(
                    'market %s resolves to multiplier %s, which is fractional, but '
                    .'bet_items.payout_multiplier is an unsigned integer column and cannot store it '
                    .'without truncation (schema limitation)',
                    $marketKey,
                    $multiplier->value(),
                ),
                ['storage of a fractional multiplier for market '.$marketKey],
                $selection,
                ['market_key' => $marketKey, 'multiplier' => $multiplier->value()],
                self::STEP_MARKET_RULE,
            );
        }

        return null;
    }

    /**
     * Step 11: consult Phase 3.1, read-only.
     *
     * requireReservable is false. Phase 3.1 owns reservation, and this call is
     * explicitly NOT a reservation boundary: no NumberLimit exposure is modified,
     * nothing is locked and nothing is held. The engine's verdict is carried through
     * verbatim so the risk vocabulary stays single-sourced.
     */
    private function consultRisk(
        BetSelectionData $selection,
        BetCalculationResult $calculation,
        string $marketKey,
        BetType $betType,
        PayoutMultiplier $multiplier,
    ): BetValidationResult {
        $number = $selection->number;

        if (! $number instanceof LotteryNumber) {
            return BetValidationResult::rejected(
                BetValidationCode::ValidationFailed,
                'The selection reached risk consultation without a canonical number, which should be '
                .'impossible; refusing rather than consulting the engine with an unvalidated number.',
                $selection,
                ['market_key' => $marketKey],
                self::STEP_RISK,
            );
        }

        try {
            $verdict = $this->risk->assessNumber(
                $selection->drawId,
                $betType,
                $number->value(),
                $selection->stake->amount(),
                $multiplier->fitsBetItemColumn() ? $multiplier->toBetItemColumn() : $multiplier->value(),
                $marketKey,
                false,
            );
        } catch (RiskException $exception) {
            return BetValidationResult::rejected(
                BetValidationCode::RiskRejected,
                $exception->getMessage(),
                $selection,
                $exception->context() + ['market_key' => $marketKey],
                self::STEP_RISK,
            );
        } catch (Throwable $exception) {
            // A failure inside the risk engine must never read as an acceptance.
            return BetValidationResult::rejected(
                BetValidationCode::ValidationFailed,
                sprintf(
                    'The risk engine could not be consulted (%s: %s), so this selection cannot be '
                    .'accepted.',
                    $exception::class,
                    $exception->getMessage(),
                ),
                $selection,
                ['market_key' => $marketKey],
                self::STEP_RISK,
            );
        }

        if (($verdict['allowed'] ?? false) !== true) {
            return BetValidationResult::riskRejected($verdict, $selection, [
                'market_key' => $marketKey,
                'risk_mode' => 'read-only inspection; no exposure was reserved',
            ]);
        }

        return BetValidationResult::accepted($selection, $calculation, [
            'market_key' => $marketKey,
            'market_rule' => $this->rules->tryResolve($marketKey)?->describe(),
            'risk_consulted' => true,
            'risk_mode' => 'read-only inspection; no exposure was reserved',
            'note' => 'Acceptance describes this moment only. The purchasing phase must re-validate '
                .'and reserve under its own lock.',
        ], $verdict);
    }
}
