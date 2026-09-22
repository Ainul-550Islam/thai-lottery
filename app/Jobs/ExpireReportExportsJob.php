<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ExpireReportExportsJob — server-horizon cleanup: exports that
 * passed their retention horizon are withdrawn, stale report jobs
 * age out. Every seat decision is ledger-visible; revisiting is
 * free.
 */
final class ExpireReportExportsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(
        \App\Services\Operations\ReportExportService $exports,
        \App\Services\Operations\OperationalReportService $reports,
    ): void {
        $exports->expireStale(200);
        $reports->expireStale(200);
    }
}
