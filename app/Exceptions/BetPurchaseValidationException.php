<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\DTOs\BetValidationResult;
use App\Enums\BetValidationCode;

/**
 * A purchase refused before any mutation, because the request itself is not valid.
 *
 * WHY THIS IS SEPARATE FROM BetPurchaseException
 * A validation refusal is the one purchase failure that is the CLIENT's to fix:
 * wrong digit count, unknown market, closed draw, stake below the configured
 * minimum. Separating it lets a caller distinguish "the player must change the
 * request" from "the system refused for reasons the player cannot influence"
 * (insufficient balance, exhausted number limit, lock contention) without parsing
 * message strings.
 *
 * WHY IT CARRIES THE PHASE 4.2 RESULT VERBATIM
 * Phase 4.2's App\Services\Betting\BetValidationService already owns every market
 * rule, digit rule, stake bound and draw-state rule, and it reports its verdict as
 * an App\DTOs\BetValidationResult carrying a stable App\Enums\BetValidationCode and
 * the numbered step that failed. Re-deriving any of that here would create a second
 * source of truth for market rules, which this phase is explicitly forbidden to do.
 * The result is therefore attached unchanged, so the caller reads the ORIGINAL code
 * and the ORIGINAL failed step.
 *
 * MUTATION GUARANTEE
 * This exception is only ever thrown before the purchase transaction is opened.
 * Validation runs entirely outside the transaction and touches no wallet, no
 * ledger, no bet, no ticket and no number limit, so when a caller sees this
 * exception the database is provably untouched by the attempt. That is what makes
 * test case AG ("no mutation on validation failure") structurally true rather than
 * merely observed.
 */
final class BetPurchaseValidationException extends BetPurchaseException
{
    /**
     * The verbatim Phase 4.2 verdict, when this refusal came from that service.
     *
     * Deliberately not readonly and not promoted: the parent constructor is shared
     * with every other purchase exception and must not grow a betting-specific
     * parameter, so the result is attached by the named constructors below.
     */
    private ?BetValidationResult $validation = null;

    /**
     * Wrap a rejected Phase 4.2 validation result.
     *
     * The message, the stable code and the failed step number all come from the
     * result. Nothing is reworded, so a log line from the purchase path is
     * comparable with a log line from a plain validation call.
     */
    public static function fromValidation(BetValidationResult $result): self
    {
        $exception = new self(
            $result->reason,
            'bet_purchase_validation_failed',
            [
                'validation_code' => $result->codeValue(),
                'failed_step' => $result->failedStep,
                'acceptance' => $result->acceptance->value,
                'market' => $result->selection?->marketKey,
                'number' => $result->selection?->number?->value(),
                'stake' => $result->selection?->stake->amount(),
                'risk_decision' => $result->riskDecision(),
                'specification_gaps' => $result->specificationGaps === []
                    ? null
                    : implode(', ', $result->specificationGaps),
            ],
        );

        $exception->validation = $result;

        return $exception;
    }

    /**
     * A refusal raised by the purchase layer itself, for a condition Phase 4.2 does
     * not judge - an unknown market key shape, a missing user, an unusable
     * idempotency key format.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function refused(BetValidationCode $code, string $reason, array $context = []): self
    {
        return new self(
            $reason,
            'bet_purchase_validation_failed',
            array_merge(['validation_code' => $code->value], $context),
        );
    }

    /**
     * The request named a market this project does not declare.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function unknownMarket(string $marketKey, array $supportedKeys, array $context = []): self
    {
        return self::refused(
            BetValidationCode::InvalidMarket,
            sprintf(
                'Market "%s" is not one of the markets this project declares. Supported markets: %s.',
                $marketKey,
                implode(', ', array_map('strval', $supportedKeys)),
            ),
            array_merge(['market' => $marketKey], $context),
        );
    }

    /**
     * The Phase 4.2 verdict, when this refusal originated there.
     */
    public function validation(): ?BetValidationResult
    {
        return $this->validation;
    }

    /**
     * The stable validation code this refusal maps onto.
     *
     * The return type matches BetDomainException exactly - a BetValidationCode, never
     * null - so App\Services\Betting\BetValidationService can keep turning a caught
     * exception into the same code it would have produced without one. Narrowing it to a
     * nullable string here would break that contract for every caller in Phase 4.2.
     */
    public function validationCode(): BetValidationCode
    {
        $code = BetValidationCode::tryFrom((string) ($this->context()['validation_code'] ?? ''));

        if ($code instanceof BetValidationCode) {
            return $code;
        }

        return parent::validationCode();
    }

    /**
     * The numbered Phase 4.2 pipeline step that refused the request, or 0 when the
     * refusal did not come from that pipeline.
     */
    public function failedStep(): int
    {
        $step = $this->context()['failed_step'] ?? 0;

        return is_int($step) ? $step : 0;
    }

    /**
     * True when the refusal is a missing project rule rather than a bad request.
     *
     * A specification gap must never be reported to a player as "your bet is
     * invalid": the bet may be perfectly legal and the project simply has not
     * declared the rule.
     */
    public function isSpecificationGap(): bool
    {
        return $this->validation?->isSpecificationGap() === true;
    }
}
