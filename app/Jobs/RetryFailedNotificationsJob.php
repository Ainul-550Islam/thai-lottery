<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Services\Notification\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RetryFailedNotificationsJob — retries ONLY eligible failures
 * (retryable reason, below ceiling, before horizon), re-queueing
 * each once; terminal deliveries are never duplicated.
 */
final class RetryFailedNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PAGE_SIZE = 100;

    public function handle(NotificationDeliveryService $delivery): void
    {
        $delivery->retryableFailures(self::PAGE_SIZE)->each(function ($notification): void {
            $notification->status = NotificationStatus::Queued;
            $notification->save();
        });
    }
}
