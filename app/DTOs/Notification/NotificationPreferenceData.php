<?php

declare(strict_types=1);

namespace App\DTOs\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Exceptions\NotificationPreferenceException;

/**
 * Player-channel/event preference write. The mandatory-message floor
 * is ENFORCED HERE: a preference row may never exist that disables a
 * mandatory notice (disabling rows self-correct to enabled, and the
 * audit lane hears the correction).
 */
final class NotificationPreferenceData
{
    public function __construct(
        public readonly int $userId,
        public readonly NotificationEventType $eventType,
        public readonly NotificationChannel $channel,
        public readonly bool $enabled,
        public readonly ?array $quietHours,
    ) {
    }

    /**
     * @param array{user_id:int, event_type:string|NotificationEventType, channel:string|NotificationChannel, enabled:bool|string|int, quiet_hours?:array|null} $data
     */
    public static function fromInput(array $data): self
    {
        $event = $data['event_type'] ?? null;
        if (! $event instanceof NotificationEventType) {
            $event = is_string($event) ? NotificationEventType::tryFrom(strtolower(trim($event))) : null;
        }
        if (! $event instanceof NotificationEventType) {
            throw NotificationPreferenceException::malformed('A valid event type is required');
        }

        $channel = $data['channel'] ?? null;
        if (! $channel instanceof NotificationChannel) {
            $channel = is_string($channel) ? NotificationChannel::tryFrom(strtolower(trim($channel))) : null;
        }
        if (! $channel instanceof NotificationChannel || ! $channel->isDeliverable()) {
            throw NotificationPreferenceException::malformed('A deliverable channel is required');
        }

        $enabled = filter_var($data['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);

        // THE FLOOR: preferences may not silence mandatory notices.
        if ($event->isMandatory() && ! $enabled) {
            throw NotificationPreferenceException::mandatoryCannotBeSilenced($event->value);
        }

        $quiet = $data['quiet_hours'] ?? null;
        if ($quiet !== null) {
            $start = preg_split('/:/', (string) ($quiet['start'] ?? ''));
            $end = preg_split('/:/', (string) ($quiet['end'] ?? ''));
            $valid = count($start) === 2 && count($end) === 2
                && $start[0] >= 0 && $start[0] <= 23 && $start[1] >= 0 && $start[1] <= 59
                && $end[0] >= 0 && $end[0] <= 23 && $end[1] >= 0 && $end[1] <= 59;
            if (! $valid) {
                throw NotificationPreferenceException::malformed('Quiet hours must carry HH:MM start/end');
            }
            $quiet = ['start' => $quiet['start'], 'end' => $quiet['end']];
        }

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            eventType: $event,
            channel: $channel,
            enabled: $enabled,
            quietHours: $quiet,
        );
    }
}
