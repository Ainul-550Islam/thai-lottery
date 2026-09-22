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
 * ExpireStaleNotificationsJob — server-horizon expiration of
 * undelivered backend records (physics only).
 */
final class ExpireStaleNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PAGE_SIZE = 200;

    public function handle(NotificationDeliveryService $delivery): void
    {
        $delivery->expireStale(self::PAGE_SIZE);
    }
}
