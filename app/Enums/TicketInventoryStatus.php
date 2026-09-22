<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ticket inventory lifecycle.
 *
 * GLO physical reality, stated simply: a printed/serialized unit of ticket
 * paper moves through exactly one of the lanes below. The transition table
 * is total — every legal step is stated, and every unstated step is
 * impossible through the service lanes that the inventory services own.
 *
 *   Available  → Reserved, Voided, Expired
 *   Reserved   → Sold, Available (release), Expired
 *   Sold       → Voided (never back to any online state)
 *   Voided     → nothing (terminal: the paper is dead paper)
 *   Expired    → nothing (terminal: unsold past its draw window)
 *
 * Terminal states exist for audit's reasons that traces never stop existing;
 * the unit's identity (serial) survives forever even when the paper has no
 * commercial meaning anymore.
 */
enum TicketInventoryStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Voided = 'voided';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::Sold => 'Sold',
            self::Voided => 'Voided',
            self::Expired => 'Expired',
        };
    }

    /**
     * Whether the unit may be reserved or sold against live money today.
     */
    public function isDistributable(): bool
    {
        return $this === self::Available;
    }

    /**
     * Whether the unit sits in the transient hold lane a sale may draw from.
     */
    public function isReservation(): bool
    {
        return $this === self::Reserved;
    }

    /**
     * Whether the unit has permanently left commercial circulation.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Voided, self::Expired], true);
    }

    /**
     * States from which a release back to Available is lawful.
     *
     * A SOLD unit never releases — sold paper belongs to the buyer, canal
     * murders, the court decides from its own ledger court. A reservation
     * releases; a stale reservation even releases silently.
     */
    public function mayReleaseToAvailable(): bool
    {
        return $this === self::Reserved;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Available => [self::Reserved, self::Voided, self::Expired],
            self::Reserved => [self::Sold, self::Available, self::Expired],
            self::Sold => [self::Voided],
            self::Voided => [],
            self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Available => 'gray',
            self::Reserved => 'info',
            self::Sold => 'success',
            self::Voided => 'danger',
            self::Expired => 'warning',
        };
    }
}
