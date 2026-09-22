<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The maker/checker approval lifecycle of a payout-level money disbursement.
 *
 * WHERE THIS SITS
 * ---------------
 * PayoutStatus describes the payout row's own mechanical state (created →
 * processing → completed | failed | reversed). The APPROVAL lifecycle here is
 * the human-control lane on top of it: BEFORE the payout service is allowed
 * to complete a sensitive payout (large prize claim, manual review, batch
 * release), a maker submits a REQUEST and an independent checker approves or
 * rejects it:
 *
 *   Pending ──▶ Approved ──▶ Completed
 *      │            │
 *      └────────▶ Rejected  (terminal)
 *
 * Pending:   maker has submitted, checker has not acted yet.
 * Approved:  checker agreed; the payment service may now complete.
 * Rejected:  checker refused; the request is dead — the payout never pays.
 * Completed: the approved request made it all the way to paid money.
 *
 * INVARIANTS
 * ----------
 * - Rejected is terminal; nothing (and no subsequent approval) undeads it.
 * - An Approved request whose payout later reverses stays Approved — the
 *   money lane is PayoutStatus's territory, not this lane's.
 * - Completed is reached ONLY from the payout completing, never edited.
 */
enum PayoutApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Checker Decision',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Completed => 'Completed',
        };
    }

    /**
     * States a checker may still act on; nothing starts a decision from
     * Rejected or Completed.
     */
    public function isActionable(): bool
    {
        return $this === self::Pending;
    }

    /**
     * The states that forever pin the request (no transition ever leaves).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Completed => true,
            self::Pending, self::Approved => false,
        };
    }

    /**
     * Transitions the request may lawfully make out of this state.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Completed],
            self::Rejected, self::Completed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Badge color for admin surfaces, in Filament vocabulary.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Approved => 'green',
            self::Rejected => 'danger',
            self::Completed => 'success',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
