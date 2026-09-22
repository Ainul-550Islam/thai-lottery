<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Security\UserSessionSecurityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RevokeExpiredSessionsJob — expires sessions by SERVER timestamps
 * alone; client-supplied clocks never expire anything on this desk.
 */
final class RevokeExpiredSessionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PAGE_SIZE = 200;

    public function handle(UserSessionSecurityService $sessions): void
    {
        $sessions->expireDue(self::PAGE_SIZE);
    }
}
