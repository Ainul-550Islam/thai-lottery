<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use PHPUnit\Framework\Attributes\Test;

final class WebhookReplayProtectionTest extends PaymentTestCase
{
    #[Test]
    public function identical_webhook_event_id_is_cached_and_ignored_on_replay(): void
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::Stripe);

        $payload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_unique_test_replay_999',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'cs_test_session_999',
            internalReference: $deposit->reference_number,
            amount: '1000.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        // First execution
        $firstResult = $this->webhookService()->processWebhookPayload($payload);
        $this->assertTrue($firstResult->success);
        $this->assertFalse($firstResult->replayed);
        $this->assertSame('credited', $firstResult->actionTaken);

        $player['wallet']->refresh();
        $this->assertSame('1500.00', $player['wallet']->balance);

        // Replay of same eventId
        $secondResult = $this->webhookService()->processWebhookPayload($payload);
        $this->assertTrue($secondResult->success);
        $this->assertTrue($secondResult->replayed);
        $this->assertSame('ignored', $secondResult->actionTaken);

        // Balance remains 1500.00, not double credited
        $player['wallet']->refresh();
        $this->assertSame('1500.00', $player['wallet']->balance);
    }
}
