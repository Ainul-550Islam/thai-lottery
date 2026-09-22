<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\NotificationDelivered;
use App\Events\NotificationDeliveryFailed;
use App\Models\AuditLog;
use App\Models\Notification;

/**
 * RecordNotificationAudit — immutable exactly-once audit over the
 * notification lifecycle. NO SECRETS, PROVIDER TOKENS OR PAYLOAD
 * TEXT EVER APPEAR — fingerprints and status alone.
 */
final class RecordNotificationAudit
{
    private const LOOKBACK_HOURS = 72;

    public function handle(NotificationDelivered|NotificationDeliveryFailed $event): void
    {
        $anchor = $event instanceof NotificationDelivered
            ? 'notif-evt-audit:'.$event->deliveryFingerprint()
            : 'notif-evt-audit:'.$event->failureFingerprint();

        $this->stamp($event->notification, $event instanceof NotificationDelivered ? 'delivered (envelope)' : 'failed (envelope)', $anchor);
    }

    /**
     * THE STATIC SCRIBE — called in-transaction by the services.
     */
    public function from(Notification $notification, string $note): void
    {
        $this->stamp($notification, $note, 'notif-audit:'.(string) $notification->message_fingerprint.':'.$notification->status->value.':'.$note);
    }

    private function stamp(Notification $notification, string $note, string $anchor): void
    {
        $exists = AuditLog::query()
            ->where('auditable_type', Notification::class)
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => $notification->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => Notification::class,
            'auditable_id' => $notification->id,
            'metadata' => [
                'lane' => 'notification',
                'risk_rating' => RiskLevel::Medium->value,
                'message_fingerprint' => $notification->message_fingerprint,
                'event_type' => $notification->event_type->value,
                'channel' => $notification->channel->value,
                'status' => $notification->status->value,
                'attempts' => $notification->attempts,
                'audit_anchor' => $anchor,
                'note' => $note,
            ],
        ]);
    }
}
