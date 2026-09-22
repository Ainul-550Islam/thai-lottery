<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\Enums\AuditAction;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Models\AuditLog;
use App\Models\Withdrawal;
use App\Services\Payment\WithdrawalDisbursementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Asynchronous Outbound Withdrawal Disbursement Job.
 *
 * GUARANTEES:
 * 1. Financial Idempotency: Executes via 3-phase disbursement orchestrator.
 * 2. Concurrency Safety: ShouldBeUnique lock keyed on withdrawal ID prevents duplicate worker collisions.
 * 3. Terminal State Safety: Final withdrawal states (Completed, Failed, Cancelled) exit immediately without re-disbursing.
 * 4. Error Discrimination: Transient network timeouts retry with exponential backoff; permanent validation errors fail immediately.
 * 5. Secret Protection: Carries only withdrawal ID; never serializes bank credentials or auth tokens into queue payloads.
 */
class DisburseWithdrawalJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum retry attempts before giving up.
     */
    public int $tries = 3;

    /**
     * Exponential backoff delays in seconds (10s, 30s, 90s).
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 90];

    /**
     * Maximum execution time in seconds.
     */
    public int $timeout = 60;

    /**
     * Unique lock TTL in seconds.
     */
    public int $uniqueFor = 120;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly int $withdrawalId,
        public readonly array $options = [],
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    /**
     * Deterministic lock identifier to prevent concurrent duplicate execution.
     */
    public function uniqueId(): string
    {
        return 'disburse_withdrawal_'.$this->withdrawalId;
    }

    /**
     * Execute the disbursement job.
     */
    public function handle(WithdrawalDisbursementService $disbursementService): ?GatewayWithdrawalResponse
    {
        $withdrawal = Withdrawal::query()->find($this->withdrawalId);

        if (! $withdrawal instanceof Withdrawal) {
            Log::error('DisburseWithdrawalJob: Withdrawal not found', [
                'withdrawal_id' => $this->withdrawalId,
                'attempt' => $this->attempts(),
            ]);

            $this->fail(new \RuntimeException(sprintf('Withdrawal #%d not found.', $this->withdrawalId)));

            return null;
        }

        // Terminal State Guard: Do NOT re-process if already in a terminal state
        if ($withdrawal->status === WithdrawalStatus::Completed) {
            Log::info('DisburseWithdrawalJob: Withdrawal already completed, skipping redundant disbursement', [
                'withdrawal_id' => $this->withdrawalId,
                'reference_number' => $withdrawal->reference_number,
                'status' => $withdrawal->status->value,
            ]);

            return null;
        }

        if ($withdrawal->status === WithdrawalStatus::Failed
            || $withdrawal->status === WithdrawalStatus::Cancelled
            || $withdrawal->status === WithdrawalStatus::Rejected) {
            Log::warning('DisburseWithdrawalJob: Withdrawal in terminal failed/cancelled state, aborting job', [
                'withdrawal_id' => $this->withdrawalId,
                'reference_number' => $withdrawal->reference_number,
                'status' => $withdrawal->status->value,
            ]);

            return null;
        }

        try {
            return $disbursementService->disburse($withdrawal, $this->options);
        } catch (WithdrawalException $e) {
            // Already completed is benign idempotency. WithdrawalException (which
            // extends FinancialException) exposes the code through errorCode();
            // getErrorCode() never existed and would fatal on this path.
            if ($e->errorCode() === WithdrawalException::ERROR_ALREADY_COMPLETED) {
                Log::info('DisburseWithdrawalJob: Caught withdrawal_already_completed, treated as idempotent success', [
                    'withdrawal_id' => $this->withdrawalId,
                ]);

                return null;
            }

            // Permanent business errors should fail immediately without retrying
            Log::error('DisburseWithdrawalJob: Permanent WithdrawalException encountered', [
                'withdrawal_id' => $this->withdrawalId,
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return null;
        } catch (FinancialException $e) {
            // Permanent financial errors (e.g. unsupported currency, lock violation)
            Log::error('DisburseWithdrawalJob: Permanent FinancialException encountered', [
                'withdrawal_id' => $this->withdrawalId,
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return null;
        } catch (Throwable $e) {
            // Transient network/gateway communication errors will retry up to $this->tries
            Log::warning('DisburseWithdrawalJob: Transient error during disbursement, queue will retry', [
                'withdrawal_id' => $this->withdrawalId,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle job permanent failure when attempts are exhausted.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('DisburseWithdrawalJob: Job permanently failed after max attempts', [
            'withdrawal_id' => $this->withdrawalId,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        $withdrawal = Withdrawal::query()->find($this->withdrawalId);

        if ($withdrawal instanceof Withdrawal) {
            $log = new AuditLog;
            $log->fill([
                'user_id' => null,
                'action' => AuditAction::Withdraw,
                'risk_level' => RiskLevel::High,
                'auditable_type' => Withdrawal::class,
                'auditable_id' => $withdrawal->id,
                'description' => sprintf(
                    'DisburseWithdrawalJob failed permanently for Withdrawal %s after %d attempts: %s',
                    $withdrawal->reference_number,
                    $this->attempts(),
                    $exception->getMessage(),
                ),
                'metadata' => [
                    'withdrawal_id' => $withdrawal->id,
                    'reference_number' => $withdrawal->reference_number,
                    'attempts' => $this->attempts(),
                    'error' => $exception->getMessage(),
                ],
            ]);
            $log->save();
        }
    }
}
