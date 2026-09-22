<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Prize\PrizeDisbursementData;
use App\Enums\PrizeDisbursementStatus;
use App\Enums\QueueName;
use App\Exceptions\PrizeDisbursementException;
use App\Models\Payout;
use App\Services\Prize\PrizeDisbursementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Executes ONLY approved/eligible prize disbursements.
 *
 * DISCIPLINE
 * - The claim/payout fingerprint is verified BEFORE reserving: a queued
 *   ask claims facts the court re-derives; disagreement is refused as
 *   PAYOUT_MISMATCH, never silently "adjusted".
 * - Approval and eligibility are re-checked AT RUN TIME. A queue entry
 *   from a pre-approval/pre-review world lands on the court's live
 *   answers, not on yesterday's.
 * - Idempotent: deterministic disbursement key; same ask replays.
 */
final class DisburseApprovedPrizeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 3600;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $payoutId,
        public readonly ?int $batchId = null,
    ) {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'disburse_prize_'.$this->payoutId.'_'.($this->batchId ?? 0).'_'.now()->format('Y-m-d-H');
    }

    /**
     * @return array{reserved: bool, disbursed: bool, replayed: bool, skipped: ?string}
     */
    public function handle(PrizeDisbursementService $disbursements): array
    {
        $fingerprint = PrizeDisbursementService::expectedFingerprint($this->payoutId);

        if ($fingerprint === null) {
            Log::info('DisburseApprovedPrizeJob: payout gone — skipped', ['payout_id' => $this->payoutId]);

            return ['reserved' => false, 'disbursed' => false, 'replayed' => false, 'skipped' => 'gone'];
        }

        /** @var Payout|null $payout */
        $payout = Payout::query()->find($this->payoutId);

        $currency = $payout->currency instanceof \BackedEnum
            ? (string) $payout->currency->value
            : (string) $payout->currency;

        $pack = PrizeDisbursementData::fromInput(
            payoutId: $this->payoutId,
            batchId: $this->batchId,
            amount: PrizeDisbursementService::moneyOf((string) $payout->amount),
            currency: $currency,
            settlementFingerprint: $fingerprint,
        );

        try {
            $reservation = $disbursements->reserve($pack, actorNote: sprintf('job:DisburseApprovedPrizeJob(payout %d)', $this->payoutId));

            if ($reservation['replayed']) {
                // Already reserved: try to finalize an un-disbursed row.
            }

            $disbursement = $reservation['disbursement'];
            $disbursed = false;

            if ($disbursement->status !== PrizeDisbursementStatus::Disbursed) {
                $disbursement = $disbursements->disburse($disbursement, actorNote: 'job:DisburseApprovedPrizeJob');
            }

            $disbursed = $disbursement->status === PrizeDisbursementStatus::Disbursed;

            Log::info('DisburseApprovedPrizeJob: settled', [
                'payout_id' => $this->payoutId,
                'replayed' => $reservation['replayed'],
                'disbursed' => $disbursed,
            ]);

            return [
                'reserved' => true,
                'disbursed' => $disbursed,
                'replayed' => (bool) $reservation['replayed'],
                'skipped' => null,
            ];
        } catch (PrizeDisbursementException $e) {
            Log::info('DisburseApprovedPrizeJob: refused, not silent', [
                'payout_id' => $this->payoutId,
                'reason' => $e->errorCode(),
            ]);

            return [
                'reserved' => false,
                'disbursed' => false,
                'replayed' => false,
                'skipped' => $e->errorCode(),
            ];
        }
    }
}
