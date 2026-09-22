<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The result-level lifecycle of an official draw result entry.
 *
 * THE DISTINCTION FROM DrawLifecycleState and DrawConfirmationStatus
 * ------------------------------------------------------------------
 * DrawLifecycleState describes the DRAW row (open, closed, result_published,
 * settled...). DrawConfirmationStatus describes the four-eyes review of ONE
 * ingested result record (pending/confirmed/rejected/superseded). This enum
 * adds the RESULT-ENTRY vocabulary a reporter or export asks with: what is
 * the lifecycle of the official result ITSELF?
 *
 *   Draft ──▶ Pending ──▶ Confirmed ──▶ Published
 *      │         │            │
 *      └─────────┴────────────┴──▶ Cancelled
 *
 * DRAFT:     entered but not yet asserted (operator draft, unverified feed).
 * PENDING:   asserted but not yet reviewed (the ingestion-state version).
 * CONFIRMED: reviewed by a second hand and attested true.
 * PUBLISHED: visible to players and settlement; the only money-touching one.
 * CANCELLED: the whole result entry is dead (draw cancelled or correction
 *            superswing) — terminal.
 *
 * MAPPING
 * -------
 * toConfirmationStatus() collapses the result-entry state into the
 * confirmation-lane status where one exists (Draft and Pending map to the
 * confirmation Pending; Published maps to Confirmed plus the publication
 * stamp lives on the draw row). It is partial-on-purpose: there is no lawful
 * DrawResultStatus for Superseded — a superseded result is merely absent,
 * not a live lifecycle.
 */
enum DrawResultStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Published = 'published';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending Confirmation',
            self::Confirmed => 'Confirmed',
            self::Published => 'Published',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the result may still be reached from this state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Published, self::Cancelled => true,
            self::Draft, self::Pending, self::Confirmed => false,
        };
    }

    /**
     * Whether money may be settled against a result in this state.
     * ONLY Published pays.
     */
    public function canSettle(): bool
    {
        return $this === self::Published;
    }

    /**
     * Transitions the result entry may lawfully make out of this state.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending, self::Cancelled],
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Published, self::Cancelled],
            self::Published, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * The confirmation-lane status this result-entry state implies, or null
     * where no confirmation row is in play (Draft—never reviewed; Cancelled—
     * beyond review).
     */
    public function toConfirmationStatus(): ?DrawConfirmationStatus
    {
        return match ($this) {
            self::Pending, self::Draft => DrawConfirmationStatus::Pending,
            self::Confirmed, self::Published => DrawConfirmationStatus::Confirmed,
            self::Cancelled => null,
        };
    }

    /**
     * Badge color for admin surfaces, in Filament vocabulary.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'yellow',
            self::Confirmed => 'blue',
            self::Published => 'green',
            self::Cancelled => 'danger',
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
