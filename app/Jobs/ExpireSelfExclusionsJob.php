<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ResponsibleGaming\SelfExclusionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ExpireSelfExclusionsJob — expires ONLY exclusions whose
 * server-authoritative end time has passed (and activates requests
 * whose effective moment arrived, in the same sweep: the two halves
 * of the same physics). Replay-safe, chunk-paged, audit-anchored.
 */
final class ExpireSelfExclusionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PAGE_SIZE = 100;

    public function __construct(
        private readonly ?int $beforeId = null,
    ) {}

    public function handle(SelfExclusionService $service): void
    {
        // Activations due: requests whose effective moment the clock crossed.
        $service->dueForActivation(self::PAGE_SIZE)->each(
            fn ($row) => $service->activate($row),
        );

        // Expirations due: active exclusions the clock has run out.
        $service->dueForExpiry(self::PAGE_SIZE)->each(
            fn ($row) => $service->expire($row),
        );
    }
}
