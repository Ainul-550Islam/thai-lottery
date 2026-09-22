<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SecurityRiskLevel;
use App\Listeners\RecordSecurityEventAudit;
use App\Services\Security\SecurityEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ReviewHighRiskSecurityEventsJob — the desk's reviewer sweep: stand
 * up unreviewed High/Critical security events for the operations
 * queue (anchor-audited exactly once apiece); marking them reviewed
 * is the desk's own later act — this job only lights them up.
 */
final class ReviewHighRiskSecurityEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PAGE_SIZE = 100;

    public function handle(SecurityEventService $events, RecordSecurityEventAudit $audit): void
    {
        $events->unreviewedAtOrAbove(SecurityRiskLevel::High, self::PAGE_SIZE)
            ->each(fn ($event) => $audit->from($event, 'high-risk security event queued for desk review'));
    }
}
