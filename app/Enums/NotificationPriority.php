<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * NotificationPriority — delivery priority. The Critical lane
 * exists exactly for mandatory notices (security/payment/settlement)
 * which shield against quiet windows and preference silencing;
 * the law is that the FLOOR, not a preference, carries them.
 */
enum NotificationPriority: string
{
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Normal => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    /**
     * Whether this priority bypasses quiet-window suppression.
     */
    public function piercesQuietWindows(): bool
    {
        return $this === self::Critical;
    }
}
