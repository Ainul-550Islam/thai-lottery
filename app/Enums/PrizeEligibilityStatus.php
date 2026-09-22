<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Claimant/ticket eligibility decision lifecycle.
 *
 *   Pending ──▶ Eligible
 *      │           │
 *      │           └──▶ Ineligible   (state moved after the fact)
 *      └──▶ Ineligible
 *      └──▶ Blocked
 *
 * SEMANTICS
 * - Pending: the snapshot exists; the gates haven't spoken yet.
 * - Eligible: ALL of ownership + KYC + claim window + no self-exclusion
 *   + valid ticket state passed at snapshot time.
 * - Ineligible: a soft gate failed (window closed, KYC insufficient in
 *   THIS decision context, ticket in a state that can't claim).
 * - Blocked: a hard gate fired — self-excluded claimant or blocked
 *   principal. BLOCKED IS STICKIER THAN INELIGIBLE: the row's journey
 *   back never passes through Pending quietly.
 *
 * TERMINAL: none (decisions rotate as authoritative state moves — the
 * snapshot re-evaluates; every rotation is pronounced via the service,
 * never rewritten underneath).
 */
enum PrizeEligibilityStatus: string
{
    case Pending = 'pending';
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
    case Blocked = 'blocked';

    /**
     * @return array<int, PrizeEligibilityStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Eligible, self::Ineligible, self::Blocked],
            self::Eligible => [self::Ineligible, self::Blocked],
            self::Ineligible => [self::Pending, self::Eligible, self::Blocked],
            self::Blocked => [self::Pending, self::Eligible],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * May money conversation proceed on this decision?
     */
    public function admits(): bool
    {
        return $this === self::Eligible;
    }

    /**
     * Is this a HARD-gate reading (blocked claimant)?
     */
    public function isHardGate(): bool
    {
        return $this === self::Blocked;
    }
}
