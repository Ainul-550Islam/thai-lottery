<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a prize TAX calculation.
 *
 * WHAT ONE STATUS DESCRIBES
 * -------------------------
 * Where ONE tax computation over ONE prize stands between being queued and
 * being settled into the payout: whether the arithmetic has run, whether the
 * answer was recorded against the payout, or whether the whole lane was
 * waived.
 *
 * STATE MACHINE
 * -------------
 *
 *              ┌─────────┐   computed     ┌────────────┐  applied   ┌─────────┐
 *              │ Pending │ ─────────────▶ │ Calculated │ ─────────▶ │ Applied │
 *              └────┬────┘                └─────┬──────┘            └─────────┘
 *                   │                           │        waived    ┌─────────┐
 *                   └───────────────────────────┼────────────▶──── │ Waived  │
 *                   │  no liability             │                  └─────────┘
 *                   │                           │ calc error ┌────────┐
 *                   └───────────────────────────┴──────────▶ │ Failed │
 *                                                            └────────┘
 *
 *   Pending    — the prize qualifies for a tax computation and it is queued;
 *                no arithmetic has run yet
 *   Calculated — the arithmetic ran; the exact-tax answer is stamped against
 *                the calculation key, but the payout money has NOT yet been
 *                adjusted. Between Calculated and Applied a checker/payout
 *                lane may still refuse; the state exists so refusal never
 *                re-derives arithmetic from scratch
 *   Applied    — the computed tax was applied to the payout; the amount the
 *                player sees demonstrates it. Applied is terminal
 *   Waived     — the lane completed with NO tax (below threshold, exempt
 *                currency/method combination, or documented operations
 *                waiver). Waived is terminal. Waived is NOT "ignored": it is
 *                a positive, audited answer of zero under a stated rule
 *   Failed     — the computation could not complete (basis-mismatch,
 *                missing rate configuration, payout drift between queue and
 *                run). Failed is RE-RUNNABLE: lane does not advance, queue
 *                may make another attempt; a Failed that keeps failing must
 *                alert, not silently pile
 *
 * IDEMPOTENCY CONTRACT
 *   Pending -> Calculated -> Applied happens at most once per calculation
 *   key. A Failed is the only re-entry point and the only non-linear edge.
 */
enum TaxCalculationStatus: string
{
    case Pending = 'pending';
    case Calculated = 'calculated';
    case Applied = 'applied';
    case Waived = 'waived';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Calculated => 'Calculated',
            self::Applied => 'Applied',
            self::Waived => 'Waived',
            self::Failed => 'Failed',
        };
    }

    /**
     * Is this status one in which the lane still expects forward movement?
     * Failed counts as actionable: the queue retries Failed lanes.
     */
    public function isActionable(): bool
    {
        return match ($this) {
            self::Pending, self::Failed => true,
            self::Calculated => true, // awaits apply-or-waive resolution
            self::Applied, self::Waived => false,
        };
    }

    /**
     * Terminal statuses can never move again.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Applied, self::Waived => true,
            self::Pending, self::Calculated, self::Failed => false,
        };
    }

    /**
     * Every target this status may advance to, in no particular order.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Calculated, self::Waived, self::Failed],
            self::Calculated => [self::Applied, self::Waived, self::Failed],
            self::Failed => [self::Calculated, self::Waived], // re-run may succeed or waive
            self::Applied, self::Waived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * UI badge color shared with the other enum faces in this codebase.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Calculated => 'blue',
            self::Applied => 'green',
            self::Waived => 'gray',
            self::Failed => 'red',
        };
    }
}
