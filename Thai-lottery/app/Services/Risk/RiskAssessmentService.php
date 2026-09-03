<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\BetType;
use App\Enums\ExposureType;
use App\Exceptions\RiskConfigurationException;
use App\Exceptions\RiskException;
use App\Models\Draw;
use App\Services\Finance\Money;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The single entry point a betting flow calls before money moves.
 *
 * This is the facade over the risk domain. It composes the pieces in the right order
 * so a caller does not have to know that heat is derived, that ceilings live on two
 * columns, or that reservation needs a lock:
 *
 *   1. validate the draw is open for betting
 *   2. canonicalise every number (leading zeros preserved)
 *   3. resolve the payout multiplier for the market or bet type
 *   4. project the liability against every ceiling
 *   5. turn the projection into a decision with a stable reason code
 *   6. optionally reserve the capacity atomically, inside the CALLER's transaction
 *
 * NOT A CONTROLLER
 * There is no controller here, no route, no request validation, no HTTP status code
 * and no response. It is a service, called by a controller that a later phase will
 * add.
 *
 * PRE-BET ONLY — WHAT IS DELIBERATELY ABSENT
 * No wallet is read or written. No ledger entry is posted. No Bet, BetItem, Ticket,
 * Payout or FinancialTransaction is created. No draw result is touched. No bet type
 * beyond the ceiling arithmetic is implemented: this class does not know how to match
 * a 3D Tod permutation or a Run digit, only how much liability a selection creates.
 *
 * ASSESSMENT VERSUS RESERVATION
 * assessBet() is read-only and takes no lock; two concurrent callers will both be told
 * they fit. reserveBet() takes the row locks and is the only method whose success means
 * capacity is actually held. A caller that assesses and then reserves must treat the
 * reservation's verdict as final, because the state can change in between; that is why
 * reserveBet() re-evaluates everything inside the lock rather than trusting a prior
 * assessment.
 */
class RiskAssessmentService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly NumberNormalizationService $numbers,
        private readonly NumberLimitResolver $resolver,
        private readonly ExposureCalculator $exposure,
        private readonly MoneyExposureCalculator $money,
        private readonly NumberLimitEngine $engine,
        private readonly RiskDecisionService $decisions,
        private readonly HotNumberService $hotNumbers,
        private readonly NumberLimitLockService $locks,
    ) {
    }

    /**
     * Assess one number. Read-only: no lock, no write, no money.
     *
     * @param  string  $stake  exact decimal stake, e.g. '150.00'
     * @param  string|int|null  $multiplier  overrides the configured multiplier when given
     * @return array<string, mixed> a verdict from RiskDecisionService
     */
    public function assessNumber(
        int $drawId,
        BetType $betType,
        string $rawNumber,
        string $stake,
        string|int|null $multiplier = null,
        ?string $market = null,
        bool $requireReservable = true,
    ): array {
        try {
            $amount = $this->money->money($stake, $this->resolver->currency());
            $resolvedMultiplier = $multiplier ?? $this->multiplierFor($betType, $market);

            $assessment = $this->engine->assess($drawId, $betType, $rawNumber, $amount, $resolvedMultiplier);

            return $this->decisions->decide($assessment, $requireReservable);
        } catch (RiskException $exception) {
            return $this->decisions->fromException($exception, [
                'draw_id' => $drawId,
                'bet_type' => $betType->value,
                'number' => $this->numbers->tryNormalize($rawNumber, $betType) ?? $rawNumber,
            ]);
        }
    }

    /**
     * Assess a whole selection of numbers for one draw and bet type.
     *
     * Every number is assessed independently and then combined into one overall
     * verdict, so a caller learns both the aggregate outcome and exactly which number
     * caused it. Duplicate numbers are NOT collapsed: two selections of the same number
     * are two liabilities.
     *
     * WARNING ABOUT INDEPENDENT ASSESSMENT
     * Two selections of the same number are each assessed against the SAME pre-bet
     * exposure, so each may be told it fits while together they would not. That is
     * inherent to assessment and is resolved by reserveBet(), which accumulates under a
     * lock. reserveBet() is the method to use before taking money.
     *
     * @param  list<array{number: string, stake: string, multiplier?: string|int, market?: string}>  $selections
     * @return array<string, mixed>
     */
    public function assessBet(
        int $drawId,
        BetType $betType,
        array $selections,
        bool $requireReservable = true,
    ): array {
        $drawCheck = $this->checkDraw($drawId);

        if ($drawCheck !== null) {
            return $drawCheck;
        }

        $verdicts = [];

        foreach ($selections as $index => $selection) {
            $verdict = $this->assessNumber(
                $drawId,
                $betType,
                $selection['number'],
                $selection['stake'],
                $selection['multiplier'] ?? null,
                $selection['market'] ?? null,
                $requireReservable,
            );

            // Keyed by index and number so duplicate numbers do not overwrite one
            // another in the per-number detail.
            $verdicts[$index.':'.(is_string($verdict['number'] ?? null) ? $verdict['number'] : $selection['number'])] = $verdict;
        }

        return $this->decisions->combine($verdicts);
    }

    /**
     * Reserve capacity for a whole selection, atomically.
     *
     * MUST be called inside a transaction the caller opened. Every row is locked in
     * ascending primary-key order before any ceiling is evaluated, so concurrent
     * multi-number requests queue instead of deadlocking. On any refusal an exception
     * propagates and the caller's rollback removes every reservation made in the batch,
     * so partial reservation cannot happen.
     *
     * @param  list<array{number: string, stake: string, multiplier?: string|int, market?: string}>  $selections
     * @return array{
     *     reserved: bool,
     *     decision: string,
     *     reason_code: string,
     *     reason: string,
     *     draw_id: int,
     *     bet_type: string,
     *     total_stake: string,
     *     total_potential_payout: string,
     *     reservations: array<string, array<string, mixed>>
     * }
     *
     * @throws RiskException when reservation is refused or the engine cannot evaluate
     */
    public function reserveBet(int $drawId, BetType $betType, array $selections): array
    {
        $this->locks->assertInsideTransaction('reserve risk capacity for a bet');

        $this->assertDrawAcceptsBets($drawId);

        $currency = $this->resolver->currency();
        $entries = [];

        foreach ($selections as $selection) {
            $entries[] = [
                'number' => $this->numbers->normalize($selection['number'], $betType),
                'stake' => $this->money->money($selection['stake'], $currency),
                'multiplier' => $selection['multiplier']
                    ?? $this->multiplierFor($betType, $selection['market'] ?? null),
            ];
        }

        $reservations = $this->engine->reserveMany($drawId, $betType, $entries);

        $totalStake = Money::zero($currency);
        $totalPayout = Money::zero($currency);

        foreach ($reservations as $reservation) {
            $totalStake = $totalStake->plus(
                $this->money->money((string) $reservation['stake'], $currency),
            );
            $totalPayout = $totalPayout->plus(
                $this->money->money((string) $reservation['potential_payout'], $currency),
            );
        }

        return [
            'reserved' => true,
            'decision' => \App\Enums\RiskDecision::Allow->value,
            'reason_code' => RiskDecisionService::REASON_ALLOWED,
            'reason' => 'Capacity was reserved for every number in the selection.',
            'draw_id' => $drawId,
            'bet_type' => $betType->value,
            'total_stake' => $totalStake->amount(),
            'total_potential_payout' => $totalPayout->amount(),
            'reservations' => $reservations,
        ];
    }

    /**
     * Release capacity previously reserved for a selection.
     *
     * MUST be called inside the caller's transaction. Used when a bet that held capacity
     * is cancelled after its reservation was committed.
     *
     * @param  list<array{number: string, stake: string, multiplier?: string|int, market?: string}>  $selections
     * @return array<string, array<string, mixed>>
     *
     * @throws RiskException
     */
    public function releaseBet(int $drawId, BetType $betType, array $selections): array
    {
        $this->locks->assertInsideTransaction('release risk capacity for a bet');

        $currency = $this->resolver->currency();
        $released = [];

        // Lock in a deterministic order first, exactly as the reservation path does.
        $triples = [];

        foreach ($selections as $selection) {
            $triples[] = [
                'draw_id' => $drawId,
                'bet_type' => $betType,
                'number' => $this->numbers->normalize($selection['number'], $betType),
            ];
        }

        $this->locks->lockMany($triples);

        foreach ($selections as $selection) {
            $number = $this->numbers->normalize($selection['number'], $betType);

            $released[$number] = $this->engine->release(
                $drawId,
                $betType,
                $number,
                $this->money->money($selection['stake'], $currency),
                $selection['multiplier'] ?? $this->multiplierFor($betType, $selection['market'] ?? null),
            );
        }

        return $released;
    }

    /**
     * A complete risk picture for one number, for an operator screen.
     *
     * Read-only and safe to call at any time. Combines the resolver's snapshot, the heat
     * evaluation and the reconciliation of the stored counters against the bet tables.
     *
     * @return array<string, mixed>
     *
     * @throws RiskConfigurationException
     */
    public function inspectNumber(int $drawId, BetType $betType, string $rawNumber): array
    {
        $number = $this->numbers->normalize($rawNumber, $betType);
        $snapshot = $this->resolver->describe($drawId, $betType, $number);
        $heat = $this->hotNumbers->evaluate($drawId, $betType, $number);
        $limit = $this->resolver->resolve($drawId, $betType, $number);

        return [
            'snapshot' => $snapshot,
            'heat' => $heat,
            'reconciliation' => $limit === null ? null : $this->exposure->reconcile($limit),
        ];
    }

    /**
     * Draw-wide exposure totals, for the per-draw ceilings in config/risk.php.
     *
     * @return array{
     *     draw_id: int,
     *     stake: string,
     *     potential_payout: string,
     *     stake_ceiling: string|null,
     *     stake_within_ceiling: bool|null
     * }
     *
     * @throws RiskConfigurationException
     */
    public function inspectDraw(int $drawId): array
    {
        $currency = $this->resolver->currency();
        $stake = $this->exposure->drawStakeExposure($drawId);
        $payout = $this->exposure->drawPayoutExposure($drawId);

        $configured = $this->config->get('risk.exposure.max_per_draw');
        $ceiling = is_string($configured) || is_int($configured)
            ? $this->money->money((string) $configured, $currency)
            : null;

        return [
            'draw_id' => $drawId,
            'stake' => $stake->amount(),
            'potential_payout' => $payout->amount(),
            'stake_ceiling' => $ceiling?->amount(),
            'stake_within_ceiling' => $ceiling === null
                ? null
                : $this->money->fitsWithinCeiling($stake, $ceiling),
        ];
    }

    /**
     * The payout multiplier that applies to a bet type, or to a specific market.
     *
     * MULTIPLIER RESOLUTION ORDER
     * 1. config('lottery.markets.<market>.payout_multiplier') when a market is named.
     *    This matters because Run pays differently by market: run_top is 3 and
     *    run_bottom is 4, while the bet type alone reports 12.
     * 2. config('lottery.types.<bet_type>.payout_multiplier') otherwise.
     * App\Enums\BetType::payoutMultiplier() is deliberately NOT used as the source,
     * because config/lottery.php declares 'payouts.multiplier_source' to be
     * 'lottery.markets' so a rate is defined in exactly one place.
     *
     * Returned as a string so it can be multiplied exactly.
     *
     * @throws RiskConfigurationException when no multiplier is configured
     */
    public function multiplierFor(BetType $betType, ?string $market = null): string
    {
        if ($market !== null && trim($market) !== '') {
            $key = sprintf('lottery.markets.%s', trim($market));
            $definition = $this->config->get($key);

            if (! is_array($definition)) {
                throw RiskConfigurationException::missingKey($key);
            }

            $configuredType = $definition['bet_type'] ?? null;

            if (is_string($configuredType) && $configuredType !== $betType->value) {
                throw RiskConfigurationException::invalidKey(
                    $key.'.bet_type',
                    sprintf(
                        'market %s belongs to bet type %s, not %s',
                        trim($market),
                        $configuredType,
                        $betType->value,
                    ),
                );
            }

            return $this->readMultiplier($definition, $key);
        }

        $key = sprintf('lottery.types.%s', $betType->value);
        $definition = $this->config->get($key);

        if (! is_array($definition)) {
            throw RiskConfigurationException::missingKey($key);
        }

        return $this->readMultiplier($definition, $key);
    }

    /**
     * Whether the risk engine is configured to run before a bet.
     */
    public function evaluatesBeforeBet(): bool
    {
        return $this->config->get('risk.evaluate_before_bet') === true;
    }

    /**
     * The exposure types this phase actually enforces ceilings on.
     *
     * @return list<string>
     */
    public function enforcedExposureTypes(): array
    {
        return array_map(
            static fn (ExposureType $type): string => $type->value,
            ExposureType::enforced(),
        );
    }

    /**
     * Read and validate a multiplier out of a configuration block.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws RiskConfigurationException
     */
    private function readMultiplier(array $definition, string $key): string
    {
        $multiplier = $definition['payout_multiplier'] ?? null;

        if ($multiplier === null) {
            throw RiskConfigurationException::missingKey($key.'.payout_multiplier');
        }

        if (! is_int($multiplier) && ! is_string($multiplier)) {
            throw RiskConfigurationException::invalidKey(
                $key.'.payout_multiplier',
                'the multiplier must be an integer or an exact decimal string',
            );
        }

        return $this->money->normaliseMultiplier($multiplier);
    }

    /**
     * Verify the draw exists and is open for betting, returning a verdict when not.
     *
     * @return array<string, mixed>|null null when the draw is fine
     */
    private function checkDraw(int $drawId): ?array
    {
        try {
            $this->assertDrawAcceptsBets($drawId);
        } catch (RiskException $exception) {
            return $this->decisions->fromException($exception, ['draw_id' => $drawId]);
        }

        return null;
    }

    /**
     * Refuse to evaluate risk for a draw that cannot accept bets.
     *
     * The draw's own canAcceptBets() is authoritative; it is derived from
     * App\Enums\DrawStatus and is not re-implemented here.
     *
     * @throws RiskConfigurationException
     */
    private function assertDrawAcceptsBets(int $drawId): void
    {
        if ($this->config->get('lottery.betting.require_open_draw') !== true) {
            return;
        }

        $draw = Draw::query()->whereKey($drawId)->first();

        if (! $draw instanceof Draw) {
            throw RiskConfigurationException::invalidLimit(
                'the draw does not exist, so no number limit can apply',
                ['draw_id' => $drawId],
            );
        }

        if (! $draw->canAcceptBets()) {
            throw RiskConfigurationException::invalidLimit(
                'the draw is not open for betting',
                [
                    'draw_id' => $drawId,
                    'draw_status' => $draw->getAttribute('status') instanceof \App\Enums\DrawStatus
                        ? $draw->getAttribute('status')->value
                        : (string) $draw->getAttribute('status'),
                ],
            );
        }
    }
}
