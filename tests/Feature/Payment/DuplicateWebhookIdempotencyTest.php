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

final class DuplicateWebhookIdempotencyTest extends PaymentTestCase
{
    #[Test]
    public function new_event_id_for_already_confirmed_deposit_is_safely_idempotent(): void
    {
        $player = $this->createPlayer('200.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '300.00', PaymentMethod::Stripe);

        // First webhook event
        $payload1 = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_stripe_first_111',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'cs_test_session_111',
            internalReference: $deposit->reference_number,
            amount: '300.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        $result1 = $this->webhookService()->processWebhookPayload($payload1);
        $this->assertTrue($result1->success);
        $this->assertFalse($result1->replayed);

        $player['wallet']->refresh();
        $this->assertSame('500.00', $player['wallet']->balance);

        $txCountBefore = FinancialTransaction::query()->count();
        $ledgerCountBefore = LedgerEntry::query()->count();

        // Second webhook event with a DIFFERENT eventId pointing to the same deposit reference
        $payload2 = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_stripe_second_222',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'cs_test_session_111',
            internalReference: $deposit->reference_number,
            amount: '300.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        $result2 = $this->webhookService()->processWebhookPayload($payload2);
        $this->assertTrue($result2->success);
        $this->assertTrue($result2->replayed);
        $this->assertSame('replayed', $result2->actionTaken);

        // Verify zero new transactions and zero new ledger entries
        $this->assertSame($txCountBefore, FinancialTransaction::query()->count());
        $this->assertSame($ledgerCountBefore, LedgerEntry::query()->count());

        $player['wallet']->refresh();
        $this->assertSame('500.00', $player['wallet']->balance);
    }
}
