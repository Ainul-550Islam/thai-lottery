<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an UNCLAIMED PRIZE — a payout obligation whose claim window
 * has lapsed and whose money the system must now dispose of by rule, not by
 * forgetfulness.
 *
 * WHAT ONE STATUS DESCRIBES
 * -------------------------
 * When a claim window closes without payment, the prize is not "gone": it
 * enters a disposal lane. Pending → Expired names the lapse. Expired →
 * Swept names the moment the lapse was enrolled into the unclaimed ledger;
 * Closed names the final disposition recorded (forfeited to house rules /
 * reserve ledgers per configuration). Every step is loud: an audit row,
 * never a silent delete.
 *
 * STATE MACHINE
 * -------------
 *
 *   ┌──────────┐ window lapses ┌─────────┐  sweep enrolls  ┌─────────────┐
 *   │ Pending  │ ─────────────▶ │ Expired │ ──────────────▶ │   Swept     │
 *   └────┬─────┘                └─────────┘                 └──────┬──────┘
 *        │ reclaim still possible (rare ops edge)                   │ disposition
 *        │ (only with written ops reason)                          │ recorded
 *        ▼                                                          ▼
 *   ┌──────────┐                                             ┌─────────────┐
 *   │ Reclaimed│                                             │   Closed    │
 *   └──────────┘                                             └─────────────┘
 *
 *   Pending   — within its claim window still; the lane exists but nothing
 *               has lapsed. (The claim window service may already report
 *               Open on the same payout; this lane is the DISPOSAL view.)
 *   Expired   — the window closed with no payment. The prize is lapsed but
 *               not yet enrolled into disposal accounting. Sweep sets in
 *               next.
 *   Swept     — the daily sweep enrolled the lapse: amount and identity
 *               stamped into the unclaimed ledger projection. Swept awaits
 *               the final disposition (house forfeiture, reserve transfer).
 *   Reclaimed — exceptional ops recovery of a lapsed-but-not-disposed
 *               prize back to the winner, with a mandatory written reason.
 *               Terminal on this lane.
 *   Closed    — the final disposition is recorded; the ladder is finished.
 *               Terminal.
 *
 * IDEMPOTENCY CONTRACT
 *   Expired → Swept is the step the sweeper job performs once per payout.
 *   A second sweep matches nothing — the selector is the status itself.
 */
enum UnclaimedPrizeStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Swept = 'swept';
    case Reclaimed = 'reclaimed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Expired => 'Expired',
            self::Swept => 'Swept',
            self::Reclaimed => 'Reclaimed',
            self::Closed => 'Closed',
        };
    }

    /**
     * The sweeper selects exactly this status.
     */
    public function isSweepable(): bool
    {
        return $this === self::Expired;
    }

    public function isActionable(): bool
    {
        return match ($this) {
            self::Pending, self::Expired => true,
            self::Swept => true, // awaits disposition → Closed
            self::Reclaimed, self::Closed => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Reclaimed, self::Closed => true,
            self::Pending, self::Expired, self::Swept => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Expired, self::Reclaimed],
            self::Expired => [self::Swept, self::Reclaimed],
            self::Swept => [self::Closed],
            self::Reclaimed, self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Expired => 'orange',
            self::Swept => 'blue',
            self::Reclaimed => 'green',
            self::Closed => 'gray',
        };
    }
}
