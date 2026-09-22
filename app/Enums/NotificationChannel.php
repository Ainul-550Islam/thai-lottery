<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * NotificationChannel — the delivery lanes. Every message row
 * carries exactly one; None is pronounced when a preference or
 * suppression silences (a deliberate answer, not an omission).
 */
enum NotificationChannel: string
{
    case None = 'none';
    case InApp = 'in_app';
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    public function isDeliverable(): bool
    {
        return $this !== self::None;
    }
}
