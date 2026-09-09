<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
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
}
