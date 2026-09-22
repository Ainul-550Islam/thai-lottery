<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Services\Withdrawal\WithdrawalKycGateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The LAST gate before money-out becomes irreversible: re-verifies the
 * withdrawal's KYC standing before the approval lane lets it enter
 * Processing.
 *
 * WHY A JOB AND NOT AN INLINE CHECK
 * ---------------------------------
 * The approval gesture and the irreversible moment can be SECONDS apart:
 * an operator approves, the queue dispatches Processing, and between the
 * two the requester's KYC evidence may have been expired by its document
 * lane or freshly verified after a detention. Money leaving under stale
 * KYC is the exact AML finding this gate exists to prevent, so the check
 * MUST rerun at the cliff edge, not trust the approval-time verdict.
 *
 * WHAT IT DOES
 * ------------
 * Delegates every question to {@see WithdrawalKycGateService}:
 *   gate passes        → the withdrawal is free to proceed (the event
 *                        fired inside the gate lands its audit line).
 *   gate detains       → the withdrawal was moved to KycRequired and the
 *                        processing run MUST drop it: the job's return
 *                        value is the whole integration contract — the
 *                        dispatcher consults 'detention' and skips the
 *                        withdrawal instead of processing it.
 *   gate disabled/missing → the job refuses the pass when the gate was
 *                        REQUIRED: paused check-ins are better than
 *                        silent pass-throughs, and operations sees the
 *                        refusal in the log line this job writes.
 *
 * RETRY AND IDEMPOTENCY
 * ---------------------
 * Invoking the job N times is the same as invoking it once: the gate
 * service's own stamped verdicts make replays no-ops (same anchor →
 * re-serve, no row mutation, no event redispatch).
 */
class VerifyWithdrawalKycJob implements ShouldBeUnique, ShouldQueue
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
    public array $backoff = [10, 60, 180];

    /**
     * Execution timeout in seconds.
     */
    public int $timeout = 60;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $withdrawalId,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier: one gate run per withdrawal per minute.
     */
    public function uniqueId(): string
    {
        return 'verify_withdrawal_kyc_'.$this->withdrawalId.'_'.now()->format('Y-m-d-H-i');
    }

    /**
     * Run the recheck.
     *
     * @return array{verdict: string, anchor: string|null, detention: bool}
     */
    public function handle(WithdrawalKycGateService $gate): array
    {
        Log::info('VerifyWithdrawalKycJob: running cliff-edge KYC recheck', [
            'withdrawal_id' => $this->withdrawalId,
            'attempt' => $this->attempts(),
        ]);

        $result = $gate->recheck($this->withdrawalId);

        Log::info('VerifyWithdrawalKycJob: recheck verdict', [
            'withdrawal_id' => $this->withdrawalId,
            'verdict' => $result['verdict'],
            'detention' => $result['detention'],
        ]);

        return $result;
    }

    /**
     * A thrown recheck never lets a withdrawal drift into Processing
     * half-verified: the dispatcher receives nothing, the job retry loop
     * reruns, and the operational log names the exact fault.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('VerifyWithdrawalKycJob: cliff-edge recheck FAILED', [
            'withdrawal_id' => $this->withdrawalId,
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }
}
