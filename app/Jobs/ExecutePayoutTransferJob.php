<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Payment\PayoutTransferData;
use App\Enums\AuditAction;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizePayoutMethod;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Exceptions\PayoutTransferException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opens the outbound transfer lane for every approved payout in a batch's
 * membership — the hand-off from "batch says these may pay" to "the
 * transfer service recorded they are paying".
 *
 * WHAT IT DOES — AND DELEGATES
 * ----------------------------
 * The job SELECTS and delegates; {@see PayoutTransferService} does every
 * money-respecting step (gateway/session record, drift guards, state
 * guards, idempotency by anchor). The job itself takes no wallet and
 * touches no ledger.
 *
 * WHO IS SELECTED
 * ---------------
 * Payouts whose metadata.batch.batch_key names the batch this job runs
 * for, whose row status is still Pending, whose approval lane is Approved
 * (the four-eyes gate must have landed before money moves), and who have
 * NO open transfer lane yet — a rerun of this job is therefore a no-op
 * for rows already opened, making the job idempotent by selection.
 *
 * EXTERNAL METHODS ONLY
 * ---------------------
 * The claim's chosen payout method (payout.metadata.claim.payout_method)
 * is consulted: wallet-method members skip the external lane entirely —
 * the internal wallet executor credits those. Bank-transfer and cheque
 * members get a transfer lane each.
 *
 * RETRY SAFETY
 * ------------
 * One failing member collects into the failure list and never stops the
 * run; anchors derived by the DTO mean the retried member lands back on
 * its own lane even across worker restarts.
 */
class ExecutePayoutTransferJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum retry attempts before failing permanently.
     */
    public int $tries = 3;

    /**
     * Exponential backoff delays in seconds.
     *
     * @var list<int>
     */
    public array $backoff = [15, 60, 180];

    /**
     * Execution timeout in seconds.
     */
    public int $timeout = 180;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    /**
     * @param  string  $batchKey  The batch whose membership this run
     *                            executes transfers for.
     * @param  int|null  $chunkSize  Members per run (bounded for backlog).
     */
    public function __construct(
        public readonly string $batchKey,
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for the concurrency lock: one transfer run per
     * batch per minute at most.
     */
    public function uniqueId(): string
    {
        return 'execute_payout_transfer_'.$this->batchKey.'_'.now()->format('Y-m-d-H-i');
    }

    /**
     * Run the transfer-open pass over the batch's membership.
     *
     * @return array{selected: int, opened: int, skipped_wallet: int, failed: int, failures: list<array<string, mixed>>, executed_at: string}
     */
    public function handle(PayoutTransferService $service): array
    {
        Log::info('ExecutePayoutTransferJob: opening transfer lanes', [
            'batch_key' => $this->batchKey,
            'attempt' => $this->attempts(),
        ]);

        $selected = 0;
        $opened = 0;
        $skippedWallet = 0;
        $failed = 0;
        $failures = [];

        $query = Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->where('status', PayoutStatus::Pending->value)
            ->whereRaw("json_extract(metadata, '$.batch.batch_key') = ?", [$this->batchKey])
            ->whereRaw("json_extract(metadata, '$.approval.status') = ?", [PayoutApprovalStatus::Approved->value])
            ->whereRaw("json_extract(metadata, '$.transfer.lane_status') IS NULL")
            ->orderBy('id');

        if (is_int($this->chunkSize) && $this->chunkSize > 0) {
            $query->limit($this->chunkSize);
        }

        foreach ($query->get() as $payout) {
            $selected++;

            $claim = is_array($payout->metadata['claim'] ?? null) ? $payout->metadata['claim'] : [];
            $methodValue = (string) ($claim['payout_method'] ?? 'bank_transfer');
            $method = PrizePayoutMethod::tryFrom($methodValue) ?? PrizePayoutMethod::BankTransfer;

            if ($method === PrizePayoutMethod::Wallet) {
                $skippedWallet++;

                continue;
            }

            $data = new PayoutTransferData(
                batchKey: $this->batchKey,
                payoutReference: (string) $payout->reference_number,
                beneficiaryUserId: (int) $payout->user_id,
                method: $method,
                amount: (string) $payout->amount,
                currency: $payout->currency,
                beneficiary: is_array($claim['beneficiary'] ?? null) ? $claim['beneficiary'] : null,
                idempotencyKey: PayoutTransferData::deriveIdempotencyKey(
                    $this->batchKey,
                    (string) $payout->reference_number,
                    (int) $payout->user_id,
                    (string) $payout->amount,
                    $payout->currency,
                ),
                context: ['source' => 'execute-payout-transfer-job'],
            );

            try {
                $service->transfer($data);
                $opened++;
            } catch (PayoutTransferException $e) {
                $failed++;
                $failures[] = [
                    'payout_id' => (int) $payout->getKey(),
                    'payout_reference' => (string) $payout->reference_number,
                    'code' => $e->errorCode(),
                ];

                Log::warning('ExecutePayoutTransferJob: member refused by transfer service', [
                    'payout_id' => (int) $payout->getKey(),
                    'code' => $e->errorCode(),
                ]);
            }
        }

        $summary = [
            'selected' => $selected,
            'opened' => $opened,
            'skipped_wallet' => $skippedWallet,
            'failed' => $failed,
            'failures' => $failures,
            'executed_at' => now()->toIso8601String(),
        ];

        Log::info('ExecutePayoutTransferJob: pass complete', [
            'batch_key' => $this->batchKey,
            'selected' => $selected,
            'opened' => $opened,
            'skipped_wallet' => $skippedWallet,
            'failed' => $failed,
        ]);

        if ($selected > 0) {
            $this->recordSummary($summary);
        }

        return $summary;
    }

    /**
     * A thrown run is retried by the queue; per-member anchors make the
     * retry join already-opened lanes instead of re-opening them.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ExecutePayoutTransferJob: run failed', [
            'batch_key' => $this->batchKey,
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * @param  array{selected: int, opened: int, skipped_wallet: int, failed: int}  $summary
     */
    private function recordSummary(array $summary): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => 'payout_transfer_run',
            'auditable_id' => 0,
            'description' => sprintf(
                'Payout transfer run for batch [%s]: %d selected, %d opened, %d internal-wallet skipped, %d failed.',
                $this->batchKey,
                $summary['selected'],
                $summary['opened'],
                $summary['skipped_wallet'],
                $summary['failed'],
            ),
            'metadata' => [
                'batch_key' => $this->batchKey,
                'selected' => $summary['selected'],
                'opened' => $summary['opened'],
                'skipped_wallet' => $summary['skipped_wallet'],
                'failed' => $summary['failed'],
                'action' => 'payout_transfer_run',
            ],
        ]);

        $log->save();
    }
}
