<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Services\Payment\Drivers\CryptoGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class CryptoGatewayTest extends TestCase
{
    private CryptoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.gateways.crypto.enabled', true);
        Config::set('payment.gateways.crypto.webhook_secret', 'test_crypto_secret');

        $this->gateway = app(CryptoGateway::class);
    }

    public function test_crypto_properties_and_status(): void
    {
        $this->assertSame('crypto', $this->gateway->name());
        $this->assertSame('Cryptocurrency', $this->gateway->label());
        $this->assertSame(GatewayIntegrationStatus::FullyImplemented, $this->gateway->status());
        $this->assertTrue($this->gateway->supportsDeposit());
        $this->assertTrue($this->gateway->supportsWithdrawal());
        $this->assertTrue($this->gateway->supportsCurrency(Currency::USD));
        $this->assertFalse($this->gateway->supportsCurrency(Currency::THB));
    }

    public function test_crypto_initiate_deposit(): void
    {
        $deposit = new Deposit();
        $deposit->id = 3;
        $deposit->user_id = 15;
        $deposit->wallet_id = 30;
        $deposit->reference_number = 'DP-20260903-CRYPTO01';
        $deposit->currency = Currency::USD;
        $deposit->amount = '100.00';
        $deposit->fee = '0.00';
        $deposit->net_amount = '100.00';

        $response = $this->gateway->initiateDeposit($deposit);

        $this->assertTrue($response->successful);
        $this->assertNotNull($response->providerReference);
        $this->assertStringContainsString('invoice=', $response->redirectUrl);
    }

    public function test_crypto_signature_and_webhook_parsing(): void
    {
        $payload = [
            'txid' => '0xabcdef1234567890',
            'invoice_id' => 'DP-CRYPTO-REF-01',
            'amount' => '100.00',
            'status' => 'CONFIRMED',
            'confirmations' => 12,
        ];

        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, 'test_crypto_secret');

        $request = Request::create(
            uri: '/api/v1/payments/webhook/crypto',
            method: 'POST',
            server: ['HTTP_X_CRYPTO_SIGNATURE' => $sig],
            content: $json,
        );

        $this->assertTrue($this->gateway->verifyWebhookSignature($request));

        $parsed = $this->gateway->parseWebhook($request);
        $this->assertSame('crypto', $parsed->gateway);
        $this->assertSame(WebhookEventType::DepositSuccess, $parsed->eventType);
        $this->assertSame('DP-CRYPTO-REF-01', $parsed->internalReference);
        $this->assertSame('100.00', $parsed->amount);
        $this->assertSame(Currency::USD, $parsed->currency);
        $this->assertTrue($parsed->isSuccess);
    }
}
