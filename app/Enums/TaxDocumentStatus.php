<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a TAX DOCUMENT — the receipt/evidence a player and the
 * regulator can hold: the withholding slip a prize tax produced.
 *
 * WHAT ONE STATUS DESCRIBES
 * -------------------------
 * A TaxCalculation is arithmetic. A TaxDocument is the legal PAPER over
 * that arithmetic: the labeled, numbered, retrievable record of how much
 * was kept, for whom, on what basis. The calculation says "89.50 was
 * withheld"; the document is what the winner shows an accountant and what
 * the operator shows an auditor.
 *
 * STATE MACHINE
 * -------------
 *
 *   ┌─────────┐  generated   ┌───────────┐  issued    ┌────────┐
 *   │  Draft  │ ───────────▶ │ Generated │ ─────────▶ │ Issued │
 *   └────┬────┘              └─────┬─────┘            └───┬────┘
 *        │                         │                      │ operator voids
 *        │  cancelled              ▼                      ▼
 *        └────────────────▶  ┌────────────┐        ┌───────────┐
 *                            │ Cancelled  │  ◀──── │ Voided    │
 *                            └────────────┘        └───────────┘
 *
 *   Draft     — the document lane exists for a calculation, nothing was
 *               rendered or numbered yet. A Draft may still be amended or
 *               dropped without leaving a paper trail to explain
 *   Generated — the document body is rendered and stamped with its number,
 *               but it has NOT been exposed to the claimant. Internal
 *               evidence exists; the player cannot cite it yet
 *   Issued    — the document is delivered/exposed to the claimant; it is
 *               legally in the world. Issued never returns: corrections
 *               take the form of a void + a fresh document, not an edit
 *   Cancelled — dropped before issuance (Draft or Generated) without legal
 *               trace obligations; terminal
 *   Voided    — an Issued document withdrawn by the operator with reason;
 *               the number is tombstoned forever and a correcting document
 *               (if any) MUST reference this number. Voided is terminal
 *
 * THE VOID RULE
 *   Cancelled covers documents nobody ever saw. Voided is the ONLY way out
 *   of Issued. No status may ever reach back into Issued.
 */
enum TaxDocumentStatus: string
{
    case Draft = 'draft';
    case Generated = 'generated';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Generated => 'Generated',
            self::Issued => 'Issued',
            self::Cancelled => 'Cancelled',
            self::Voided => 'Voided',
        };
    }

    /**
     * Does this status still accept forward movement?
     */
    public function isActionable(): bool
    {
        return match ($this) {
            self::Draft, self::Generated => true,
            self::Issued => true, // may still be voided
            self::Cancelled, self::Voided => false,
        };
    }

    /**
     * Terminal statuses can never move again.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Voided => true,
            self::Draft, self::Generated, self::Issued => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Generated, self::Cancelled],
            self::Generated => [self::Issued, self::Cancelled],
            self::Issued => [self::Voided], // the void rule: only way out
            self::Cancelled, self::Voided => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Has this document ever been exposed to the claimant?
     */
    public function wasExposed(): bool
    {
        return $this === self::Issued || $this === self::Voided;
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Generated => 'blue',
            self::Issued => 'green',
            self::Cancelled => 'amber',
            self::Voided => 'red',
        };
    }
}
