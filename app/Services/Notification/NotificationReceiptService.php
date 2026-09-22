<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\DTOs\Notification\NotificationReceiptData;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationReceipt;
use Illuminate\Support\Facades\DB;

/**
 * NotificationReceiptService — normalize provider callbacks into
 * IDEMPOTENT delivery receipts: same callback = one row, forever;
 * overlapping provider chattiness never double-pronounces a message.
 */
final class NotificationReceiptService
{
    public function __construct(
        private readonly NotificationDeliveryService $delivery,
        private readonly \App\Listeners\RecordNotificationReceiptAudit $audit,
    ) {
    }

    /**
     * RECORD the callback. Resolves by provider_reference against
     * the airborne slot that produced it; a callback for nothing we
     * airborne is refused by name (no invented pronouncements).
     *
     * @return array{receipt: NotificationReceipt, created: bool, notification_status: NotificationStatus}
     */
    public function record(NotificationReceiptData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var NotificationReceipt|null $existing */
            $existing = NotificationReceipt::query()->where('receipt_fingerprint', $data->receiptFingerprint())->first();

            if ($existing instanceof NotificationReceipt) {
                /** @var Notification $owner */
                $owner = Notification::query()->findOrFail($existing->notification_id);

                return ['receipt' => $existing, 'created' => false, 'notification_status' => $owner->status];
            }

            /** @var NotificationDeliveryAttempt|null $slot */
            $slot = NotificationDeliveryAttempt::query()
                ->where('provider_reference', $data->providerReference)
                ->orderByDesc('attempt_number')
                ->first();

            if (! $slot instanceof NotificationDeliveryAttempt) {
                // A callback with no airborne lineage: floor-check by
                // the parent row when the provider echoes a reference
                // the dispatch lane captured on the latest attempt.
                /** @var Notification|null $byOwner */
                $byOwner = Notification::query()
                    ->whereHas('deliveryAttempts', fn ($q) => $q->where('provider_reference', $data->providerReference))
                    ->first();

                if (! $byOwner instanceof Notification) {
                    throw \App\Exceptions\NotificationDeliveryException::invalidReceipt(
                        'provider reference ['.substr($data->providerReference, 0, 24).'] has no airborne lineage here',
                    );
                }
            }

            $slot ??= NotificationDeliveryAttempt::query()
                ->where('provider_reference', $data->providerReference)
                ->firstOrFail();

            $receipt = NotificationReceipt::query()->create([
                'receipt_fingerprint' => $data->receiptFingerprint(),
                'notification_id' => $slot->notification_id,
                'provider_reference' => $data->providerReference,
                'delivery_state' => $data->deliveryState,
                'failure_reason' => $data->failureReason,
                'reported_at' => $data->reportedAt,
            ]);

            /** @var Notification $notification */
            $notification = Notification::query()->findOrFail($slot->notification_id);

            if (! $notification->status->isTerminal()) {
                match ($data->deliveryState) {
                    'delivered', 'read' => $this->delivery->seatDelivered($notification, $data->providerReference),
                    'failed' => $this->delivery->seatFailed(
                        $notification,
                        $data->failureReason,
                        $data->providerReference,
                    ),
                    default => null,
                };
                $notification->refresh();
            }

            $this->audit->from($receipt, 'provider callback normalized + seated');

            return ['receipt' => $receipt, 'created' => true, 'notification_status' => $notification->status];
        });
    }
}
