<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Draw\DrawReconciliationData;
use App\Enums\QueueName;
use App\Exceptions\DrawReconciliationException;
use App\Models\WinningNumber;
use App\Services\Draw\DrawCertificationService;
use App\Services\Draw\DrawReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background reconciliation for completed draws: idempotent, and its
 * solemn job is to surface drift — LOUDLY, never to heal it.
 *
 * Reads ONLY. Expected totals are rebuilt from the result lane at run
 * time; actuals live in the service per-lane. Ambitions:
 * - one rerun per draw per hour (queue uniqueness),
 * - no rerun once Matched (nothing to re-assert until something moves),
 * - every pass produces a pronouncement in the log.
 */
final class ReconcileCompletedDrawJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $drawId,
    ) {
        $this->onQueue(QueueName::Reconciliation->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'reconcile_completed_draw_'.$this->drawId.'_'.now()->format('Y-m-d-H');
    }

    /**
     * @return array{reconciled: bool, matched: bool, drift_lines: int, skipped: ?string}
     */
    public function handle(DrawReconciliationService $reconciliations): array
    {
        $numbers = DrawCertificationService::winningNumbersSnapshot($this->drawId);

        if ($numbers === []) {
            Log::info('ReconcileCompletedDrawJob: no result on the ledger — skipped', [
                'draw_id' => $this->drawId,
            ]);

            return ['reconciled' => false, 'matched' => false, 'drift_lines' => 0, 'skipped' => 'no-result'];
        }

        // Expected totals from the result lane itself.
        $assertedWinners = (string) WinningNumber::query()
            ->where('draw_id', $this->drawId)
            ->sum('total_winners');

        $assertedPayout = (string) WinningNumber::query()
            ->where('draw_id', $this->drawId)
            ->sum('total_payout');

        try {
            $result = $reconciliations->reconcile(DrawReconciliationData::fromInput(
                drawId: $this->drawId,
                winningNumbers: $numbers,
                expectedWinners: $assertedWinners,
                expectedPayout: $assertedPayout,
                expectedPrizeSettled: $assertedPayout,
                expectedPayoutsPaid: $assertedPayout,
            ));

            if ($result['matched']) {
                Log::info('ReconcileCompletedDrawJob: all lanes matched', [
                    'draw_id' => $this->drawId,
                ]);
            } else {
                Log::error('ReconcileCompletedDrawJob: DRIFT pronounced', [
                    'draw_id' => $this->drawId,
                    'drift_lines' => $result['drift_lines'],
                ]);
            }

            return [
                'reconciled' => true,
                'matched' => (bool) $result['matched'],
                'drift_lines' => (int) $result['drift_lines'],
                'skipped' => null,
            ];
        } catch (DrawReconciliationException $e) {
            Log::info('ReconcileCompletedDrawJob: refusal, not silence', [
                'draw_id' => $this->drawId,
                'reason' => $e->errorCode(),
            ]);

            return ['reconciled' => false, 'matched' => false, 'drift_lines' => 0, 'skipped' => $e->errorCode()];
        }
    }
}
