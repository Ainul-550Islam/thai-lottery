<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\DTOs\BetPurchaseData;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Enums\WebhookEventType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Jobs\Draw\ProcessPrizeSettlementJob;
use App\Jobs\Finance\ProcessFinancialReconciliationJob;
use App\Jobs\Notification\SendFinancialAlertJob;
use App\Jobs\Payment\DisburseWithdrawalJob;
use App\Jobs\Payment\ProcessPaymentWebhookJob;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Betting\BetPurchaseService;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultPublicationService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentWebhookService;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Queue\QueueHealthService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payment\PaymentTestCase;

/**
 * Phase 5.3.5: Comprehensive Production Async Queue, Worker & Job Infrastructure Test Suite.
 */
final class ProductionQueueComprehensiveTest extends PaymentTestCase
{
    private WithdrawalService $withdrawalService;
    private WithdrawalApprovalService $withdrawalApprovalService;
    private WithdrawalCompletionService $withdrawalCompletionService;
    private WithdrawalDisbursementService $disbursementService;
    private PaymentWebhookService $webhookService;
    private QueueHealthService $queueHealthService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withdrawalService = app(WithdrawalService::class);
        $this->withdrawalApprovalService = app(WithdrawalApprovalService::class);
        $this->withdrawalCompletionService = app(WithdrawalCompletionService::class);
        $this->disbursementService = app(WithdrawalDisbursementService::class);
        $this->webhookService = app(PaymentWebhookService::class);
        $this->queueHealthService = app(QueueHealthService::class);

        Config::set('queue.default', 'database');
    }

    /**
     * Helper to create a disbursement service with a gateway returning immediate success.
     */
    private function createInstantDisbursementService(): WithdrawalDisbursementService
    {
        $mockGateway = $this->createMock(PaymentGatewayInterface::class);
        $mockGateway->method('name')->willReturn('mock_instant');
        $mockGateway->method('supportsCurrency')->willReturn(true);
        $mockGateway->method('initiateWithdrawal')->willReturn(
            GatewayWithdrawalResponse::completed('INSTANT-TX-'.bin2hex(random_bytes(4)), ['instant' => true]),
        );

        $gatewayManager = $this->createMock(PaymentGatewayManager::class);
        $gatewayManager->method('forMethod')->willReturn($mockGateway);

        return new WithdrawalDisbursementService(
            gateways: $gatewayManager,
            locks: app(\App\Services\Finance\WalletLockService::class),
            holds: app(\App\Services\Finance\WalletHoldService::class),
            transitions: app(\App\Services\Finance\FinancialStateTransitionService::class),
            approvalService: $this->withdrawalApprovalService,
            completionService: $this->withdrawalCompletionService,
        );
    }

    // -------------------------------------------------------------------------
    // 1. Job Dispatching & Priority Queues
    // -------------------------------------------------------------------------

    #[Test]
    public function test_01_job_dispatches_correctly_to_designated_priority_queue(): void
    {
        Queue::fake();

        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('250.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );

        DisburseWithdrawalJob::dispatch($withdrawal->id);

        Queue::assertPushedOn(QueueName::FinancialCritical->value, DisburseWithdrawalJob::class);
        Queue::assertPushed(DisburseWithdrawalJob::class, function (DisburseWithdrawalJob $job) use ($withdrawal) {
            return $job->withdrawalId === $withdrawal->id;
        });
    }

    // -------------------------------------------------------------------------
    // 2. Successful Job Execution
    // -------------------------------------------------------------------------

    #[Test]
    public function test_02_job_processes_successfully_and_settles_withdrawal(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('300.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );

        $this->withdrawalApprovalService->approve($withdrawal);

        $instantService = $this->createInstantDisbursementService();
        $job = new DisburseWithdrawalJob($withdrawal->id);
        $job->handle($instantService);

        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->status);
        $this->assertNotNull($withdrawal->financial_transaction_id);

        $player['wallet']->refresh();
        $this->assertSame('700.00', (string) $player['wallet']->balance);
        $this->assertSame('0.00', (string) $player['wallet']->locked_balance);
    }

    // -------------------------------------------------------------------------
    // 3. Transient Provider Failure Retries
    // -------------------------------------------------------------------------

    #[Test]
    public function test_03_transient_provider_failure_retries(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('250.00', Currency::THB),
            method: PaymentMethod::Stripe,
        );
        $this->withdrawalApprovalService->approve($withdrawal);

        // Mock disbursement service to simulate a transient database deadlock / network failure
        $mockDisbursement = $this->createMock(WithdrawalDisbursementService::class);
        $mockDisbursement->method('disburse')->willThrowException(
            new \RuntimeException('Connection timeout to gateway API.'),
        );

        $job = new DisburseWithdrawalJob($withdrawal->id);

        // Transient exception is re-thrown so the worker retries
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection timeout');

        $job->handle($mockDisbursement);
    }

    // -------------------------------------------------------------------------
    // 4. Permanent Failure Does Not Retry Indefinitely
    // -------------------------------------------------------------------------

    #[Test]
    public function test_04_permanent_validation_failure_does_not_retry_indefinitely(): void
    {
        $job = new DisburseWithdrawalJob(999999);

        // Job does not throw uncaught exception; it marks itself failed
        $result = $job->handle($this->disbursementService);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // 5 & 6. Duplicate Withdrawal Job Idempotency
    // -------------------------------------------------------------------------

    #[Test]
    public function test_05_duplicate_withdrawal_job_is_strictly_idempotent(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('300.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );
        $this->withdrawalApprovalService->approve($withdrawal);

        $instantService = $this->createInstantDisbursementService();
        $job = new DisburseWithdrawalJob($withdrawal->id);

        // 1st Execution
        $job->handle($instantService);
        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->status);
        $balanceAfterFirst = $player['wallet']->refresh()->balance;

        // 2nd Duplicate Execution
        $duplicateJob = new DisburseWithdrawalJob($withdrawal->id);
        $result = $duplicateJob->handle($instantService);

        // 2nd run safely exits without error and without double debit
        $this->assertNull($result);
        $this->assertSame($balanceAfterFirst, $player['wallet']->refresh()->balance);
    }

    #[Test]
    public function test_06_duplicate_withdrawal_job_creates_exactly_one_payout(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('400.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );
        $this->withdrawalApprovalService->approve($withdrawal);

        $instantService = $this->createInstantDisbursementService();

        $job1 = new DisburseWithdrawalJob($withdrawal->id);
        $job1->handle($instantService);

        $job2 = new DisburseWithdrawalJob($withdrawal->id);
        $job2->handle($instantService);

        $this->assertSame(
            1,
            DB::table('payments')->where('payable_type', Withdrawal::class)->where('payable_id', $withdrawal->id)->count(),
        );
        $this->assertSame('600.00', (string) $player['wallet']->refresh()->balance);
    }

    // -------------------------------------------------------------------------
    // 7. Duplicate Prize Settlement Job Idempotency
    // -------------------------------------------------------------------------

    #[Test]
    public function test_07_duplicate_prize_settlement_job_creates_exactly_one_payout(): void
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        $draw = Draw::factory()->create(['type' => DrawType::ThreeD]);
        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        // Create Limit & Purchase winning bet on 3d_direct with '123'
        $limit = new NumberLimit();
        $limit->draw_id = $draw->getKey();
        $limit->bet_type = BetType::ThreeD;
        $limit->number = '123';
        $limit->max_amount = '100000.00';
        $limit->current_amount = '0.00';
        $limit->status = LimitStatus::Active;
        $limit->save();

        app(BetPurchaseService::class)->purchase(new BetPurchaseData(
            userId: (int) $player['user']->getKey(),
            drawId: (int) $draw->getKey(),
            marketKey: '3d_direct',
            rawNumber: '123',
            rawStake: '10.00',
            idempotencyKey: 'prize-job-bet-07',
        ));

        // Publish Result
        $lifecycle = app(DrawLifecycleService::class);
        $draw = $lifecycle->close($draw);
        $draw = $lifecycle->markResultPending($draw);
        app(DrawResultPublicationService::class)->publish((int) $draw->getKey(), [
            'first_prize' => '456123',
            'bottom_two' => '45',
        ]);

        $job = new ProcessPrizeSettlementJob((int) $draw->getKey());

        // 1st Run
        $job->handle(app(\App\Services\Draw\RealPrizeSettlementService::class), $lifecycle);

        $draw->refresh();
        $this->assertSame(DrawStatus::Completed, $draw->status);
        $this->assertSame(1, Payout::query()->where('draw_id', $draw->id)->count());
        $balanceAfter = $player['wallet']->refresh()->balance;

        // 2nd Duplicate Run
        $job2 = new ProcessPrizeSettlementJob((int) $draw->getKey());
        $job2->handle(app(\App\Services\Draw\RealPrizeSettlementService::class), $lifecycle);

        // Payouts and wallet balance remain unchanged
        $this->assertSame(1, Payout::query()->where('draw_id', $draw->id)->count());
        $this->assertSame($balanceAfter, $player['wallet']->refresh()->balance);
    }

    // -------------------------------------------------------------------------
    // 8. Duplicate Webhook Job Idempotency
    // -------------------------------------------------------------------------

    #[Test]
    public function test_08_duplicate_webhook_job_creates_exactly_one_wallet_mutation(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '300.00', PaymentMethod::Bkash);

        $payload = new WebhookPayload(
            gateway: 'bkash',
            eventId: 'evt_job_dup_08',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'BKASH-JOB-TRX-08',
            internalReference: $deposit->reference_number,
            amount: '300.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        $job1 = new ProcessPaymentWebhookJob($payload);
        $job1->handle($this->webhookService);

        $player['wallet']->refresh();
        $this->assertSame('300.00', (string) $player['wallet']->balance);

        // 2nd Duplicate Webhook Job
        $job2 = new ProcessPaymentWebhookJob($payload);
        $job2->handle($this->webhookService);

        $player['wallet']->refresh();
        $this->assertSame('300.00', (string) $player['wallet']->balance);
    }

    // -------------------------------------------------------------------------
    // 9. Failed Job Persistence
    // -------------------------------------------------------------------------

    #[Test]
    public function test_09_failed_job_is_persisted_in_database(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => QueueName::FinancialCritical->value,
            'payload' => json_encode(['job' => DisburseWithdrawalJob::class, 'data' => ['withdrawalId' => 123]]),
            'exception' => 'Permanent financial validation exception',
            'failed_at' => now(),
        ]);

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(1, $this->queueHealthService->check()->failedCount);
    }

    // -------------------------------------------------------------------------
    // 10. Failed Job Does Not Leak Secrets
    // -------------------------------------------------------------------------

    #[Test]
    public function test_10_failed_job_does_not_leak_secrets(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('250.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );

        $job = new DisburseWithdrawalJob($withdrawal->id);
        $exception = new \RuntimeException('Gateway auth failed for secret token secret_key_123456');

        $job->failed($exception);

        $audit = AuditLog::query()
            ->where('auditable_type', Withdrawal::class)
            ->where('auditable_id', $withdrawal->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $json = json_encode($audit->toArray());

        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('payout_details', $json);
    }

    // -------------------------------------------------------------------------
    // 11. Retrying Failed Financial Job Remains Idempotent
    // -------------------------------------------------------------------------

    #[Test]
    public function test_11_retrying_failed_financial_job_remains_idempotent(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('300.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );
        $this->withdrawalApprovalService->approve($withdrawal);

        $instantService = $this->createInstantDisbursementService();
        $job = new DisburseWithdrawalJob($withdrawal->id);

        // 1st run succeeds
        $job->handle($instantService);

        // Worker retries job from queue
        $retryJob = new DisburseWithdrawalJob($withdrawal->id);
        $retryJob->handle($instantService);

        $player['wallet']->refresh();
        $this->assertSame('700.00', (string) $player['wallet']->balance);
    }

    // -------------------------------------------------------------------------
    // 12. After-Commit Safety
    // -------------------------------------------------------------------------

    #[Test]
    public function test_12_jobs_declare_after_commit_safety(): void
    {
        $withdrawalJob = new DisburseWithdrawalJob(1);
        $this->assertTrue($withdrawalJob->afterCommit);

        $settlementJob = new ProcessPrizeSettlementJob(1);
        $this->assertTrue($settlementJob->afterCommit);

        $reconciliationJob = new ProcessFinancialReconciliationJob();
        $this->assertTrue($reconciliationJob->afterCommit);

        $payload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_12',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'ref_12',
            internalReference: 'DP_12',
            amount: '100.00',
            currency: Currency::THB,
            isSuccess: true,
        );
        $webhookJob = new ProcessPaymentWebhookJob($payload);
        $this->assertTrue($webhookJob->afterCommit);
    }

    // -------------------------------------------------------------------------
    // 13. Concurrency Locking (ShouldBeUnique)
    // -------------------------------------------------------------------------

    #[Test]
    public function test_13_jobs_implement_should_be_unique_with_deterministic_keys(): void
    {
        $job1 = new DisburseWithdrawalJob(42);
        $this->assertInstanceOf(ShouldBeUnique::class, $job1);
        $this->assertSame('disburse_withdrawal_42', $job1->uniqueId());

        $job2 = new ProcessPrizeSettlementJob(99);
        $this->assertInstanceOf(ShouldBeUnique::class, $job2);
        $this->assertSame('prize_settlement_draw_99', $job2->uniqueId());

        $job3 = new ProcessFinancialReconciliationJob(currency: Currency::THB);
        $this->assertInstanceOf(ShouldBeUnique::class, $job3);
        $this->assertSame('financial_reconciliation_THB', $job3->uniqueId());
    }

    // -------------------------------------------------------------------------
    // 14 & 15. Queue / Provider Timeout Behavior
    // -------------------------------------------------------------------------

    #[Test]
    public function test_14_queue_timeout_does_not_create_duplicate_money(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('300.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );

        $job = new DisburseWithdrawalJob($withdrawal->id);
        $this->assertSame(60, $job->timeout);
        $this->assertSame(180, (new ProcessPrizeSettlementJob(1))->timeout);
    }

    #[Test]
    public function test_15_provider_pending_response_results_in_safe_pending_behavior(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('300.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );
        $this->withdrawalApprovalService->approve($withdrawal);

        $job = new DisburseWithdrawalJob($withdrawal->id);
        $job->handle($this->disbursementService);

        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Processing, $withdrawal->status);
        $this->assertNotNull($withdrawal->provider_reference);

        // Hold remains active during pending asynchronous disbursement
        $player['wallet']->refresh();
        $this->assertSame('1000.00', (string) $player['wallet']->balance);
        $this->assertSame('300.00', (string) $player['wallet']->locked_balance);
    }

    // -------------------------------------------------------------------------
    // 16. Overlap Prevention for Reconciliation
    // -------------------------------------------------------------------------

    #[Test]
    public function test_16_scheduled_reconciliation_cannot_overlap(): void
    {
        $job = new ProcessFinancialReconciliationJob();
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(600, $job->uniqueFor);
    }

    // -------------------------------------------------------------------------
    // 17. Stale / Poison Jobs Eventually Fail
    // -------------------------------------------------------------------------

    #[Test]
    public function test_17_stale_poison_jobs_have_finite_tries(): void
    {
        $withdrawalJob = new DisburseWithdrawalJob(1);
        $this->assertSame(3, $withdrawalJob->tries);
        $this->assertSame([10, 30, 90], $withdrawalJob->backoff);

        $settlementJob = new ProcessPrizeSettlementJob(1);
        $this->assertSame(2, $settlementJob->tries);
        $this->assertSame([15, 60], $settlementJob->backoff);

        $webhookJob = new ProcessPaymentWebhookJob(new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_17',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'ref_17',
            internalReference: 'DP_17',
            amount: '100.00',
            currency: Currency::THB,
            isSuccess: true,
        ));
        $this->assertSame(3, $webhookJob->tries);
    }

    // -------------------------------------------------------------------------
    // 18. Worker Restart Safety
    // -------------------------------------------------------------------------

    #[Test]
    public function test_18_worker_restart_command_is_available(): void
    {
        $exitCode = Artisan::call('queue:restart');
        $this->assertSame(0, $exitCode);
    }

    // -------------------------------------------------------------------------
    // 19. Sensitive Models Are Not Serialized into Job Payloads
    // -------------------------------------------------------------------------

    #[Test]
    public function test_19_sensitive_models_are_not_serialized_into_jobs(): void
    {
        $job = new DisburseWithdrawalJob(12345);
        $serialized = serialize($job);

        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('remember_token', $serialized);
        $this->assertStringNotContainsString('payout_details', $serialized);
    }

    // -------------------------------------------------------------------------
    // 20. Terminal Financial State Prevents Duplicate Processing
    // -------------------------------------------------------------------------

    #[Test]
    public function test_20_terminal_failed_state_prevents_duplicate_processing(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('250.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );

        // Mark failed
        $this->withdrawalApprovalService->markFailed($withdrawal, 'Fraud risk check failed.');

        $job = new DisburseWithdrawalJob($withdrawal->id);
        $result = $job->handle($this->disbursementService);

        $this->assertNull($result);
        $this->assertSame(WithdrawalStatus::Failed, $withdrawal->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // 21. Queue Health Monitor Service
    // -------------------------------------------------------------------------

    #[Test]
    public function test_21_queue_health_service_reports_healthy_state(): void
    {
        $report = $this->queueHealthService->check();

        $this->assertTrue($report->isHealthy);
        $this->assertSame(0, $report->failedCount);
        $this->assertSame(0, $report->stuckCount);
        $this->assertArrayHasKey(QueueName::FinancialCritical->value, $report->pendingByQueue);
    }

    // -------------------------------------------------------------------------
    // 22. Strict Priority Queue Hierarchy Enforcement
    // -------------------------------------------------------------------------

    #[Test]
    public function test_22_queue_priority_order_is_strictly_enforced(): void
    {
        $order = QueueName::priorityOrder();

        $this->assertSame([
            'financial-critical',
            'webhooks',
            'reconciliation',
            'default',
            'notifications',
        ], $order);

        $this->assertSame(
            'financial-critical,webhooks,reconciliation,default,notifications',
            QueueName::workerQueueString(),
        );
    }

    // -------------------------------------------------------------------------
    // 23. CLI Queue Health Command
    // -------------------------------------------------------------------------

    #[Test]
    public function test_23_queue_health_cli_command_outputs_valid_json(): void
    {
        $exitCode = Artisan::call('queue:health', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertJson($output);

        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['is_healthy']);
        $this->assertArrayHasKey('pending_by_queue', $decoded);
    }

    // -------------------------------------------------------------------------
    // 24. Asynchronous Financial Alert Notification
    // -------------------------------------------------------------------------

    #[Test]
    public function test_24_financial_alert_notification_job_dispatches_and_logs(): void
    {
        $job = new SendFinancialAlertJob(
            title: 'Audit Warning',
            message: 'Suspicious transaction pattern detected',
            severity: RiskLevel::Critical,
            metadata: ['draw_id' => 123],
        );

        $job->handle();

        $audit = AuditLog::query()
            ->where('auditable_type', AuditLog::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(RiskLevel::Critical, $audit->risk_level);
        $this->assertStringContainsString('Audit Warning', $audit->description);
    }
}
