<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Services\Payment\Drivers\NagadGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NagadGatewayTest extends TestCase
{
    private NagadGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.gateways.nagad.enabled', true);
        Config::set('payment.gateways.nagad.merchant_id', 'TEST_NAGAD_MERCHANT');
        Config::set('payment.gateways.nagad.app_secret', 'test_nagad_secret');
        Config::set('payment.gateways.nagad.webhook_secret', 'test_nagad_secret');

        $this->gateway = app(NagadGateway::class);
    }

    public function test_nagad_properties_and_status(): void
    {
        $this->assertSame('nagad', $this->gateway->name());
        $this->assertSame('Nagad', $this->gateway->label());
        $this->assertSame(GatewayIntegrationStatus::FullyImplemented, $this->gateway->status());
        $this->assertTrue($this->gateway->supportsDeposit());
        $this->assertTrue($this->gateway->supportsWithdrawal());
        $this->assertTrue($this->gateway->supportsCurrency(Currency::BDT));
    }

    public function test_nagad_initiate_deposit(): void
    {
        Http::fake([
            '*/check-out/initialize/*' => Http::response([
                'paymentReferenceId' => 'NAGAD_PAY_REF_999',
                'callBackUrl' => 'https://sandbox.mynagad.com/pay/NAGAD_PAY_REF_999',
            ], 200),
        ]);

        $deposit = new Deposit();
        $deposit->id = 2;
        $deposit->user_id = 11;
        $deposit->wallet_id = 22;
        $deposit->reference_number = 'DP-20260903-NAGAD01';
        $deposit->currency = Currency::BDT;
        $deposit->amount = '750.00';
        $deposit->fee = '0.00';
        $deposit->net_amount = '750.00';

        $response = $this->gateway->initiateDeposit($deposit);

        $this->assertTrue($response->successful);
        $this->assertSame('NAGAD_PAY_REF_999', $response->providerReference);
        $this->assertSame('https://sandbox.mynagad.com/pay/NAGAD_PAY_REF_999', $response->redirectUrl);
    }

    public function test_nagad_signature_and_webhook_parsing(): void
    {
        $payload = [
            'payment_ref_id' => 'NAGAD_TX_777',
            'order_id' => 'DP-NAGAD-REF-01',
            'amount' => '750.00',
            'status' => 'Success',
        ];

        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, 'test_nagad_secret');

        $request = Request::create(
            uri: '/api/v1/payments/webhook/nagad',
            method: 'POST',
            server: ['HTTP_X_KM_SIGNATURE' => $sig],
            content: $json,
        );

        $this->assertTrue($this->gateway->verifyWebhookSignature($request));

        $parsed = $this->gateway->parseWebhook($request);
        $this->assertSame('nagad', $parsed->gateway);
        $this->assertSame(WebhookEventType::DepositSuccess, $parsed->eventType);
        $this->assertSame('DP-NAGAD-REF-01', $parsed->internalReference);
        $this->assertSame('750.00', $parsed->amount);
        $this->assertSame(Currency::BDT, $parsed->currency);
        $this->assertTrue($parsed->isSuccess);
    }
}
