<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PaymentWebhookException;
use App\Models\PaymentWebhook;
use App\Services\Payment\PaymentWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async application of a PERSISTED webhook envelope.
 *
 * The envelope is custody of the house by the time this job runs, so
 * the service re-proves the signature against the STORED bytes
 * (window relaxed) and applies the facts — exactly-once because the
 * row's own Applied pronunciation is terminal and idempotent completion
 * lanes underneath never double the money.
 */
final class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $paymentWebhookId,
    ) {
        $this->onQueue('finance-webhooks');
    }

    public function handle(PaymentWebhookService $webhooks): void
    {
        $webhook = PaymentWebhook::query()->find($this->paymentWebhookId);

        if (! $webhook instanceof PaymentWebhook) {
            return; // evidence withdrawn; nothing to do
        }

        try {
            $webhooks->applyPersisted($webhook);
        } catch (PaymentWebhookException $e) {
            // A refused envelope is a fact (not a retryable fault): it
            // stays Verified/Rejected with its reason; log loudly once
            // here so monitors see the refusal.
            Log::warning('Persisted payment webhook refused application.', [
                'webhook' => $webhook->webhook_key,
                'error' => $e->errorCode(),
            ]);
        }
    }
}
