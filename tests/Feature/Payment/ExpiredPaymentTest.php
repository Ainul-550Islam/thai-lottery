<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

final class ExpiredPaymentTest extends PaymentTestCase
{
    #[Test]
    public function expired_deposit_webhook_transitions_deposit_to_failed_with_expiry_reason(): void
    {
        $player = $this->createPlayer('300.00', Currency::USD);
        $deposit = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Crypto);

        $payload = new WebhookPayload(
            gateway: 'crypto',
            eventId: 'evt_crypto_expired_555',
            eventType: WebhookEventType::DepositExpired,
            providerReference: 'crypto_inv_555',
            internalReference: $deposit->reference_number,
            amount: '100.00',
            currency: Currency::USD,
            isSuccess: false,
            failureReason: 'Crypto payment window expired.',
        );

        $result = $this->webhookService()->processWebhookPayload($payload);

        $this->assertFalse($result->success);

        // Verify Deposit and Payment status
        $deposit->refresh();
        $this->assertSame(DepositStatus::Failed, $deposit->status);
        $this->assertSame('Crypto payment window expired.', $deposit->failure_reason);

        $payment = Payment::query()->where('payable_type', Deposit::class)->where('payable_id', $deposit->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Failed, $payment->status);

        $player['wallet']->refresh();
        $this->assertSame('300.00', $player['wallet']->balance);
    }
}
