<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AmlRiskAssessment;
use App\Models\User;
use App\Services\Compliance\AmlRiskAssessmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled AML reassessment using current authoritative evidence.
 *
 * A stale assessment (its evidence fingerprint no longer matches the
 * measured evidence, or it aged past the horizon) is REPLACED by a
 * new version — the old row is superseded, never edited. A page at a
 * time, cursor-resumable.
 */
final class ReassessAmlRiskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAGE_SIZE = 100;

    /**
     * An assessment whose facts are older than this (hours) may be
     * re-pronounced even without evident change.
     */
    public const STALE_HORIZON_HOURS = 24;

    public function __construct(
        public readonly int $afterUserId = 0,
    ) {
        $this->onQueue('finance-reconciliation');
    }

    public function handle(AmlRiskAssessmentService $assessments): void
    {
        $pronounced = 0;
        $replaced = 0;
        $lastId = $this->afterUserId;

        User::query()
            ->where('id', '>', $this->afterUserId)
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get(['id'])
            ->each(function (User $user) use ($assessments, &$pronounced, &$replaced, &$lastId): void {
                $lastId = (int) $user->id;

                /** @var AmlRiskAssessment|null $current */
                $current = AmlRiskAssessment::query()
                    ->where('user_id', $user->id)
                    ->where('status', AmlRiskAssessment::STATUS_CURRENT)
                    ->first();

                // Fresh-enough evidence paper needs no new ink unless
                // the measured facts themselves have moved.
                if ($current instanceof AmlRiskAssessment
                    && $current->assessed_at !== null
                    && $current->assessed_at->gte(now()->subHours(self::STALE_HORIZON_HOURS))) {
                    return;
                }

                ['replayed' => $replayed, 'superseded' => $superseded] = $assessments->pronounce((int) $user->id);

                if (! $replayed) {
                    $pronounced++;

                    if ($superseded) {
                        $replaced++;
                    }
                }
            });

        if ($pronounced > 0) {
            Log::info('AML reassessment sweep pronounced new evidence.', [
                'pronounced' => $pronounced,
                'superseded' => $replaced,
            ]);
        }

        if ($lastId > $this->afterUserId && User::query()->where('id', '>', $lastId)->exists()) {
            self::dispatch($lastId);
        }
    }
}
