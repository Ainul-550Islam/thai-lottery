<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\DTOs\Payment\PaymentProcessingResult;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\AuditAction;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Services\Payment\PaymentWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Asynchronous Payment Webhook Processing Job.
 *
 * GUARANTEES:
 * 1. Cryptographic Isolation: Signature is verified synchronously before dispatching.
 * 2. Deduplication & Idempotency: Checked via atomic cache and database unique constraints.
 * 3. Double-Entry Accounting: Deposit success credits wallet with balanced double-entry ledger postings.
 * 4. Error Discrimination: Business validation failures (amount/currency mismatch) fail permanently; transient DB errors retry.
 * 5. Secret Protection: Does NOT carry raw webhook secrets in payload.
 */
class ProcessPaymentWebhookJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum retry attempts before permanent failure.
     */
    public int $tries = 3;

    /**
     * Exponential backoff delays in seconds (5s, 15s, 60s).
     *
     * @var list<int>
     */
    public array $backoff = [5, 15, 60];

    /**
     * Execution timeout in seconds.
     */
    public int $timeout = 60;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 180;

    public function __construct(
        public readonly WebhookPayload $payload,
    ) {
        $this->onQueue(QueueName::Webhooks->value);
        $this->afterCommit();
    }

    /**
     * Concurrency lock key based on gateway and event ID.
     */
    public function uniqueId(): string
    {
        return 'process_webhook_'.$this->payload->gateway.'_'.$this->payload->eventId;
    }

    /**
     * Execute the webhook processing job.
     */
    public function handle(PaymentWebhookService $webhookService): PaymentProcessingResult
    {
        try {
            return $webhookService->processWebhookPayload($this->payload);
        } catch (FinancialException $e) {
            Log::error('ProcessPaymentWebhookJob: Permanent FinancialException encountered', [
                'gateway' => $this->payload->gateway,
                'event_id' => $this->payload->eventId,
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return PaymentProcessingResult::failed($this->payload->gateway, $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('ProcessPaymentWebhookJob: Transient error during webhook processing, queue will retry', [
                'gateway' => $this->payload->gateway,
                'event_id' => $this->payload->eventId,
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
        Log::critical('ProcessPaymentWebhookJob: Job failed permanently after maximum attempts', [
            'gateway' => $this->payload->gateway,
            'event_id' => $this->payload->eventId,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        $log = new AuditLog();
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Deposit,
            'risk_level' => RiskLevel::High,
            'auditable_type' => AuditLog::class,
            'auditable_id' => 0,
            'description' => sprintf(
                'ProcessPaymentWebhookJob failed permanently for %s event %s after %d attempts: %s',
                strtoupper($this->payload->gateway),
                $this->payload->eventId,
                $this->attempts(),
                $exception->getMessage(),
            ),
            'metadata' => [
                'gateway' => $this->payload->gateway,
                'event_id' => $this->payload->eventId,
                'event_type' => $this->payload->eventType->value,
                'provider_reference' => $this->payload->providerReference,
                'internal_reference' => $this->payload->internalReference,
                'attempts' => $this->attempts(),
                'error' => $exception->getMessage(),
            ],
        ]);
        $log->save();
    }
}
