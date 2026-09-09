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
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

final class FailedPaymentStateTest extends PaymentTestCase
{
    #[Test]
    public function failed_deposit_webhook_transitions_deposit_to_failed_without_wallet_credit(): void
    {
        $player = $this->createPlayer('250.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '750.00', PaymentMethod::Stripe);

        $payload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_stripe_failure_test_444',
            eventType: WebhookEventType::DepositFailed,
            providerReference: 'cs_test_session_444',
            internalReference: $deposit->reference_number,
            amount: '750.00',
            currency: Currency::THB,
            isSuccess: false,
            failureReason: 'Card was declined by issuing bank.',
        );

        $result = $this->webhookService()->processWebhookPayload($payload);

        $this->assertFalse($result->success);
        $this->assertSame('Card was declined by issuing bank.', $result->message);

        // Wallet balance untouched
        $player['wallet']->refresh();
        $this->assertSame('250.00', $player['wallet']->balance);
        $this->assertSame('0.00', $player['wallet']->total_deposited);

        // Deposit marked Failed
        $deposit->refresh();
        $this->assertSame(DepositStatus::Failed, $deposit->status);
        $this->assertNull($deposit->financial_transaction_id);
        $this->assertNotNull($deposit->failed_at);
        $this->assertSame('Card was declined by issuing bank.', $deposit->failure_reason);

        // Payment marked Failed
        $payment = Payment::query()->where('payable_type', Deposit::class)->where('payable_id', $deposit->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Card was declined by issuing bank.', $payment->failure_reason);

        // Zero financial transactions and zero ledger entries created
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }
}
