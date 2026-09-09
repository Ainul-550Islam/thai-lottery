<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Services\Finance\WalletService;
use PHPUnit\Framework\Attributes\Test;

final class PaymentLedgerBalanceTest extends PaymentTestCase
{
    #[Test]
    public function deposit_and_refund_postings_maintain_strict_double_entry_balance(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '1500.00', PaymentMethod::Stripe);

        // 1. Process successful deposit webhook
        $successPayload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_stripe_ledger_888',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'cs_test_session_888',
            internalReference: $deposit->reference_number,
            amount: '1500.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        $depositResult = $this->webhookService()->processWebhookPayload($successPayload);
        $this->assertTrue($depositResult->success);

        $deposit->refresh();
        $depositTx = FinancialTransaction::query()->find($deposit->financial_transaction_id);

        $depositEntries = LedgerEntry::query()->where('financial_transaction_id', $depositTx->id)->get();
        $this->assertCount(2, $depositEntries);

        $cashAccount = LedgerAccount::query()->where('code', WalletService::ACCOUNT_SYSTEM_CASH)->firstOrFail();
        $liabilityAccount = LedgerAccount::query()->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)->firstOrFail();

        $cashEntry = $depositEntries->firstWhere('ledger_account_id', $cashAccount->id);
        $liabilityEntry = $depositEntries->firstWhere('ledger_account_id', $liabilityAccount->id);

        $this->assertNotNull($cashEntry);
        $this->assertSame('debit', $cashEntry->type->value);
        $this->assertSame('1500.00', (string) $cashEntry->amount);

        $this->assertNotNull($liabilityEntry);
        $this->assertSame('credit', $liabilityEntry->type->value);
        $this->assertSame('1500.00', (string) $liabilityEntry->amount);

        // 2. Process refund / chargeback webhook
        $refundPayload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_stripe_refund_888',
            eventType: WebhookEventType::ChargeRefunded,
            providerReference: 'cs_test_session_888',
            internalReference: $deposit->reference_number,
            amount: '1500.00',
            currency: Currency::THB,
            isSuccess: false,
        );

        $refundResult = $this->webhookService()->processWebhookPayload($refundPayload);
        $this->assertTrue($refundResult->success);
        $this->assertSame('reversed', $refundResult->actionTaken);

        // Verify player wallet was reversed back to 0.00
        $player['wallet']->refresh();
        $this->assertSame('0.00', $player['wallet']->balance);

        // Overall ledger balance across all entries must sum to exactly zero
        $allEntries = LedgerEntry::query()->get();
        $totalDebits = '0.00';
        $totalCredits = '0.00';

        foreach ($allEntries as $entry) {
            if ($entry->type->value === 'debit') {
                $totalDebits = bcadd($totalDebits, (string) $entry->amount, 2);
            } else {
                $totalCredits = bcadd($totalCredits, (string) $entry->amount, 2);
            }
        }

        $this->assertSame($totalDebits, $totalCredits);
    }
}
