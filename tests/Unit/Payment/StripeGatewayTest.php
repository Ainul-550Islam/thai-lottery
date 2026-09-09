<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Services\Payment\Drivers\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class StripeGatewayTest extends TestCase
{
    private StripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.gateways.stripe.enabled', true);
        Config::set('payment.gateways.stripe.secret', 'sk_test_mock_stripe_key_123');
        Config::set('payment.gateways.stripe.webhook_secret', 'whsec_test_stripe_secret_456');

        $this->gateway = app(StripeGateway::class);
    }

    public function test_gateway_properties_and_status(): void
    {
        $this->assertSame('stripe', $this->gateway->name());
        $this->assertSame('Stripe', $this->gateway->label());
        $this->assertSame(GatewayIntegrationStatus::FullyImplemented, $this->gateway->status());
        $this->assertTrue($this->gateway->supportsDeposit());
        $this->assertFalse($this->gateway->supportsWithdrawal());
        $this->assertTrue($this->gateway->supportsCurrency(Currency::THB));
        $this->assertTrue($this->gateway->supportsCurrency(Currency::USD));
        $this->assertFalse($this->gateway->supportsCurrency(Currency::BDT));
    }

    public function test_initiate_deposit_creates_checkout_session(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_mock_session_id',
                'url' => 'https://checkout.stripe.com/pay/cs_test_mock_session_id',
                'payment_intent' => 'pi_test_mock_intent_id',
            ], 200),
        ]);

        $deposit = new Deposit();
        $deposit->id = 1;
        $deposit->user_id = 42;
        $deposit->wallet_id = 99;
        $deposit->reference_number = 'DP-20260903-TEST01';
        $deposit->currency = Currency::THB;
        $deposit->amount = '250.00';
        $deposit->fee = '0.00';
        $deposit->net_amount = '250.00';

        $response = $this->gateway->initiateDeposit($deposit);

        $this->assertTrue($response->successful);
        $this->assertSame('cs_test_mock_session_id', $response->providerReference);
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_mock_session_id', $response->redirectUrl);
    }

    public function test_signature_verification_and_parsing(): void
    {
        $timestamp = time();
        $payload = [
            'id' => 'evt_test_123',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_abc',
                    'client_reference_id' => 'DP-20260903-TEST01',
                    'amount_total' => 25000,
                    'currency' => 'thb',
                ],
            ],
        ];

        $json = json_encode($payload);
        $signed = "{$timestamp}.{$json}";
        $sig = hash_hmac('sha256', $signed, 'whsec_test_stripe_secret_456');

        $request = Request::create(
            uri: '/api/v1/payments/webhook/stripe',
            method: 'POST',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$sig}"],
            content: $json,
        );

        $this->assertTrue($this->gateway->verifyWebhookSignature($request));

        $parsed = $this->gateway->parseWebhook($request);
        $this->assertSame('stripe', $parsed->gateway);
        $this->assertSame('evt_test_123', $parsed->eventId);
        $this->assertSame(WebhookEventType::DepositSuccess, $parsed->eventType);
        $this->assertSame('DP-20260903-TEST01', $parsed->internalReference);
        $this->assertSame('250.00', $parsed->amount);
        $this->assertSame(Currency::THB, $parsed->currency);
        $this->assertTrue($parsed->isSuccess);
    }
}
