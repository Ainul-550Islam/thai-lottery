<?php

declare(strict_types=1);

namespace App\Jobs\Draw;

use App\DTOs\SettlementResult;
use App\Enums\AuditAction;
use App\Enums\DrawStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\FinancialException;
use App\Exceptions\SettlementSimulationException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\RealPrizeSettlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Asynchronous Prize Settlement Job.
 *
 * GUARANTEES:
 * 1. Double-Entry Prize Settlement: Atomic prize calculation, payout creation, player wallet credit.
 * 2. Concurrency Safety: ShouldBeUnique keyed per draw prevents concurrent settlement workers.
 * 3. Idempotency: Replaying an already settled draw returns cached/stored results without duplicate payouts.
 * 4. After-Commit Dispatch: Never processes until preceding database commits have succeeded.
 * 5. Error Discrimination: Business validation failures fail permanently; transient DB errors retry.
 */
class ProcessPrizeSettlementJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     * Execution timeout in seconds (supports high volume draws).
     */
    public int $timeout = 180;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $drawId,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for concurrency lock.
     */
    public function uniqueId(): string
    {
        return 'prize_settlement_draw_'.$this->drawId;
    }

    /**
     * Execute the prize settlement.
     */
    public function handle(
        RealPrizeSettlementService $settlementService,
        DrawLifecycleService $lifecycleService,
    ): ?SettlementResult {
        $draw = Draw::query()->find($this->drawId);

        if (! $draw instanceof Draw) {
            Log::error('ProcessPrizeSettlementJob: Draw not found', [
                'draw_id' => $this->drawId,
                'attempt' => $this->attempts(),
            ]);

            $this->fail(new \RuntimeException(sprintf('Draw #%d not found.', $this->drawId)));

            return null;
        }

        // Check if already settled
        if ($draw->status === DrawStatus::Completed || $lifecycleService->currentState($draw)->isSettled()) {
            Log::info('ProcessPrizeSettlementJob: Draw is already settled, skipping redundant execution', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'status' => $draw->status->value,
            ]);

            return null;
        }

        try {
            return $settlementService->settle($this->drawId);
        } catch (SettlementSimulationException $e) {
            Log::error('ProcessPrizeSettlementJob: Permanent SettlementSimulationException encountered', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return null;
        } catch (DrawLifecycleException $e) {
            Log::error('ProcessPrizeSettlementJob: Permanent DrawLifecycleException encountered', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return null;
        } catch (FinancialException $e) {
            Log::error('ProcessPrizeSettlementJob: Permanent FinancialException encountered', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return null;
        } catch (Throwable $e) {
            Log::warning('ProcessPrizeSettlementJob: Transient error during settlement, queue will retry', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'attempt' => $this->attempts(),
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle permanent job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ProcessPrizeSettlementJob: Job failed permanently after maximum attempts', [
            'draw_id' => $this->drawId,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        $draw = Draw::query()->find($this->drawId);

        if ($draw instanceof Draw) {
            $log = new AuditLog;
            $log->fill([
                'user_id' => null,
                'action' => AuditAction::Payout,
                'risk_level' => RiskLevel::Critical,
                'auditable_type' => Draw::class,
                'auditable_id' => $draw->id,
                'description' => sprintf(
                    'ProcessPrizeSettlementJob failed permanently for Draw %s after %d attempts: %s',
                    $draw->draw_number,
                    $this->attempts(),
                    $exception->getMessage(),
                ),
                'metadata' => [
                    'draw_id' => $draw->id,
                    'draw_number' => $draw->draw_number,
                    'attempts' => $this->attempts(),
                    'error' => $exception->getMessage(),
                ],
            ]);
            $log->save();
        }
    }
}
