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

final class SuccessfulDepositCompletionTest extends PaymentTestCase
{
    #[Test]
    public function successful_deposit_webhook_advances_deposit_credits_wallet_and_updates_payment(): void
    {
        $player = $this->createPlayer('100.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '500.00', PaymentMethod::Stripe);

        $payload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_stripe_success_test_333',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'cs_test_session_333',
            internalReference: $deposit->reference_number,
            amount: '500.00',
            currency: Currency::THB,
            isSuccess: true,
            rawData: ['id' => 'cs_test_session_333', 'status' => 'complete'],
        );

        $result = $this->webhookService()->processWebhookPayload($payload);

        $this->assertTrue($result->success);
        $this->assertSame('credited', $result->actionTaken);

        // Verify Wallet balance
        $player['wallet']->refresh();
        $this->assertSame('600.00', $player['wallet']->balance);
        $this->assertSame('500.00', $player['wallet']->total_deposited);

        // Verify Deposit state
        $deposit->refresh();
        $this->assertSame(DepositStatus::Confirmed, $deposit->status);
        $this->assertNotNull($deposit->financial_transaction_id);
        $this->assertNotNull($deposit->confirmed_at);
        $this->assertSame('stripe', $deposit->provider);
        $this->assertSame('cs_test_session_333', $deposit->provider_reference);

        // Verify Payment state
        $payment = Payment::query()->where('payable_type', Deposit::class)->where('payable_id', $deposit->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertNotNull($payment->captured_at);
        $this->assertSame('cs_test_session_333', $payment->gateway_reference);

        // Verify Financial Transaction
        $tx = FinancialTransaction::query()->find($deposit->financial_transaction_id);
        $this->assertNotNull($tx);
        $this->assertSame('500.00', (string) $tx->amount);
        $this->assertSame(Currency::THB, $tx->currency);

        // Verify Ledger Entries (Balanced Double-Entry)
        $ledgerEntries = LedgerEntry::query()->where('financial_transaction_id', $tx->id)->get();
        $this->assertCount(2, $ledgerEntries);

        $debitTotal = $ledgerEntries->where('type.value', 'debit')->sum('amount');
        $creditTotal = $ledgerEntries->where('type.value', 'credit')->sum('amount');
        $this->assertSame('500.00', number_format((float) $debitTotal, 2, '.', ''));
        $this->assertSame('500.00', number_format((float) $creditTotal, 2, '.', ''));
    }
}
