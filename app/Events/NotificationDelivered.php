<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Notification;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Exactly-once envelope after a normalized delivery confirmation.
 */
final class NotificationDelivered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Notification $notification,
        public readonly string $deliveredAt,
    ) {
    }

    public function deliveryFingerprint(): string
    {
        return hash('sha256', 'glo-notif-delivered|'.(string) $this->notification->message_fingerprint.'|'.$this->deliveredAt);
    }
}
