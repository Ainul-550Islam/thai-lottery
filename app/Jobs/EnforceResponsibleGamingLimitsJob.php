<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * EnforceResponsibleGamingLimitsJob — the periodic enforcement /
 * reconciliation beat: seats Pending increases whose cooling-off
 * has run and expires versions whose horizon passed; the live
 * enforcement between beats remains the fail-closed facade's job.
 */
final class EnforceResponsibleGamingLimitsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PAGE_SIZE = 100;

    public function handle(ResponsibleGamingLimitService $limits): void
    {
        $limits->activateDuePending(self::PAGE_SIZE);
        $limits->expireStale(self::PAGE_SIZE);
    }
}
