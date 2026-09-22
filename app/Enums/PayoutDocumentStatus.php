<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a PAYOUT STATEMENT (document) — the winner-facing paper
 * that itemizes one completed payout: gross prize, tax withheld, net paid,
 * with references to the claim/batch that moved it.
 *
 * WHAT ONE STATUS DESCRIBES
 * -------------------------
 * Unlike a tax document (withholding slip for ONE calculation), a payout
 * statement is the FULL ledger-facing account of one payout's money
 * journey. The lifecycle answers: does the paper exist, may the claimant
 * hold it now, and has it been retired into long-term storage.
 *
 * STATE MACHINE
 * -------------
 *
 *   ┌───────┐ statement job ┌───────────┐ claimant surface ┌────────┐
 *   │ Draft │ ────────────▶ │ Generated │ ───────────────▶ │ Issued │
 *   └───┬───┘               └─────┬─────┘                  └───┬────┘
 *       │ regeneration │ correction found                     │ retention
 *       │ (before issue) │ (after issue, correcting doc)      │ window up
 *       ▼               ▼                                      ▼
 *   ┌───────────┐  ┌───────────┐                         ┌──────────┐
 *   │ Cancelled │  │ Cancelled │                         │ Archived │
 *   └───────────┘  └───────────┘                         └──────────┘
 *
 *   Draft     — the statement data was derived but not yet rendered /
 *               numbered. May still be re-derived in place (source rows
 *               may still be settling: tax application, batch counters).
 *   Generated — deterministic document key + number assigned; the body is
 *               frozen and internal. Corrections require regeneration
 *               (back to Draft) or archive-and-reissue.
 *   Issued    — exposed to the claimant. Issued statements are fact: they
 *               may be Annotated by later corrections but never edited.
 *   Archived  — closed into long-term retention after its live-use period
 *               (regulator retention window elapsed online). Reads still
 *               succeed; mutations stop forever. Terminal.
 *   Cancelled — withdrawn pre-issuance only. Never applies to Issued
 *               paper: correcting visible paper is always a NEW statement
 *               referencing the old one, never a mutation.
 *
 * DETERMINISM CONTRACT
 *   Given the same completed payout claim/batch/tax anchors, a statement
 *   regenerates the SAME document key and the same numbers — there is
 *   exactly one lawful statement identity per payout, forever.
 */
enum PayoutDocumentStatus: string
{
    case Draft = 'draft';
    case Generated = 'generated';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Generated => 'Generated',
            self::Issued => 'Issued',
            self::Cancelled => 'Cancelled',
            self::Archived => 'Archived',
        };
    }

    public function isActionable(): bool
    {
        return match ($this) {
            self::Draft, self::Generated => true,
            self::Issued => true, // may still archive
            self::Cancelled, self::Archived => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Archived => true,
            self::Draft, self::Generated, self::Issued => false,
        };
    }

    /**
     * Has the claimant ever been able to see this paper?
     */
    public function wasExposed(): bool
    {
        return $this === self::Issued || $this === self::Archived;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Generated, self::Cancelled],
            self::Generated => [self::Issued, self::Draft, self::Cancelled],
            self::Issued => [self::Archived],
            self::Cancelled, self::Archived => [],
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
            self::Generated => 'blue',
            self::Issued => 'green',
            self::Cancelled => 'red',
            self::Archived => 'slate',
        };
    }
}
