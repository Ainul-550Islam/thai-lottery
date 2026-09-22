<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a FIXED lottery-ticket PRODUCT — the printed/physical
 * six-digit ticket line the operator manufactures against a draw date
 * before any retail placement, GLO-parity.
 *
 * WHAT ONE STATUS DESCRIBES
 * -------------------------
 * A product is not a player's ticket: it is the LINE ITEM of the
 * offering — "six-digit series, denomination ฿80, Thai Gov draw of
 * 2026-10-01, 40,000 units". The lifecycle answers: may this line still
 * be offered, did the draw it belongs to pass, is its unsold inventory
 * recoverable.
 *
 * STATE MACHINE
 * -------------
 *
 *   ┌───────┐ ops activates ┌────────┐ incident/pause ┌───────────┐
 *   │ Draft │ ────────────▶ │ Active │ ─────────────▶ │ Suspended │
 *   └───┬───┘               └───┬────┘ ◀───────────── └─────┬─────┘
 *       │  Withdrawn            │  draw settles/sells out   │ resume
 *       ▼                       ▼                           │
 *   ┌───────────┐           ┌────────┐ ◀─────────────────────┘
 *   │ Withdrawn │           │ Closed │
 *   └───────────┘           └────────┘
 *
 *   Draft     — defined, never sellable. Still editable in full; no
 *               inventory movement may occur against it. May be Withdrawn.
 *   Active    — issued and sellable; inventory may allocate and tickets
 *               may retail against it.
 *   Suspended — temporarily un-sellable (print discrepancy, recall,
 *               investigation). Existing sold tickets stay lawful — a
 *               suspension never voids ticket holders' rights. May resume
 *               to Active exactly once per pause cycle (re-suspend is the
 *               same edge taken again).
 *   Closed    — terminally finished: the parent draw settled and the lane
 *               was reconciled, or the line sold out. Unsold inventory
 *               marked un-usable upstream (AllocateTicketInventoryJob never
 *               selects Closed). Terminal.
 *   Withdrawn — Draft discarded pre-offering (typo'd denomination, wrong
 *               draw link). Terminal, and because it never retailed, no
 *               ticket-holder consequence exists.
 *
 * NUMBERS THAT NEVER CHANGE
 *   denomination, code and draw association are immutable once Active —
 *   repricing an offered line would retro-edit what buyers were shown.
 */
enum TicketProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /**
     * May inventory retail against this product right now?
     */
    public function isSellable(): bool
    {
        return $this === self::Active;
    }

    public function isActionable(): bool
    {
        return match ($this) {
            self::Draft, self::Active, self::Suspended => true,
            self::Closed, self::Withdrawn => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Closed, self::Withdrawn => true,
            self::Draft, self::Active, self::Suspended => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Withdrawn],
            self::Active => [self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::Closed],
            self::Closed, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'green',
            self::Suspended => 'orange',
            self::Closed => 'blue',
            self::Withdrawn => 'red',
        };
    }
}
