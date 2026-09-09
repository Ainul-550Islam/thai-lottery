<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

final class PlayerDepositApiTest extends PaymentTestCase
{
    #[Test]
    public function authenticated_player_can_initiate_stripe_deposit(): void
    {
        $player = $this->createPlayer('100.00', Currency::THB);
        Sanctum::actingAs($player['user']);

        $response = $this->postJson('/api/v1/deposits', [
            'amount' => '500.00',
            'method' => 'stripe',
            'currency' => 'THB',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.deposit.amount', '500.00');
        $response->assertJsonPath('data.deposit.status', 'pending');
        $response->assertJsonPath('data.deposit.currency', 'THB');
        $response->assertJsonPath('data.payment.status', 'pending');

        $deposit = Deposit::query()->where('user_id', $player['user']->id)->first();
        $this->assertNotNull($deposit);
        $this->assertSame('500.00', (string) $deposit->amount);
    }

    #[Test]
    public function player_can_view_their_own_deposit(): void
    {
        $player = $this->createPlayer('100.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '750.00', PaymentMethod::Stripe);
        Sanctum::actingAs($player['user']);

        $response = $this->getJson('/api/v1/deposits/'.$deposit->reference_number);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.reference_number', $deposit->reference_number);
        $response->assertJsonPath('data.amount', '750.00');
    }

    #[Test]
    public function player_cannot_view_another_players_deposit(): void
    {
        $player1 = $this->createPlayer('100.00', Currency::THB);
        $player2 = $this->createPlayer('200.00', Currency::THB);

        $deposit = $this->createPendingDeposit($player1['wallet'], '500.00', PaymentMethod::Stripe);
        Sanctum::actingAs($player2['user']);

        $response = $this->getJson('/api/v1/deposits/'.$deposit->reference_number);

        $response->assertStatus(404);
    }
}
