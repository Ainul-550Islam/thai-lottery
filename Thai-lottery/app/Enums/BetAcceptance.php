<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The binary outcome of pre-bet validation: may this selection proceed.
 *
 * WHAT THIS DOES NOT MEAN
 * Accept means "nothing in the domain layer refuses this selection". It does NOT
 * mean money has moved, capacity has been held, or a bet exists. No wallet is
 * debited, no balance is locked, no ledger entry is posted and no number-limit
 * exposure is reserved anywhere in Phase 4.1. A later phase must still, inside
 * one transaction, reserve risk capacity through the Phase 3.1
 * RiskAssessmentService and debit the wallet through the Phase 2.1 wallet
 * services. An Accept that is never followed by those steps has changed nothing.
 *
 * WHY THIS IS NOT App\Enums\RiskDecision
 * RiskDecision has three states (allow / review / reject) and belongs to the risk
 * engine. Validation is binary, and mapping the risk engine's Review onto Accept
 * or Reject is a decision the validation layer makes explicitly through
 * fromRiskDecision() rather than by sharing an enum.
 */
enum BetAcceptance: string
{
    case Accept = 'accept';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'Accepted',
            self::Reject => 'Rejected',
        };
    }

    public function isAccepted(): bool
    {
        return $this === self::Accept;
    }

    public function isRejected(): bool
    {
        return $this === self::Reject;
    }

    /**
     * Map a Phase 3.1 risk decision onto an acceptance.
     *
     * Review is mapped to Reject, not to Accept. A selection the risk engine
     * wants a human to look at must not proceed automatically; the caller can
     * still read the underlying verdict and route it to an override flow, which
     * config('risk.override') already provides for.
     */
    public static function fromRiskDecision(RiskDecision $decision): self
    {
        return $decision === RiskDecision::Allow ? self::Accept : self::Reject;
    }

    /**
     * The most restrictive acceptance across a set.
     *
     * One rejected selection rejects the whole request. An empty set is Reject,
     * because nothing was validated and so nothing was approved.
     *
     * @param  iterable<self>  $acceptances
     */
    public static function mostRestrictive(iterable $acceptances): self
    {
        $seen = false;

        foreach ($acceptances as $acceptance) {
            $seen = true;

            if ($acceptance->isRejected()) {
                return self::Reject;
            }
        }

        return $seen ? self::Accept : self::Reject;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
