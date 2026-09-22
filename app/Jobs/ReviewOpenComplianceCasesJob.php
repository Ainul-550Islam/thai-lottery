<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ComplianceCaseStatus;
use App\Exceptions\ComplianceCaseException;
use App\Models\ComplianceCase;
use App\Services\Compliance\ComplianceCaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The desk's own clock: detects stale/open cases and escalates
 * according to DETERMINISTIC rules (no clerk decides when a case is
 * old — the clock decides, by the rule written below):
 *
 *   Open          AND older than 4 h                → Escalated
 *                   ('intake-overdue')
 *   Investigating AND older than 24 h               → Escalated
 *                   ('investigation-overdue')
 *
 * Everything the job does lands as evidence: the case's escalation
 * reason and the ComplianceCaseEscalated envelope are emitted inside
 * the case service's own transaction by the service itself.
 */
final class ReviewOpenComplianceCasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAGE_SIZE = 100;

    public const OPEN_HORIZON_HOURS = 4;

    public const INVESTIGATING_HORIZON_HOURS = 24;

    public function __construct()
    {
        $this->onQueue('finance-reconciliation');
    }

    public function handle(ComplianceCaseService $cases): void
    {
        $escalated = 0;
        $failed = 0;

        ComplianceCase::query()
            ->where(function ($q): void {
                $q->where(fn ($a) => $a->where('status', ComplianceCaseStatus::Open->value)
                    ->where('created_at', '<=', now()->subHours(self::OPEN_HORIZON_HOURS)))
                    ->orWhere(fn ($b) => $b->where('status', ComplianceCaseStatus::Investigating->value)
                        ->where('investigating_since', '<=', now()->subHours(self::INVESTIGATING_HORIZON_HOURS)));
            })
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->each(function (ComplianceCase $case) use ($cases, &$escalated, &$failed): void {
                $reason = $case->status === ComplianceCaseStatus::Open
                    ? sprintf('Desk-clock rule: intake overdue (open > %dh)', self::OPEN_HORIZON_HOURS)
                    : sprintf('Desk-clock rule: investigation overdue (investigating > %dh)', self::INVESTIGATING_HORIZON_HOURS);

                try {
                    $cases->escalate($case, $reason, 'desk-clock');
                    $escalated++;
                } catch (ComplianceCaseException|\Throwable $e) {
                    $failed++;

                    Log::error('Compliance-case desk-clock escalation refused.', [
                        'case' => $case->case_key,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        if ($escalated > 0 || $failed > 0) {
            Log::warning('Compliance desk-clock review completed.', [
                'escalated' => $escalated,
                'failed' => $failed,
            ]);
        }
    }
}
