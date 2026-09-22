<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\DTOs\Notification\NotificationMessageData;
use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationException;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;

/**
 * NotificationDispatchService — the central event → notification
 * RESOLUTION gate: binds the event to its template + lane when one
 * exists (content becomes part of the pronouncement's identity),
 * applies suppression, and queues the born notification. THE ONLY
 * door to a new row on `notifications`; nothing here speaks to a
 * provider, provider contact is Delivery's arena.
 */
final class NotificationDispatchService
{
    public function __construct(
        private readonly NotificationSuppressionService $suppression,
        private readonly \App\Listeners\RecordNotificationAudit $audit,
    ) {
    }

    /**
     * RESOLVE + QUEUE.
     *
     * @return array{notification: Notification|null, suppressed: bool, reason: string|null, replayed: bool}
     */
    public function pronounce(int $userId, NotificationMessageData $message): array
    {
        return DB::transaction(function () use ($userId, $message): array {
            // Suppression knows first: asked at pronouncement time so
            // quiet hours count from now and duplicates are branded.
            $suppress = $this->suppression->reasonFor($userId, $message);

            if ($suppress !== null) {
                return [
                    'notification' => null,
                    'suppressed' => true,
                    'reason' => $suppress->value,
                    'replayed' => false,
                ];
            }

            $fingerprint = $message->messageFingerprint();

            /** @var Notification|null $existing */
            $existing = Notification::query()->where('message_fingerprint', $fingerprint)->first();

            if ($existing instanceof Notification) {
                return [
                    'notification' => $existing,
                    'suppressed' => false,
                    'reason' => null,
                    'replayed' => true,
                ];
            }

            // Template binding — when the event has an accountable
            // registered lane, its CURRENT ACTIVE version becomes the
            // content the row pronounces (content from the caller is
            // a fallback, not a mandate).
            $subject = $message->subject;
            $body = $message->body;
            $templateId = $message->templateId;

            if ($templateId !== null) {
                /** @var NotificationTemplate|null $tpl */
                $tpl = NotificationTemplate::query()->find($templateId);

                if ($tpl instanceof NotificationTemplate) {
                    if (! $tpl->status->acceptsEnqueues()) {
                        // A dead lane never renders new messages.
                        return [
                            'notification' => null,
                            'suppressed' => true,
                            'reason' => \App\Enums\NotificationFailureReason::TemplateDisabled->value,
                            'replayed' => false,
                        ];
                    }

                    $subject = $tpl->subject;
                    $body = $tpl->body;
                }
            }

            $row = Notification::query()->create([
                'message_fingerprint' => $fingerprint,
                'user_id' => $userId,
                'event_type' => $message->eventType,
                'channel' => $message->channel,
                'priority' => $message->priority,
                'status' => NotificationStatus::Queued,
                'locale' => $message->locale,
                'subject' => $subject !== '' ? $subject : null,
                'body' => $body !== '' ? $body : null,
                'payload_fingerprint' => $message->payloadFingerprint(),
                'template_id' => $templateId,
                'queued_at' => now(),
                'expires_at' => $message->expiresAt,
            ]);

            $this->audit->from($row, 'notification pronounced + queued');

            return [
                'notification' => $row,
                'suppressed' => false,
                'reason' => null,
                'replayed' => false,
            ];
        });
    }

    /**
     * MARK READ: own rows alone; first read wins, replays free.
     */
    public function markRead(int $notificationId, int $requesterUserId): Notification
    {
        return DB::transaction(function () use ($notificationId, $requesterUserId): Notification {
            /** @var Notification $row */
            $row = Notification::query()->lockForUpdate()->findOrFail($notificationId);

            if ((int) $row->user_id !== $requesterUserId) {
                throw NotificationException::ownershipMismatch($notificationId, $requesterUserId);
            }

            if ($row->read_at === null) {
                $row->read_at = now();
                $row->save();
            }

            return $row->refresh();
        });
    }
}
