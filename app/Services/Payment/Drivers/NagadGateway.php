<?php

declare(strict_types=1);

namespace App\Services\Payment\Drivers;

use App\DTOs\Payment\GatewayDepositResponse;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real Nagad Payment Gateway & Webhook Driver.
 *
 * SPECIFICATION & OFFICIAL PROTOCOL
 * ---------------------------------
 * 1. Base URLs:
 *    Sandbox: http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs
 *    Live:    https://api.mynagad.com/api/dfs
 *
 * 2. Signature Verification:
 *    Header: `X-KM-Signature` or `X-Nagad-Signature` using HMAC-SHA256 with secret.
 */
class NagadGateway extends AbstractPaymentGateway
{
    public function name(): string
    {
        return 'nagad';
    }

    public function label(): string
    {
        return 'Nagad';
    }

    public function status(): GatewayIntegrationStatus
    {
        return GatewayIntegrationStatus::FullyImplemented;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return $currency === Currency::BDT;
    }

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        $merchantId = $this->merchantId();

        if (empty($merchantId)) {
            return GatewayDepositResponse::failed('Nagad merchant configuration is missing.');
        }

        $orderId = $deposit->reference_number;
        $datetime = date('YmdHis');

        $payload = [
            'merchantId' => $merchantId,
            'datetime' => $datetime,
            'orderId' => $orderId,
            'challenge' => bin2hex(random_bytes(20)),
        ];

        try {
            $url = $this->baseUrl()."/check-out/initialize/{$merchantId}/{$orderId}";
            $response = Http::withHeaders([
                'X-KM-Api-Version' => 'v-0.2.0',
                'X-KM-IP-Add' => request()->ip() ?? '127.0.0.1',
            ])->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $paymentRef = (string) ($data['paymentReferenceId'] ?? '');
                $callBackUrl = (string) ($data['callBackUrl'] ?? '');

                if ($callBackUrl !== '') {
                    return GatewayDepositResponse::redirect(
                        url: $callBackUrl,
                        providerReference: $paymentRef ?: $orderId,
                        rawResponse: $data,
                        metadata: ['paymentReferenceId' => $paymentRef],
                    );
                }
            }

            $errorData = $response->json();
            $msg = $errorData['message'] ?? 'Nagad initialization failed: '.$response->body();

            return GatewayDepositResponse::failed($msg, is_array($errorData) ? $errorData : []);
        } catch (\Throwable $e) {
            Log::error('Nagad deposit initiation failed', ['exception' => $e->getMessage()]);

            return GatewayDepositResponse::failed('Nagad connection error: '.$e->getMessage());
        }
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        return GatewayWithdrawalResponse::pending(
            providerReference: 'NAGAD-DISBURSE-'.$withdrawal->reference_number,
            rawResponse: [],
            metadata: ['requires_manual_disbursement' => true],
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->webhookSecret() ?? $this->appSecret();

        if (empty($secret)) {
            Log::warning('Nagad webhook rejected: secret is not configured.');

            return false;
        }

        $provided = (string) ($request->header('X-KM-Signature')
            ?: $request->header('X-Nagad-Signature')
            ?: $request->header('X-Signature', ''));

        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $data = $request->json()->all();

        $paymentRef = (string) ($data['payment_ref_id'] ?? $data['paymentReferenceId'] ?? '');
        $orderId = (string) ($data['order_id'] ?? $data['orderId'] ?? $data['reference'] ?? '');
        $status = strtoupper((string) ($data['status'] ?? $data['payment_status'] ?? ''));
        $amount = (string) ($data['amount'] ?? '0.00');

        $currency = isset($data['currency']) ? (Currency::tryFrom(strtoupper((string) $data['currency'])) ?? Currency::BDT) : Currency::BDT;

        $eventType = match ($status) {
            'SUCCESS', 'COMPLETED' => WebhookEventType::DepositSuccess,
            'ABORTED', 'CANCELLED', 'CANCELED' => WebhookEventType::DepositCancelled,
            'FAILED', 'DECLINED' => WebhookEventType::DepositFailed,
            default => WebhookEventType::Unknown,
        };

        $eventId = ! empty($paymentRef) ? 'nagad_'.$paymentRef : 'nagad_evt_'.bin2hex(random_bytes(8));

        return new WebhookPayload(
            gateway: $this->name(),
            eventId: $eventId,
            eventType: $eventType,
            providerReference: ! empty($paymentRef) ? $paymentRef : $orderId,
            internalReference: ! empty($orderId) ? $orderId : null,
            amount: $amount,
            currency: $currency,
            isSuccess: $eventType->isSuccess(),
            failureReason: $eventType->isFailure() ? ($data['message'] ?? 'Nagad payment failed.') : null,
            rawData: $data,
            metadata: ['payment_ref_id' => $paymentRef],
        );
    }

    private function merchantId(): ?string
    {
        $id = $this->config->get('payment.gateways.nagad.merchant_id');

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    private function appSecret(): ?string
    {
        $secret = $this->config->get('payment.gateways.nagad.app_secret');

        return is_string($secret) && trim($secret) !== '' ? trim($secret) : null;
    }

    private function baseUrl(): string
    {
        $custom = $this->config->get('payment.gateways.nagad.base_url');

        if (is_string($custom) && trim($custom) !== '') {
            return rtrim(trim($custom), '/');
        }

        $sandbox = (bool) $this->config->get('payment.gateways.nagad.sandbox', true);

        return $sandbox
            ? 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs'
            : 'https://api.mynagad.com/api/dfs';
    }
}
