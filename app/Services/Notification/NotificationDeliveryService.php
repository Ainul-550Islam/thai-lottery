<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\DTOs\Notification\NotificationDeliveryData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationFailureReason;
use App\Enums\NotificationStatus;
use App\Events\NotificationDelivered;
use App\Events\NotificationDeliveryFailed;
use App\Exceptions\NotificationDeliveryException;
use App\Models\Notification;
use App\Models\NotificationDeliveryAttempt;
use Illuminate\Support\Facades\DB;

/**
 * NotificationDeliveryService — the provider-neutral delivery state
 * machine. THE ACTUAL NETWORK SEND is a caller-effected callback
 * (the gate hands the desk a sealed lane; what leaves the wire is
 * the driver's own payload) — this service owns the attempt rows,
 * the retry ceiling, and the terminal events that fly exactly once.
 */
final class NotificationDeliveryService
{
    public function __construct(
        private readonly \App\Listeners\RecordNotificationAudit $audit,
    ) {
    }

    /**
     * START an attempt on a notification. Terminal rows refuse;
     * ceiling rows lock by name.
     *
     * @return array{attempt: NotificationDeliveryAttempt, replayed: bool}
     */
    public function beginAttempt(Notification $notification): array
    {
        return DB::transaction(function () use ($notification): array {
            /** @var Notification $locked */
            $locked = Notification::query()->lockForUpdate()->findOrFail($notification->id);

            if ($locked->status->isTerminal()) {
                throw NotificationDeliveryException::terminalRepeat($locked->id);
            }

            if ($locked->isPastHorizon()) {
                $locked->status = NotificationStatus::Expired;
                $locked->failure_reason = NotificationFailureReason::Expired;
                $locked->save();

                throw NotificationDeliveryException::terminalRepeat($locked->id);
            }

            $nextAttempt = $locked->attempts + 1;

            $data = NotificationDeliveryData::forAttempt([
                'notification_id' => $locked->id,
                'channel' => $locked->channel->value,
                'attempt_number' => $nextAttempt,
            ]);

            /** @var NotificationDeliveryAttempt|null $existing */
            $existing = NotificationDeliveryAttempt::query()
                ->where('attempt_identity', $data->attemptIdentity())
                ->first();

            if ($existing instanceof NotificationDeliveryAttempt) {
                return ['attempt' => $existing, 'replayed' => true];
            }

            $attempt = NotificationDeliveryAttempt::query()->create([
                'attempt_identity' => $data->attemptIdentity(),
                'notification_id' => $locked->id,
                'attempt_number' => $nextAttempt,
                'channel' => $locked->channel,
                'status' => 'in_flight',
                'attempted_at' => now(),
            ]);

            $locked->status = NotificationStatus::Sent; // the attempt is airborne
            $locked->attempts = $nextAttempt;
            $locked->sent_at = now();
            $locked->save();

            return ['attempt' => $attempt, 'replayed' => false];
        });
    }

    /**
     * SEAT: delivery confirmed (caller reached the provider).
     * Exactly-once event, row stamps, audit.
     */
    public function seatDelivered(Notification $notification, ?string $providerReference = null): Notification
    {
        return DB::transaction(function () use ($notification, $providerReference): Notification {
            /** @var Notification $locked */
            $locked = Notification::query()->lockForUpdate()->findOrFail($notification->id);

            if ($locked->status === NotificationStatus::Delivered) {
                return $locked; // replay free
            }

            $this->completeAttempt($locked, 'delivered', null, $providerReference);
            $locked->status = NotificationStatus::Delivered;
            $locked->delivered_at = now();
            $locked->failure_reason = null;
            $locked->save();

            $this->audit->from($locked, 'notification delivered');
            event(new NotificationDelivered($locked, $locked->delivered_at->toIso8601String()));

            return $locked->refresh();
        });
    }

    /**
     * SEAT: failure. Retryable seals Failed (job retries); terminal
     * refusal seals as Failed-by-policy too — never re-opened.
     */
    public function seatFailed(Notification $notification, NotificationFailureReason $reason, ?string $providerReference = null): Notification
    {
        return DB::transaction(function () use ($notification, $reason, $providerReference): Notification {
            /** @var Notification $locked */
            $locked = Notification::query()->lockForUpdate()->findOrFail($notification->id);

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            $this->completeAttempt($locked, 'failed', $reason, $providerReference);
            $locked->status = NotificationStatus::Failed;
            $locked->failure_reason = $reason;
            $locked->save();

            $this->audit->from($locked, 'notification failed ('.$reason->value.')');
            event(new NotificationDeliveryFailed($locked, $reason));

            return $locked->refresh();
        });
    }

    /**
     * ELIGIBILITY for the retry queue: Failed with a retryable reason
     * and attempts below the ceiling and before the horizon.
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    public function retryableFailures(int $limit = 100): \Illuminate\Support\Collection
    {
        return Notification::query()
            ->where('status', NotificationStatus::Failed->value)
            ->whereIn('failure_reason', [
                NotificationFailureReason::ProviderFailure->value,
                NotificationFailureReason::Throttled->value,
            ])
            ->where('attempts', '<', NotificationDeliveryData::MAX_ATTEMPTS)
            ->where('expires_at', '>', now())
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * CLAIM for dispatch: Pending / Queued / (airborne-only) rows
     * due to leave, before horizon. Skips bottom-locked lanes.
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    public function dueForDispatch(int $limit = 100): \Illuminate\Support\Collection
    {
        return Notification::query()
            ->whereIn('status', [NotificationStatus::Pending->value, NotificationStatus::Queued->value])
            ->where('expires_at', '>', now())
            ->orderBy('queued_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Server-horizon expiry sweep.
     */
    public function expireStale(int $limit = 200): int
    {
        $expired = 0;

        Notification::query()
            ->whereIn('status', [
                NotificationStatus::Pending->value,
                NotificationStatus::Queued->value,
                NotificationStatus::Sent->value,
                NotificationStatus::Failed->value,
            ])
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->get()
            ->each(function (Notification $notification) use (&$expired): void {
                DB::transaction(function () use ($notification, &$expired): void {
                    /** @var Notification $locked */
                    $locked = Notification::query()->lockForUpdate()->findOrFail($notification->id);

                    if ($locked->status->isTerminal()) {
                        return;
                    }

                    $locked->status = NotificationStatus::Expired;
                    $locked->failure_reason = NotificationFailureReason::Expired;
                    $locked->save();
                    $expired++;
                });
            });

        return $expired;
    }

    private function completeAttempt(Notification $notification, string $outcome, ?NotificationFailureReason $reason, ?string $providerReference): void
    {
        /** @var NotificationDeliveryAttempt|null $open */
        $open = NotificationDeliveryAttempt::query()
            ->where('notification_id', $notification->id)
            ->where('attempt_number', $notification->attempts)
            ->where('status', 'in_flight')
            ->first();

        if ($open instanceof NotificationDeliveryAttempt) {
            $open->status = $outcome;
            $open->failure_reason = $reason;
            $open->provider_reference = $providerReference;
            $open->completed_at = now();
            $open->save();
        }
    }
}
