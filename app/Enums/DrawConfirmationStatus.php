<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Four-eyes confirmation state of an ingested draw result.
 *
 * A draw result arrives through App\Services\Draw\DrawResultIngestionService
 * (official GLO feed, operator paste, or correction) INTO PENDING. It may then
 * be CONFIRMED by an operator holding the 'confirm draw results' permission —
 * the confirmation is the human attestation that the ingested numbers match
 * the official announcement — and a confirmed result is what
 * App\Services\Draw\DrawResultPublicationService is allowed to publish
 * downstream. REJECTED marks the row as reviewed-and-wrong so a fresh
 * ingestion is required; SUPERSEDED is written onto a previously CONFIRMED
 * row when a correction replaces it, so the audit trail keeps the history of
 * which result was believed true at which moment.
 *
 * Only CONFIRMED rows may be published. PENDING rows are invisible to
 * settlement. This enum is the gate that makes "a result cannot be published
 * by one unreviewed pair of hands" enforceable in code.
 */
enum DrawConfirmationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Confirmation',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Superseded => 'Superseded',
        };
    }

    /**
     * Whether this state is terminal for the row (Rejected and Superseded
     * rows are history; Confirmed rows are history-ready but still govern the
     * draw until superseded).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Superseded => true,
            self::Pending, self::Confirmed => false,
        };
    }

    /**
     * Whether a result row in this state may be published to players and used
     * by settlement. Only Confirmed passes — never Pending, never history.
     */
    public function canPublish(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * The legal direct transitions out of this state.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Rejected],
            self::Confirmed => [self::Superseded],
            self::Rejected => [],
            self::Superseded => [],
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
            self::Confirmed => 'green',
            self::Rejected => 'danger',
            self::Superseded => 'gray',
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
