<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ResponsibleGaming\PlayerProtectionCaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ReviewPlayerProtectionCasesJob — the desk-clock reviewer: files
 * Open past the claim horizon (4h), or sitting in Monitoring past
 * the pronounce horizon (24h), are escalated by the deterministic
 * rule with desk-clock evidence. The rule lives here, pronounced
 * exactly once per passage (the service guards replays).
 */
final class ReviewPlayerProtectionCasesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const OPEN_CLAIM_HORIZON_HOURS = 4;

    public const MONITORING_PRONOUNCE_HORIZON_HOURS = 24;

    public const PAGE_SIZE = 100;

    public function handle(PlayerProtectionCaseService $cases): void
    {
        $cases->staleOpen(self::OPEN_CLAIM_HORIZON_HOURS, self::PAGE_SIZE)->each(
            fn ($case) => $cases->escalate($case, sprintf('Open unclaimed > %dh (desk-clock rule)', self::OPEN_CLAIM_HORIZON_HOURS), 'desk-clock'),
        );

        $cases->staleMonitoring(self::MONITORING_PRONOUNCE_HORIZON_HOURS, self::PAGE_SIZE)->each(
            fn ($case) => $cases->escalate($case, sprintf('Monitoring > %dh without pronouncement (desk-clock rule)', self::MONITORING_PRONOUNCE_HORIZON_HOURS), 'desk-clock'),
        );
    }
}
