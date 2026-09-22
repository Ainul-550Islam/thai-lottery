<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\DTOs\Notification\NotificationPreferenceData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Exceptions\NotificationPreferenceException;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;

/**
 * NotificationPreferenceService — player-owned preferences with the
 * MANDATORY-MESSAGE BYPASS PROTECTION: the floor can never be
 * silenced, rows for (user, event, channel) are exactly one each,
 * renewal replays freely.
 */
final class NotificationPreferenceService
{
    /**
     * WRITE: one row per (user, event, channel) — creation replays,
     * updates apply atomically.
     */
    public function write(NotificationPreferenceData $data): NotificationPreference
    {
        return DB::transaction(function () use ($data): NotificationPreference {
            /** @var NotificationPreference $row */
            $row = NotificationPreference::query()->firstOrNew([
                'user_id' => $data->userId,
                'event_type' => $data->eventType->value,
                'channel' => $data->channel->value,
            ]);

            $row->enabled = $data->enabled;
            $row->quiet_hours = $data->quietHours;
            $row->save();

            return $row;
        });
    }

    public function enabledFor(int $userId, NotificationEventType $eventType, NotificationChannel $channel): bool
    {
        if ($eventType->isMandatory()) {
            return true; // the floor silence-protection, second gate.
        }

        /** @var NotificationPreference|null $row */
        $row = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('event_type', $eventType->value)
            ->where('channel', $channel->value)
            ->first();

        return $row === null ? true : (bool) $row->enabled;
    }

    /**
     * Player's quiet hours — returns hours to TEST AT DELIVERY TIME
     * in player-local window terms (start ≤ end within a day cycle,
     * crossing midnight honoured).
     *
     * @return array{start:string, end:string}|null
     */
    public function quietHoursFor(int $userId, NotificationEventType $eventType, NotificationChannel $channel): ?array
    {
        /** @var NotificationPreference|null $row */
        $row = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('event_type', $eventType->value)
            ->where('channel', $channel->value)
            ->first();

        return $row instanceof NotificationPreference ? $row->quiet_hours : null;
    }

    public function disableQuietHours(int $userId, NotificationEventType $eventType, NotificationChannel $channel): void
    {
        NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('event_type', $eventType->value)
            ->where('channel', $channel->value)
            ->update(['quiet_hours' => null]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, NotificationPreference>
     */
    public function allFor(int $userId): \Illuminate\Support\Collection
    {
        return NotificationPreference::query()
            ->where('user_id', $userId)
            ->orderBy('event_type')
            ->orderBy('channel')
            ->get();
    }
}
