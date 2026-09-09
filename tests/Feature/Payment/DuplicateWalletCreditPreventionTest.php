<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use PHPUnit\Framework\Attributes\Test;

final class DuplicateWalletCreditPreventionTest extends PaymentTestCase
{
    #[Test]
    public function simultaneous_or_repeated_webhooks_cannot_double_credit_wallet(): void
    {
        $player = $this->createPlayer('100.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Stripe);

        $payload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_stripe_concurrency_666',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'cs_test_session_666',
            internalReference: $deposit->reference_number,
            amount: '400.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        // Run 5 repeated webhooks in sequence
        for ($i = 0; $i < 5; $i++) {
            $this->webhookService()->processWebhookPayload($payload);
        }

        // Verify balance was credited exactly ONCE: 100 + 400 = 500
        $player['wallet']->refresh();
        $this->assertSame('500.00', $player['wallet']->balance);
        $this->assertSame('400.00', $player['wallet']->total_deposited);

        // Verify exactly ONE financial transaction and TWO ledger entries
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
    }
}
