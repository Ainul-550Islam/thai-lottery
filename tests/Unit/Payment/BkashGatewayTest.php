<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Services\Payment\Drivers\BkashGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BkashGatewayTest extends TestCase
{
    private BkashGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.gateways.bkash.enabled', true);
        Config::set('payment.gateways.bkash.app_key', 'test_app_key');
        Config::set('payment.gateways.bkash.app_secret', 'test_app_secret');
        Config::set('payment.gateways.bkash.username', 'test_user');
        Config::set('payment.gateways.bkash.password', 'test_pass');
        Config::set('payment.gateways.bkash.webhook_secret', 'test_webhook_secret');

        $this->gateway = app(BkashGateway::class);
    }

    public function test_bkash_properties_and_status(): void
    {
        $this->assertSame('bkash', $this->gateway->name());
        $this->assertSame('bKash', $this->gateway->label());
        $this->assertSame(GatewayIntegrationStatus::FullyImplemented, $this->gateway->status());
        $this->assertTrue($this->gateway->supportsDeposit());
        $this->assertTrue($this->gateway->supportsWithdrawal());
        $this->assertTrue($this->gateway->supportsCurrency(Currency::BDT));
        $this->assertFalse($this->gateway->supportsCurrency(Currency::THB));
    }

    public function test_bkash_initiate_deposit(): void
    {
        Http::fake([
            '*/tokenized/checkout/token/grant' => Http::response(['id_token' => 'mock_bkash_token_123'], 200),
            '*/tokenized/checkout/create' => Http::response([
                'paymentID' => 'BK_PAY_MOCK_123',
                'bkashURL' => 'https://sandbox.bka.sh/checkout/BK_PAY_MOCK_123',
                'statusCode' => '0000',
            ], 200),
        ]);

        $deposit = new Deposit();
        $deposit->id = 1;
        $deposit->user_id = 10;
        $deposit->wallet_id = 20;
        $deposit->reference_number = 'DP-20260903-BKASH01';
        $deposit->currency = Currency::BDT;
        $deposit->amount = '500.00';
        $deposit->fee = '0.00';
        $deposit->net_amount = '500.00';

        $response = $this->gateway->initiateDeposit($deposit);

        $this->assertTrue($response->successful);
        $this->assertSame('BK_PAY_MOCK_123', $response->providerReference);
        $this->assertSame('https://sandbox.bka.sh/checkout/BK_PAY_MOCK_123', $response->redirectUrl);
    }

    public function test_bkash_signature_and_webhook_parsing(): void
    {
        $payload = [
            'paymentID' => 'BK_PAY_123',
            'trxID' => 'TRX_999888',
            'merchantInvoiceNumber' => 'DP-BKASH-REF-01',
            'amount' => '350.00',
            'transactionStatus' => 'Completed',
        ];

        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, 'test_webhook_secret');

        $request = Request::create(
            uri: '/api/v1/payments/webhook/bkash',
            method: 'POST',
            server: ['HTTP_X_BKASH_SIGNATURE' => $sig],
            content: $json,
        );

        $this->assertTrue($this->gateway->verifyWebhookSignature($request));

        $parsed = $this->gateway->parseWebhook($request);
        $this->assertSame('bkash', $parsed->gateway);
        $this->assertSame(WebhookEventType::DepositSuccess, $parsed->eventType);
        $this->assertSame('DP-BKASH-REF-01', $parsed->internalReference);
        $this->assertSame('350.00', $parsed->amount);
        $this->assertSame(Currency::BDT, $parsed->currency);
        $this->assertTrue($parsed->isSuccess);
    }
}
