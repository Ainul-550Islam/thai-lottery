<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Notification\NotificationPreferenceData;
use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationException;
use App\Exceptions\NotificationPreferenceException;
use App\Http\Responses\ApiResponse;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationDispatchService;
use App\Services\Notification\NotificationPreferenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NotificationController — the authenticated player's notification
 * surface: preferences, listing with read state, and the safe
 * status view (fingerprints only, never payloads of others).
 */
final class NotificationController
{
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
        private readonly NotificationDispatchService $dispatch,
    ) {
    }

    /** GET /notifications — own rows, newest first. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rows = Notification::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                NotificationStatus::Queued->value,
                NotificationStatus::Sent->value,
                NotificationStatus::Delivered->value,
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(static fn (Notification $n): array => [
                'id' => $n->id,
                'event_type' => $n->event_type->value,
                'channel' => $n->channel->value,
                'priority' => $n->priority->value,
                'subject' => $n->subject,
                'body' => $n->body,
                'created_at' => $n->created_at?->toIso8601String(),
                'read_at' => $n->read_at?->toIso8601String(),
            ]);

        return ApiResponse::success(['notifications' => $rows], 'Notifications listed.');
    }

    /** POST /notifications/{id}/read — own rows only. */
    public function markRead(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $row = $this->dispatch->markRead($id, (int) $user->id);
        } catch (NotificationException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 404);
        }

        return ApiResponse::success([
            'read_at' => $row->read_at?->toIso8601String(),
        ], 'Marked as read.');
    }

    /** GET /notifications/preferences — the player's current blend. */
    public function preferences(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success([
            'preferences' => $this->preferences->allFor((int) $user->id)
                ->map(static fn ($p): array => [
                    'event_type' => $p->event_type->value,
                    'channel' => $p->channel->value,
                    'enabled' => $p->enabled,
                    'quiet_hours' => $p->quiet_hours,
                    'mandatory' => $p->event_type->isMandatory(),
                ]),
        ], 'Preferences loaded.');
    }

    /** PUT /notifications/preferences — upsert one (event, channel). */
    public function writePreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string'],
            'channel' => ['required', 'string', 'in:in_app,sms,email,push'],
            'enabled' => ['required', 'boolean'],
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $row = $this->preferences->write(NotificationPreferenceData::fromInput([
                'user_id' => (int) $user->id,
                'event_type' => $validated['event_type'],
                'channel' => $validated['channel'],
                'enabled' => (bool) $validated['enabled'],
                'quiet_hours' => $validated['quiet_hours'] ?? null,
            ]));
        } catch (NotificationPreferenceException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'event_type' => $row->event_type->value,
            'channel' => $row->channel->value,
            'enabled' => $row->enabled,
            'quiet_hours' => $row->quiet_hours,
        ], 'Preference saved.');
    }

    /** GET /notifications/status/{fingerprint} — SAFE clerk view. */
    public function status(Request $request, string $fingerprint): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Notification|null $row */
        $row = Notification::query()
            ->where('user_id', $user->id)
            ->where('message_fingerprint', $fingerprint)
            ->first();

        if (! $row instanceof Notification) {
            return ApiResponse::error('notif_not_found', 'No such notification.', 404);
        }

        return ApiResponse::success([
            'status' => $row->status->value,
            'event_type' => $row->event_type->value,
            'channel' => $row->channel->value,
            'attempts' => $row->attempts,
            'queued_at' => $row->queued_at?->toIso8601String(),
            'delivered_at' => $row->delivered_at?->toIso8601String(),
            'read_at' => $row->read_at?->toIso8601String(),
        ], 'Delivery status.');
    }
}
