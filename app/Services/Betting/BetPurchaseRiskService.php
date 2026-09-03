<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseContext;
use App\Enums\BetType;
use App\Enums\RiskDecision;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\HotNumberException;
use App\Exceptions\NumberLimitExceededException;
use App\Exceptions\RiskConfigurationException;
use App\Exceptions\RiskException;
use App\Services\Finance\Money;
use App\Services\Risk\NumberLimitEngine;
use App\Services\Risk\RiskDecisionService;
use Illuminate\Support\Facades\DB;

/**
 * The purchase pipeline's only door to the Phase 3.1 risk engine.
 *
 * THIS IS AN ADAPTER, NOT A SECOND RISK ENGINE
 * There is exactly one risk engine in this project and it is Phase 3.1. This class
 * calculates no exposure, compares no ceiling, writes no number_limits column and
 * decides nothing. App\Services\Risk\NumberLimitEngine::reserve() takes the row lock,
 * re-reads the counters under it, does the exact bcmath comparison against the ceiling
 * and writes the counters; App\Services\Risk\RiskDecisionService turns a read-only
 * assessment into a verdict. Both are used unmodified.
 *
 * THERE IS NO BYPASS, AND NONE CAN BE ADDED THROUGH HERE
 * This class exposes no $skipRisk, no $ignoreRisk, no $force, no $forceReserve, no
 * $adminOverride and no equivalent, in any name or spelling. reserve() has no boolean
 * parameter of any kind, so there is no argument a caller could pass to make a purchase
 * skip the engine. The only path from the purchase pipeline to a committed bet runs
 * through reserve(), and reserve() always calls the engine.
 *
 * WHY preview() AND reserve() ARE DIFFERENT METHODS WITH DIFFERENT STANDING
 * preview() wraps NumberLimitEngine::assess(), which takes NO LOCK. Its answer is a
 * snapshot that another transaction can invalidate before the next statement runs. It
 * is used only to refuse an obviously impossible bet early, so that a hopeless request
 * never takes a lock. Phase 3.1 says it plainly: never gate money on assess() alone.
 *
 * reserve() wraps NumberLimitEngine::reserve(), which locks the number_limits row with
 * SELECT ... FOR UPDATE, re-reads the counters from the locked row and refuses inside
 * the lock. That is the authoritative decision, and it is the only one that can grant
 * capacity. This is why two simultaneous bets cannot both consume the last 100 of a
 * 1000 ceiling: the second one blocks on the row lock, then reads the counter the first
 * one wrote.
 *
 * WHY THE RESERVATION IS NOT RELEASED ON FAILURE
 * It never needs to be. reserve() is called inside the purchase transaction, so a later
 * failure rolls the counter increment back with everything else. Calling
 * NumberLimitEngine::release() on the failure path would be a SECOND mutation - it
 * would subtract the same capacity again after the rollback had already discarded it -
 * and would under-count exposure for every number it touched. release() exists in Phase
 * 3.1 for cancelling an ALREADY COMMITTED bet, which is a later phase's concern.
 *
 * LOCK ORDER
 * This is the SECOND lock a purchase takes; the wallet row is always locked first. That
 * order is fixed for every purchase path in this phase, which is what makes a
 * lock-order deadlock between two purchases impossible.
 */
final class BetPurchaseRiskService
{
    public function __construct(
        private readonly NumberLimitEngine $engine,
        private readonly RiskDecisionService $decisions,
    ) {
    }

    /**
     * Read-only exposure assessment. Takes no lock and grants nothing.
     *
     * Errors are captured into the returned array rather than thrown, because a preview
     * must never be able to fail a purchase that the authoritative reservation would
     * have allowed. The decision service already treats an assessment carrying errors,
     * or one with no usable limit, as a rejection - it never reads a missing limit as
     * unlimited.
     *
     * @return array<string, mixed>
     */
    public function preview(
        int $drawId,
        BetType $betType,
        string $canonicalNumber,
        Money $stake,
        string $multiplier,
    ): array {
        try {
            return $this->engine->assess($drawId, $betType, $canonicalNumber, $stake, $multiplier);
        } catch (RiskException $exception) {
            return [
                'draw_id' => $drawId,
                'bet_type' => $betType->value,
                'number' => $canonicalNumber,
                'reservable' => false,
                'fits' => false,
                'blocked' => false,
                'limit_source' => 'none',
                'errors' => [$exception->getMessage()],
            ];
        }
    }

    /**
     * Whether a previewed verdict permits going as far as taking the locks.
     *
     * Deliberately narrow: it answers "is it worth locking?", never "may this bet be
     * sold?". Only reserve() answers the second question.
     *
     * @param  array<string, mixed>  $verdict  a RiskDecisionService verdict
     */
    public function previewAllows(array $verdict): bool
    {
        $decision = RiskDecision::tryFrom(
            is_string($verdict['decision'] ?? null) ? $verdict['decision'] : '',
        );

        // An unrecognised decision is treated as a refusal. Reading an unknown verdict
        // as permission is how a future enum case would silently start allowing bets.
        if ($decision === null) {
            return false;
        }

        return $decision->isAllowed();
    }

    /**
     * The human-readable reason inside a verdict, for reporting.
     *
     * @param  array<string, mixed>  $verdict
     */
    public function reasonFor(array $verdict): string
    {
        $reason = $verdict['reason'] ?? null;

        return is_string($reason) && $reason !== ''
            ? $reason
            : 'The risk engine refused this selection.';
    }

    /**
     * The stable reason code inside a verdict, for reporting.
     *
     * @param  array<string, mixed>  $verdict
     */
    public function reasonCodeFor(array $verdict): ?string
    {
        $code = $verdict['reason_code'] ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * Reserve number-limit capacity for the selection, under the row lock.
     *
     * ONE selection reserves ONCE. A 3D Tod selection on '123' covers six arrangements
     * of those digits, and it still reserves exactly once, against the number the
     * player chose. Reserving six times would consume six times the capacity for a
     * single stake and would exhaust the market's limits six times faster than the
     * money justifies. The arrangement count is recorded as metadata elsewhere and is
     * never used here.
     *
     * @return array<string, mixed> the verbatim Phase 3.1 reservation result
     *
     * @throws BetPurchaseException on any refusal; the original risk exception is
     *                              always preserved as $previous
     */
    public function reserve(BetPurchaseContext $context): array
    {
        $this->assertInsideTransaction();

        try {
            return $this->engine->reserve(
                $context->drawId(),
                $context->betType,
                $context->canonicalNumber(),
                $context->stakeMoney(),
                $context->multiplier->value(),
            );
        } catch (NumberLimitExceededException $exception) {
            throw BetPurchaseException::riskRejected(
                $exception->getMessage(),
                $this->contextOf($context, NumberLimitExceededException::REASON_CODE),
                $exception,
            );
        } catch (HotNumberException $exception) {
            throw BetPurchaseException::riskRejected(
                $exception->getMessage(),
                $this->contextOf($context, HotNumberException::REASON_BLOCKED),
                $exception,
            );
        } catch (RiskConfigurationException $exception) {
            // A missing or unusable number_limits row is a refusal, never an implicit
            // "unlimited". Phase 3.1 requires the row to exist before a number can be
            // sold, and inventing one here would mean selling a number against a
            // ceiling nobody configured.
            throw BetPurchaseException::riskRejected(
                $exception->getMessage(),
                $this->contextOf($context, RiskConfigurationException::REASON_MISSING_LIMIT),
                $exception,
            );
        } catch (RiskException $exception) {
            throw BetPurchaseException::riskRejected(
                $exception->getMessage(),
                $this->contextOf($context, null),
                $exception,
            );
        }
    }

    /**
     * The verdict the decision service derives from a preview.
     *
     * requireReservable is passed as false because a preview cannot reserve; asking it
     * for reservability would make it reject every bet whose ceiling comes from a
     * configured fallback rather than from a row, which is a question only the
     * authoritative reservation should decide.
     *
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    public function decide(array $assessment): array
    {
        return $this->decisions->decide($assessment, false);
    }

    /**
     * Whether the Phase 3.1 engine is configured to run before a bet is accepted.
     *
     * Reported rather than acted on: the purchase always calls reserve(), whatever this
     * returns, because capacity accounting must happen even where the engine's advisory
     * evaluation is disabled.
     */
    public function evaluatesBeforeBet(): bool
    {
        return $this->decisions->isEnabled();
    }

    /**
     * @param  string|null  $reasonCode
     * @return array<string, scalar|null>
     */
    private function contextOf(BetPurchaseContext $context, ?string $reasonCode): array
    {
        return [
            'draw_id' => $context->drawId(),
            'bet_type' => $context->betType->value,
            'market' => $context->marketKey,
            'number' => $context->canonicalNumber(),
            'stake' => $context->stake->amount(),
            'payout_multiplier' => $context->multiplier->value(),
            'potential_payout' => $context->potentialPayout->toString(),
            'risk_reason_code' => $reasonCode,
        ];
    }

    /**
     * @throws BetPurchaseException
     */
    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw BetPurchaseException::outsideTransaction('reserve number-limit capacity');
        }
    }
}
