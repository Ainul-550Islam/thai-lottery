<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ResponsibleGaming\RealityCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * DeliverDueRealityChecksJob — delivers due reality checks in chunks
 * with deterministic dedupe (delivery is fingerprint-keyed and
 * replay-safe by design; a retry of one page re-delivers nothing
 * twice), and sweeps overdue rows into Expired physics.
 */
final class DeliverDueRealityChecksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PAGE_SIZE = 100;

    public function handle(RealityCheckService $checks): void
    {
        $checks->promoteDue(self::PAGE_SIZE);

        $checks->dueForDelivery(self::PAGE_SIZE)->each(
            fn ($check) => $checks->deliver($check),
        );

        $checks->expireOverdue(self::PAGE_SIZE);
    }
}
