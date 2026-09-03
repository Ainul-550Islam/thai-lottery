<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BetAcceptance;
use App\Enums\BetValidationCode;
use App\Enums\RiskDecision;

/**
 * The verdict on one selection.
 *
 * WHY A RESULT OBJECT AND NOT A BOOLEAN
 * A refusal has to say which rule refused it and why, in a form a client can act on
 * and an operator can audit. A boolean throws that away. Every failure here carries
 * a stable BetValidationCode, a human-readable reason and structured context.
 *
 * VALIDATION ONLY
 * A result of accepted() is a statement about the request as it was evaluated. It
 * reserves nothing, holds nothing, locks nothing and guarantees nothing about a
 * later moment: a number can fill up, a draw can close and a limit can tighten
 * between validation and purchase. The purchasing phase must re-check under its own
 * lock. Nothing about this object implies a wallet was touched, because none was.
 *
 * RISK VERDICTS ARE CARRIED VERBATIM
 * When the Phase 3.1 risk engine refuses a selection, its own verdict array is
 * stored unmodified in $riskVerdict and the code is BetValidationCode::RiskRejected.
 * The risk reason codes are not re-encoded into this enum: Phase 3.1 owns them, and
 * copying them would create a second vocabulary that could drift from the first.
 *
 * SPECIFICATION GAPS ARE A DISTINCT OUTCOME
 * A selection whose business rule is not declared anywhere in the project is neither
 * valid nor a player error. It returns SpecificationRequired, which is a refusal
 * addressed to the operator, and it names the missing rule instead of guessing one.
 */
final readonly class BetValidationResult
{
    /**
     * The ordinal of the risk consultation step in the validation order defined by
     * App\Services\Betting\BetValidationService. Kept here so a risk refusal can
     * report which step it failed at without this DTO depending on the service.
     */
    public const STEP_RISK = 11;

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $riskVerdict  the Phase 3.1 verdict, verbatim
     * @param  list<string>  $specificationGaps  named undeclared rules
     */
    public function __construct(
        public BetAcceptance $acceptance,
        public BetValidationCode $code,
        public string $reason,
        public ?BetSelectionData $selection = null,
        public ?BetCalculationResult $calculation = null,
        public array $context = [],
        public ?array $riskVerdict = null,
        public array $specificationGaps = [],
        public int $failedStep = 0,
    ) {
    }

    /**
     * An accepted selection.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $riskVerdict
     */
    public static function accepted(
        BetSelectionData $selection,
        ?BetCalculationResult $calculation = null,
        array $context = [],
        ?array $riskVerdict = null,
    ): self {
        return new self(
            BetAcceptance::Accept,
            BetValidationCode::Valid,
            'The selection satisfies every rule that could be evaluated. This is a validation result '
            .'only: nothing has been reserved, held or debited.',
            $selection,
            $calculation,
            $context,
            $riskVerdict,
        );
    }

    /**
     * A refused selection.
     *
     * @param  array<string, mixed>  $context
     */
    public static function rejected(
        BetValidationCode $code,
        string $reason,
        ?BetSelectionData $selection = null,
        array $context = [],
        int $failedStep = 0,
    ): self {
        return new self(
            BetAcceptance::Reject,
            $code,
            $reason,
            $selection,
            null,
            $context,
            null,
            [],
            $failedStep,
        );
    }

    /**
     * A selection refused by the Phase 3.1 risk engine.
     *
     * @param  array<string, mixed>  $riskVerdict  the engine's verdict, unmodified
     * @param  array<string, mixed>  $context
     */
    public static function riskRejected(
        array $riskVerdict,
        ?BetSelectionData $selection = null,
        array $context = [],
    ): self {
        $reason = isset($riskVerdict['reason']) && is_string($riskVerdict['reason'])
            ? $riskVerdict['reason']
            : 'The risk engine refused this selection.';

        return new self(
            BetAcceptance::Reject,
            BetValidationCode::RiskRejected,
            $reason,
            $selection,
            null,
            $context,
            $riskVerdict,
            [],
            self::STEP_RISK,
        );
    }

    /**
     * A selection that cannot be judged because a business rule is not declared.
     *
     * @param  list<string>  $gaps
     * @param  array<string, mixed>  $context
     */
    public static function specificationRequired(
        string $subject,
        array $gaps = [],
        ?BetSelectionData $selection = null,
        array $context = [],
        int $failedStep = 0,
    ): self {
        return new self(
            BetAcceptance::Reject,
            BetValidationCode::SpecificationRequired,
            sprintf(
                'SPECIFICATION REQUIRED: %s. No rule was assumed and no value was invented; this '
                .'selection cannot be validated until the rule is declared.',
                $subject,
            ),
            $selection,
            null,
            $context,
            null,
            $gaps === [] ? [$subject] : $gaps,
            $failedStep,
        );
    }

    public function isAccepted(): bool
    {
        return $this->acceptance->isAccepted();
    }

    public function isRejected(): bool
    {
        return $this->acceptance->isRejected();
    }

    /**
     * Was this refusal caused by an undeclared business rule rather than by bad
     * input.
     */
    public function isSpecificationGap(): bool
    {
        return $this->code->isSpecificationGap();
    }

    /**
     * Did the refusal come from the risk engine.
     */
    public function isRiskRejected(): bool
    {
        return $this->code->isRiskOriginated();
    }

    /**
     * Can the player fix this themselves by changing their input.
     */
    public function isPlayerCorrectable(): bool
    {
        return $this->code->isPlayerCorrectable();
    }

    /**
     * The stable machine code, for a client to branch on.
     */
    public function codeValue(): string
    {
        return $this->code->value;
    }

    /**
     * The risk decision string as the engine reported it, when there was one.
     */
    public function riskDecision(): ?string
    {
        $decision = $this->riskVerdict['decision'] ?? null;

        if (is_string($decision)) {
            return $decision;
        }

        // Phase 3.1 may return the RiskDecision enum instance rather than its
        // backing value; read it without assuming either shape.
        if ($decision instanceof RiskDecision) {
            return $decision->value;
        }

        return null;
    }

    /**
     * The risk reason code as the engine reported it, when there was one.
     */
    public function riskReasonCode(): ?string
    {
        $code = $this->riskVerdict['reason_code'] ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * A single value from the context.
     */
    public function contextValue(string $key): mixed
    {
        return $this->context[$key] ?? null;
    }

    /**
     * The most restrictive result across a set, for validating a whole ticket.
     *
     * The first refusal is returned so the caller sees the specific rule that
     * failed. An empty set is a refusal, not an acceptance: validating nothing
     * proves nothing.
     *
     * @param  iterable<self>  $results
     */
    public static function mostRestrictive(iterable $results): self
    {
        $accepted = null;

        foreach ($results as $result) {
            if ($result->isRejected()) {
                return $result;
            }

            $accepted ??= $result;
        }

        return $accepted ?? self::rejected(
            BetValidationCode::ValidationFailed,
            'No selections were supplied, so nothing could be validated.',
        );
    }

    /**
     * Log-safe representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'acceptance' => $this->acceptance->value,
            'code' => $this->code->value,
            'reason' => $this->reason,
            'failed_step' => $this->failedStep,
            'selection' => $this->selection?->toArray(),
            'calculation' => $this->calculation?->toArray(),
            'context' => $this->context,
            'risk_decision' => $this->riskDecision(),
            'risk_reason_code' => $this->riskReasonCode(),
            'specification_gaps' => $this->specificationGaps,
        ];
    }
}
