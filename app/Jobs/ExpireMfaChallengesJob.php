<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Security\MfaChallengeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ExpireMfaChallengesJob — server-horizon expiry of MFA challenges,
 * replay-safe by construction (the status row IS the mark).
 */
final class ExpireMfaChallengesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const PAGE_SIZE = 200;

    public function handle(MfaChallengeService $challenges): void
    {
        $challenges->expireDue(self::PAGE_SIZE);
    }
}
