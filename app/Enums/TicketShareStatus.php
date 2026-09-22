<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a ticket share link.
 *
 *   active   -> the share token resolves and the ticket summary can be viewed
 *   revoked  -> the owner withdrew the share before it expired
 *   expired  -> the time-to-live elapsed; the token no longer resolves
 *
 * Expired is computed at read time from ticket_shares.expires_at rather than
 * being written, so there is no dependency on a sweeper running on schedule for
 * a dead link to stop working.
 *
 * VALUES ARE PERSISTED in ticket_shares.status. Add new cases at the end; never
 * rename or remove one.
 */
enum TicketShareStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Revoked => 'Revoked',
            self::Expired => 'Expired',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isFinal(): bool
    {
        // Revoked is operator/owner-written and final; expired is time-written
        // and also cannot return to active.
        return in_array($this, [self::Revoked, self::Expired], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
