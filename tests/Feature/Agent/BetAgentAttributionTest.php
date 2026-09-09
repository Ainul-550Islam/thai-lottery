<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Models\AgentCommission;
use App\Models\Bet;

final class BetAgentAttributionTest extends AgentTestCase
{
    public function test_accrues_commission_when_referred_player_places_bet(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $result = $this->purchaseBet(
            user: $playerData['user'],
            draw: $draw,
            market: '3d_direct',
            number: '123',
            stake: '100.00',
        );

        $bet = $result->bet;

        $commission = AgentCommission::query()->where('bet_id', $bet->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame($agent->id, $commission->agent_id);
        $this->assertSame($playerData['user']->id, $commission->user_id);
        $this->assertSame('100.00', (string) $commission->base_amount);
        $this->assertSame('0.0500', (string) $commission->commission_rate);
        $this->assertSame('5.00', (string) $commission->commission_amount);
    }

    public function test_does_not_accrue_commission_when_unreferred_player_places_bet(): void
    {
        $playerData = $this->createPlayer('1000.00', null); // Unreferred
        $draw = $this->createOpenDraw();

        $result = $this->purchaseBet(
            user: $playerData['user'],
            draw: $draw,
            market: '3d_direct',
            number: '123',
            stake: '100.00',
        );

        $bet = $result->bet;

        $commissions = AgentCommission::query()->where('bet_id', $bet->id)->get();
        $this->assertCount(0, $commissions);
    }
}
