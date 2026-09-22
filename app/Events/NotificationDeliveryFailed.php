<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\NotificationFailureReason;
use App\Models\Notification;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Exactly-once TERMINAL failure event with SANITIZED context:
 * no provider token, credential or raw callback payload ever rides
 * this envelope.
 */
final class NotificationDeliveryFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Notification $notification,
        public readonly NotificationFailureReason $reason,
    ) {
    }

    public function failureFingerprint(): string
    {
        return hash('sha256', 'glo-notif-failed|'.(string) $this->notification->message_fingerprint.'|'.$this->reason->value);
    }
}
