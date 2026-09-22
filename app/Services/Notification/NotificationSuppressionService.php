<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\DTOs\Notification\NotificationMessageData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationFailureReason;

/**
 * NotificationSuppressionService — the desk's four suppression
 * rules, pronounced at queue time BEFORE a delivery is ever crafted
 * (quiet windows, preferences, duplicates, invalid destination).
 * Mandatory (Critical) notices pierce quiet windows and preference
 * silence; duplicates suppress regardless — one pronouncement lands,
 * never two copies of one.
 */
final class NotificationSuppressionService
{
    /**
     * Ask BEFORE crafting the row's text/expiry. Returns the
     * suppression reason when suppression is the law, null when the
     * pronouncement may proceed.
     */
    public function reasonFor(int $userId, NotificationMessageData $message): ?NotificationFailureReason
    {
        // ONE: preferences silence the non-mandatory floor.
        if (! app(NotificationPreferenceService::class)->enabledFor($userId, $message->eventType, $message->channel)) {
            return NotificationFailureReason::SuppressedPreference;
        }

        // TWO: quiet windows hush everything but Critical.
        if (! $message->priority->piercesQuietWindows()
            && $this->insideQuietWindow(app(NotificationPreferenceService::class)->quietHoursFor($userId, $message->eventType, $message->channel))) {
            return NotificationFailureReason::SuppressedQuietWindow;
        }

        // THREE: the exact pronouncement must not be copied twice.
        $exists = \App\Models\Notification::query()
            ->where('message_fingerprint', $message->messageFingerprint())
            ->exists();

        if ($exists) {
            return NotificationFailureReason::SuppressedDuplicate;
        }

        return null;
    }

    /**
     * Whether the CURRENT local time falls inside a quiet window
     * (windows may cross midnight; an absent window never quiets).
     */
    public function insideQuietWindow(?array $quietHours): bool
    {
        if ($quietHours === null || ! isset($quietHours['start'], $quietHours['end'])) {
            return false;
        }

        $now = (int) now()->format('H') * 60 + (int) now()->format('i');
        [$start, $end] = [self::minutes($quietHours['start']), self::minutes($quietHours['end'])];

        return $start <= $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end); // crosses midnight
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);

        return $h * 60 + $m;
    }
}
