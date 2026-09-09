<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Enums\WithdrawalStatus;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use PHPUnit\Framework\Attributes\Test;

final class WithdrawalCompletionTest extends PaymentTestCase
{
    #[Test]
    public function withdrawal_webhook_completes_payout_consumes_hold_and_debits_wallet(): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $wallet = $player['wallet'];

        // 1. Request withdrawal for 400 THB
        $withdrawal = app(WithdrawalService::class)->request(
            wallet: $wallet,
            amount: Money::of('400.00', Currency::THB),
            method: PaymentMethod::Bkash,
            options: ['payout_details' => ['phone' => '01711111111']],
        );

        // 2. Approve withdrawal (reserves 400 THB into locked_balance)
        app(WithdrawalApprovalService::class)->approve($withdrawal);

        $wallet->refresh();
        $this->assertSame('1000.00', $wallet->balance);
        $this->assertSame('400.00', $wallet->locked_balance);

        // 3. Webhook arrives reporting successful disbursement
        $payload = new WebhookPayload(
            gateway: 'bkash',
            eventId: 'evt_bkash_wd_777',
            eventType: WebhookEventType::WithdrawalSuccess,
            providerReference: 'BKASH-DISB-777',
            internalReference: $withdrawal->reference_number,
            amount: '400.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        $result = $this->webhookService()->processWebhookPayload($payload);

        $this->assertTrue($result->success);
        $this->assertSame('debited', $result->actionTaken);

        // Verify Wallet state: locked released and balance debited (1000 - 400 = 600)
        $wallet->refresh();
        $this->assertSame('600.00', $wallet->balance);
        $this->assertSame('0.00', $wallet->locked_balance);
        $this->assertSame('400.00', $wallet->total_withdrawn);

        // Verify Withdrawal record
        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->status);
        $this->assertNotNull($withdrawal->financial_transaction_id);
        $this->assertNotNull($withdrawal->completed_at);

        // Verify Ledger Entries
        $ledgerEntries = LedgerEntry::query()->where('financial_transaction_id', $withdrawal->financial_transaction_id)->get();
        $this->assertCount(2, $ledgerEntries);

        $debitTotal = $ledgerEntries->where('type.value', 'debit')->sum('amount');
        $creditTotal = $ledgerEntries->where('type.value', 'credit')->sum('amount');
        $this->assertSame('400.00', number_format((float) $debitTotal, 2, '.', ''));
        $this->assertSame('400.00', number_format((float) $creditTotal, 2, '.', ''));
    }
}
