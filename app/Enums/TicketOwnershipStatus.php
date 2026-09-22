<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The IDENTITY-BINDING state of one issued ticket over ONE owner.
 *
 * WHAT ONE STATUS DESCRIBES
 * -------------------------
 * Who may lawfully present this ticket for prize money right now. In
 * GLO-parity retail the physical bearer is the lawful claimant; this
 * digital currency instead BINDS every issued ticket to a verified owner
 * identity the moment it issues, so stolen/screenshotted ticket numbers
 * present as nothing without the identity behind them.
 *
 * STATE MACHINE
 * -------------
 *
 *   issue ──────▶ ┌────────┐ claim flight in force ┌─────────┐
 *                 │ Active │ ────────────────────▶ │ Locked  │
 *                 └───┬────┘  (document custody)   └────┬────┘
 *                     │                                 │
 *                     │  prize claimed against it       │ custody ends:
 *                     ▼                                 ▼ claim won or released
 *              ┌───────────┐                       ┌──────────┐
 *              │  Claimed  │                       │ (back to)│
 *              └───────────┘                       │  Active  │
 *                                                  └──────────┘
 *                     ▲ Active ticket whose window lapses:
 *                     ┌───────────┐
 *                     │  Expired  │   (ownership tombstone — identity
 *                     └───────────┘    stays on record for audit)
 *
 *   Active   — bound to its owner and usable: may be amended, cancelled (by
 *              draw-cancellation rule) or claimed against. The ONLY state
 *              that may carry value.
 *   Locked   — custody held by the claim flight (the prize claim or an
 *              execution batch cites the ticket). An owner may NOT gift,
 *              re-bind or double-spend a locked ticket; the lock releases
 *              when the flight finishes (won) or folds (released). This is
 *              flight custody, not a status the owner may request.
 *   Claimed  — its prize claim completed; the obligation settled. The
 *              binding stays as audit evidence. Terminal.
 *   Expired  — the claim window lapsed un-claimed; ownership record
 *              tombstones (identity preserved for forensic/audit, unusable
 *              for money). Terminal.
 *
 * FORBIDDEN BY CONSTRUCTION
 *   There is NO "transferred" state. Moving a ticket between owners never
 *   exists as a status destination: a change of lawful ownership is a new
 *   binding operation with its own audit — and the old binding tombstones
 *   behind it — never a silent mutation of this one field. This is read
 *   together with TicketOwnershipService.
 */
enum TicketOwnershipStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
    case Claimed = 'claimed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Locked => 'Locked',
            self::Claimed => 'Claimed',
            self::Expired => 'Expired',
        };
    }

    /**
     * The single state that may carry prize value.
     */
    public function mayCarryValue(): bool
    {
        return $this === self::Active;
    }

    /**
     * Is this binding in flight custody (claim/batch cites it)?
     */
    public function inCustody(): bool
    {
        return $this === self::Locked;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Claimed, self::Expired => true,
            self::Active, self::Locked => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Locked, self::Claimed, self::Expired],
            self::Locked => [self::Active, self::Claimed, self::Expired],
            self::Claimed, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Locked => 'orange',
            self::Claimed => 'blue',
            self::Expired => 'gray',
        };
    }
}
