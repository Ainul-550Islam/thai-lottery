<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OperationalReportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * GenerateOperationalReportJob — runs a queued report generation
 * asynchronously and replay-safely by query fingerprint; a row
 * already seated is a replay, never double work.
 */
final class GenerateOperationalReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $queryFingerprint,
    ) {
    }

    public function handle(\App\Services\Operations\OperationalReportService $reports): void
    {
        $job = OperationalReportJob::query()
            ->where('query_fingerprint', $this->queryFingerprint)
            ->first();

        if (! $job instanceof OperationalReportJob) {
            return;
        }

        try {
            $reports->run($this->queryFingerprint);
        } catch (\App\Exceptions\OperationalReportException) {
            // Terminal/generation lane already seated on the row.
        }
    }
}
