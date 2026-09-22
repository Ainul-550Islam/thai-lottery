<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\FinancialTransactionType;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Events\PrizePayoutCompleted;
use App\Exceptions\PayoutException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Observability\CorrelationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Asynchronous Payout Batch Job.
 *
 * GUARANTEES
 * ----------
 * 1. ONE PAYOUT = ONE CREDIT, EXACTLY ONCE. Every row in the batch is Paid
 *    through WalletService::credit under a payout-scoped idempotency key.
 *    Replaying this job with the same payouts re-runs only the rows that
 *    never completed; each Completed row is treated as already-paid and
 *    skipped (its wallet and ledger stay as posted by the first run).
 * 2. ATOMIC PER ROW, ISOLATED ACROSS ROWS. Each payout settles inside its
 *    own database transaction: a failing payout (rejected credit, tampered
 *    amount) moves itself to Failed with the reason, and in no way stops or
 *    compromises the rest of the batch — a 200-row batch never becomes a
 *    200-row all-or-nothing homing to gamble on.
 * 3. CONCURRENCY SAFE. ShouldBeUnique keyed on the batch tag prevents two
 *    workers sweeping the same process run; inside a worker the payout row
 *    is taken with SELECT ... FOR UPDATE, so even a double-dispatch of the
 *    SAME payout id across two workers results in one winner and one replay,
 *    not two credits.
 * 4. OBSERVED. Completed rows emit PrizePayoutCompleted for the players'
 *    surfaces, a per-batch audit line records what a run did, and a hard
 *    fail of the whole run lands in the critical channel with a payout count.
 */
class ProcessPayoutBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum retry attempts before failing permanently.
     */
    public int $tries = 2;

    /**
     * Exponential backoff delays in seconds (15s, 60s).
     *
     * @var list<int>
     */
    public array $backoff = [15, 60];

    /**
     * Execution timeout in seconds (200 payouts in worst-case one-failure loop).
     */
    public int $timeout = 300;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    /**
     * @param  int|null  $batchTag  Shared tag across the rows of one logical
     *                              batch (or null to sweep every eligible
     *                              pending row).
     * @param  list<int>|null  $payoutIds  Explicit row ids limiting the run
     *                                     (operator-preclaimed batch).
     */
    public function __construct(
        public readonly ?int $batchTag = null,
        public readonly ?array $payoutIds = null,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for the concurrency lock.
     */
    public function uniqueId(): string
    {
        return 'payout_batch_'.($this->batchTag ?? 'sweep_'.now()->format('Y-m-d-H-i'));
    }

    /**
     * Process one batch of pending payouts.
     *
     * @return array{
     *     processed: int,
     *     paid: int,
     *     failed: int,
     *     replayed: int,
     *     total_paid: string
     * }|null
     */
    public function handle(WalletService $walletService): ?array
    {
        $eligible = $this->eligiblePayouts();

        if ($eligible->isEmpty()) {
            Log::info('ProcessPayoutBatchJob: no eligible pending payouts for tag #{tag}', [
                'tag' => $this->batchTag,
            ]);

            return [
                'processed' => 0,
                'paid' => 0,
                'failed' => 0,
                'replayed' => 0,
                'total_paid' => '0.00',
            ];
        }

        $paid = 0;
        $failed = 0;
        $replayed = 0;
        $totalPaid = '0.00';

        foreach ($eligible as $payoutRow) {
            $outcome = $this->processOne($walletService, (int) $payoutRow->id);

            match ($outcome['result']) {
                'paid' => [$paid, $totalPaid] = [$paid + 1, bcadd($totalPaid, $outcome['amount'], 2)],
                'failed' => $failed++,
                'replayed' => $replayed++,
                default => null,
            };
        }

        $summary = [
            'processed' => $eligible->count(),
            'paid' => $paid,
            'failed' => $failed,
            'replayed' => $replayed,
            'total_paid' => $totalPaid,
        ];

        Log::info('ProcessPayoutBatchJob: batch complete', $summary + [
            'batch_tag' => $this->batchTag,
        ]);

        $this->recordBatchAudit($summary);

        return $summary;
    }

    /**
     * The rows this run claims: pending (never processed) or explicitly listed
     * eligible payouts, age-ordered, bounded by the configured batch size.
     * Rows already Completed are never candidates; the lock inside processOne
     * proves it one more time per row.
     */
    private function eligiblePayouts()
    {
        $query = Payout::query()
            ->whereNull('deleted_at')
            ->where('status', PayoutStatus::Pending->value)
            ->orderBy('id')
            ->limit((int) config('lottery.payouts.batch_size', 200));

        if ($this->payoutIds !== null && $this->payoutIds !== []) {
            $query->whereIn('id', $this->payoutIds);
        }

        return $query->get();
    }

    /**
     * Settle ONE payout inside its own transaction. All or nothing:
     * the credit, the marks and the event payload are all committed together,
     * or — on any error — the payout moves to Failed and the wallet/ledger
     * never moved.
     *
     * @return array{result: string, amount: string, reason?: string}
     */
    private function processOne(WalletService $walletService, int $payoutId): array
    {
        try {
            return DB::transaction(function () use ($walletService, $payoutId): array {
                /** @var Payout|null $payout */
                $payout = Payout::query()->lockForUpdate()->find($payoutId);

                if (! $payout instanceof Payout) {
                    throw PayoutException::notFound($payoutId);
                }

                // Completed already: replay the stored outcome, no re-credit.
                if ($payout->status === PayoutStatus::Completed) {
                    return [
                        'result' => 'replayed',
                        'amount' => (string) $payout->amount,
                    ];
                }

                if ($payout->status !== PayoutStatus::Pending) {
                    throw PayoutException::stateForbids(
                        $payoutId,
                        $payout->status->value,
                        'batch-processed',
                    );
                }

                $payout->status = PayoutStatus::Processing;
                $payout->save();

                $user = $payout->user instanceof User ? $payout->user : User::query()->find((int) $payout->user_id);

                if (! $user instanceof User) {
                    throw PayoutException::walletUnavailable($payoutId, (int) $payout->user_id, (string) $payout->currency->value);
                }

                $wallet = $payout->wallet;

                if ($wallet === null) {
                    throw PayoutException::walletUnavailable($payoutId, (int) $payout->user_id, (string) $payout->currency->value);
                }

                if ($wallet->currency !== $payout->currency) {
                    throw PayoutException::currencyMismatch(
                        $payoutId,
                        $payout->currency->value,
                        $wallet->currency->value,
                    );
                }

                // The credit: balanced double entry, idempotency keyed per
                // payout. A retry of THIS job replays the same key and gets
                // the same stored transaction, never a second credit. Wallet
                // itself re-locks the row inside the credit pipeline.
                $transaction = $walletService->credit(
                    wallet: $wallet,
                    amount: Money::fromDatabase((string) $payout->amount, $payout->currency),
                    type: FinancialTransactionType::Payout,
                    idempotencyKey: sprintf('payout-batch-%d', $payoutId),
                    options: [
                        'description' => sprintf('Prize payout %s (batch)', (string) $payout->reference_number),
                        'reference_type' => Payout::class,
                        'reference_id' => (int) $payout->getKey(),
                        'metadata' => [
                            'payout_id' => (int) $payout->getKey(),
                            'payout_reference' => (string) $payout->reference_number,
                            'batch_tag' => $this->batchTag,
                            'source' => 'payout_batch',
                        ],
                    ],
                );

                $payout->financial_transaction_id = (int) $transaction->getKey();
                $payout->status = PayoutStatus::Completed;
                $payout->processed_at = now();
                $payout->save();

                event(new PrizePayoutCompleted(
                    payout: $payout,
                    winner: $user,
                    correlationId: CorrelationContext::get(),
                ));

                return [
                    'result' => 'paid',
                    'amount' => (string) $payout->amount,
                ];
            });
        } catch (Throwable $exception) {
            // A single-row failure must not poison the batch. The payout is
            // stamped Failed with the reason so operations can review it, and
            // the loop continues with the next row.
            $this->failRow($payoutId, $exception);

            Log::warning('ProcessPayoutBatchJob: payout failed batch-level fail-per-row', [
                'payout_id' => $payoutId,
                'reason' => $exception->getMessage(),
                'batch_tag' => $this->batchTag,
            ]);

            return [
                'result' => 'failed',
                'amount' => '0.00',
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Stamp one payout Failed with the failure reason. Outside the rolling
     * transaction on purpose: the row's failure stamp must survive even when
     * the payout's own writes rolled back.
     */
    private function failRow(int $payoutId, Throwable $exception): void
    {
        try {
            $fresh = Payout::query()->find($payoutId);

            if (! $fresh instanceof Payout) {
                return;
            }

            if (! in_array($fresh->status, [PayoutStatus::Pending, PayoutStatus::Processing], true)) {
                return;
            }

            $fresh->status = PayoutStatus::Failed;
            $fresh->failed_at = now();
            $fresh->failure_reason = mb_substr($exception->getMessage(), 0, 500);
            $fresh->save();
        } catch (Throwable) {
            // The failure stamp itself failed; better to lose the stamp and
            // keep processing than to cascade the batch.
        }
    }

    /**
     * One audit line per run: what tag, how many paid/failed/replayed, and
     * the total moved. Completed batches should never trip alerts, so the
     * level is only Medium when failures happened and Low otherwise.
     *
     * @param  array{processed: int, paid: int, failed: int, replayed: int, total_paid: string}  $summary
     */
    private function recordBatchAudit(array $summary): void
    {
        if ($summary['processed'] === 0) {
            return;
        }

        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => $summary['failed'] > 0 ? RiskLevel::Medium : RiskLevel::Low,
            'auditable_type' => 'payout_batch',
            'auditable_id' => $this->batchTag ?? 0,
            'description' => sprintf(
                'Payout batch %s processed %d row(s): %d paid (%s), %d failed, %d replayed.',
                $this->batchTag !== null ? '#'.$this->batchTag : '(sweep)',
                $summary['processed'],
                $summary['paid'],
                $summary['total_paid'],
                $summary['failed'],
                $summary['replayed'],
            ),
            'metadata' => [
                'batch_tag' => $this->batchTag,
                'payout_ids' => $this->payoutIds,
                'processed' => $summary['processed'],
                'paid' => $summary['paid'],
                'failed' => $summary['failed'],
                'replayed' => $summary['replayed'],
                'total_paid' => $summary['total_paid'],
            ],
        ]);

        $log->save();
    }

    /**
     * Handle permanent job failure (max retries exhausted).
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ProcessPayoutBatchJob: job failed permanently after maximum attempts', [
            'batch_tag' => $this->batchTag,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::Critical,
            'auditable_type' => 'payout_batch',
            'auditable_id' => $this->batchTag ?? 0,
            'description' => sprintf(
                'Payout batch job %s failed permanently after %d attempts: %s',
                $this->batchTag !== null ? '#'.$this->batchTag : '(sweep)',
                $this->attempts(),
                $exception->getMessage(),
            ),
            'metadata' => [
                'batch_tag' => $this->batchTag,
                'attempts' => $this->attempts(),
                'error' => $exception->getMessage(),
            ],
        ]);
        $log->save();
    }
}
