<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notification\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * DispatchPendingNotificationsJob — seats delivery ATTEMPTS for
 * due notifications with deterministic idempotency. The actual
 * provider send is a downstream obligation wired per channel; this
 * sweeper owns the state machine side only: attempts start exactly
 * once per (notification, attempt#), terminal rows are never
 * re-touched, stale rows expire on the same breath.
 */
final class DispatchPendingNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PAGE_SIZE = 100;

    public function handle(NotificationDeliveryService $delivery): void
    {
        $delivery->expireStale(self::PAGE_SIZE);

        $delivery->dueForDispatch(self::PAGE_SIZE)->each(function ($notification) use ($delivery): void {
            try {
                $delivery->beginAttempt($notification);
            } catch (\App\Exceptions\NotificationDeliveryException) {
                // Terminal/horizon races are benign: the row carries the truth.
            }
        });
    }
}
