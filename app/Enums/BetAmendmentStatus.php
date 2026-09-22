<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a bet amendment request.
 *
 * An amendment row reports what happened to a request to replace one bet with
 * another (new number and/or new stake). The amendment itself never holds money:
 * the refund of the old bet and the debit of the new bet are performed by the
 * services the row links to.
 *
 *   pending  -> the amendment row exists; the replacement purchase has not run yet
 *   applied  -> the old bet is cancelled AND the replacement bet committed
 *   failed   -> the old bet was refunded but the replacement purchase was refused
 *   expired  -> the request sat past its window and can never be applied
 *
 * VALUES ARE PERSISTED in bet_amendments.status. Add new cases at the end; never
 * rename or remove one.
 */
enum BetAmendmentStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Applied => 'Applied',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    /**
     * Whether the amendment can still move to applied. A pending amendment is the
     * only mutable state; applied/failed/expired are terminal reports.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Applied, self::Failed, self::Expired], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Applied => 'green',
            self::Failed => 'red',
            self::Expired => 'gray',
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
