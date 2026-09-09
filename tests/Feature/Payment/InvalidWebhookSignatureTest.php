<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use PHPUnit\Framework\Attributes\Test;

final class InvalidWebhookSignatureTest extends PaymentTestCase
{
    #[Test]
    public function invalid_stripe_signature_is_rejected_with_403(): void
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::Stripe);

        $timestamp = time();
        $payload = [
            'id' => 'evt_fake_sig_123',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'client_reference_id' => $deposit->reference_number,
                    'amount_total' => 100000,
                    'currency' => 'thb',
                ],
            ],
        ];

        $fakeSignature = hash_hmac('sha256', 'tampered_data', self::STRIPE_WEBHOOK_SECRET);
        $header = "t={$timestamp},v1={$fakeSignature}";

        $response = $this->withHeaders([
            'Stripe-Signature' => $header,
        ])->postJson('/api/v1/payments/webhook/stripe', $payload);

        $response->assertStatus(403);
    }

    #[Test]
    public function missing_signature_header_is_rejected_with_403(): void
    {
        $payload = ['foo' => 'bar'];

        $response = $this->postJson('/api/v1/payments/webhook/bkash', $payload);

        $response->assertStatus(403);
    }

    #[Test]
    public function expired_stripe_timestamp_is_rejected_due_to_replay_window(): void
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::Stripe);

        // Timestamp is 600 seconds in the past (exceeding 300s max_age_seconds limit)
        $timestamp = time() - 600;
        $payload = [
            'id' => 'evt_old_timestamp',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'client_reference_id' => $deposit->reference_number,
                    'amount_total' => 100000,
                    'currency' => 'thb',
                ],
            ],
        ];

        $jsonPayload = json_encode($payload);
        $signedPayload = "{$timestamp}.{$jsonPayload}";
        $signature = hash_hmac('sha256', $signedPayload, self::STRIPE_WEBHOOK_SECRET);
        $header = "t={$timestamp},v1={$signature}";

        $response = $this->withHeaders([
            'Stripe-Signature' => $header,
        ])->postJson('/api/v1/payments/webhook/stripe', $payload);

        $response->assertStatus(403);
    }
}
