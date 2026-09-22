<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Payment\PaymentWebhookData;
use App\Exceptions\PaymentWebhookException;
use App\Http\Responses\ApiResponse;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Services\Payment\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public Webhook endpoint for inbound payment gateway notifications.
 */
final class PaymentWebhookController
{
    public function __construct(
        private readonly PaymentWebhookService $webhookService,
    ) {
    }

    /**
     * Handle incoming payment gateway webhook.
     */
    public function handle(string $gateway, Request $request): JsonResponse
    {
        try {
            $result = $this->webhookService->handleWebhookRequest($gateway, $request);

            if (! $result->success) {
                if ($result->message === 'Invalid webhook signature.') {
                    return ApiResponse::error(
                        code: 'invalid_signature',
                        message: 'Invalid webhook signature.',
                        status: 403,
                    );
                }

                return ApiResponse::error(
                    code: 'webhook_processing_failed',
                    message: $result->message ?? 'Webhook processing failed.',
                    status: 422,
                );
            }

            return ApiResponse::success(
                data: $result->toArray(),
                message: 'Webhook processed successfully.',
            );
        } catch (\Throwable $e) {
            Log::error('Unhandled webhook exception', [
                'gateway' => $gateway,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ApiResponse::error(
                code: 'webhook_processing_error',
                message: 'An error occurred while processing the webhook.',
                status: 500,
            );
        }
    }

    /**
     * Hardened envelope ingress (batch-12): signature verification
     * BEFORE any financial mutation, persistence-first evidence rows,
     * exactly-once by payload fingerprint, and async application
     * dispatched to the queue. The legacy handle() above is untouched.
     */
    public function receive(string $gateway, Request $request): JsonResponse
    {
        $gateway = strtolower(trim($gateway));

        $payload = $request->json()->all();

        if ($payload === []) {
            $payload = $request->all();
        }

        $signature = $request->header('X-Signature')
            ?? $request->header('X-Webhook-Signature')
            ?? $request->header('Stripe-Signature');

        try {
            $envelope = PaymentWebhookData::fromInput(
                providerCode: $gateway,
                eventId: (string) ($payload['id'] ?? $payload['event_id'] ?? $request->header('X-Event-Id') ?? ''),
                eventType: (string) ($payload['type'] ?? $payload['event_type'] ?? $request->header('X-Event-Type') ?? 'unknown'),
                signature: is_string($signature) ? $signature : null,
                receivedAtIso: now()->toIso8601String(),
                payload: is_array($payload) ? $payload : [],
            );

            ['webhook' => $webhook, 'outcome' => $outcome] = $this->webhookService->processEnvelope($envelope);

            // Async application is replay-safe at every layer; dispatch
            // regardless of inline outcome so the job stands as the
            // persistent applicator of record.
            if ($outcome === 'verified' || $outcome === 'applied') {
                ProcessPaymentWebhookJob::dispatch((int) $webhook->id);
            }

            return ApiResponse::success(
                data: [
                    'webhook_key' => $webhook->webhook_key,
                    'outcome' => $outcome,
                    'status' => $webhook->status->value,
                    'sightings' => (int) $webhook->sightings,
                ],
                message: 'Webhook received.',
                status: $outcome === 'duplicate' ? 200 : 202,
            );
        } catch (PaymentWebhookException $e) {
            return ApiResponse::error(
                code: strtolower($e->errorCode()),
                message: $e->getMessage(),
                status: $e->errorCode() === PaymentWebhookException::CODE_SIGNATURE_INVALID ? 403 : 422,
            );
        }
    }
}
