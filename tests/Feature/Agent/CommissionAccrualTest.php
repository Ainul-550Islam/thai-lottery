<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;
use App\Models\AgentCommission;

final class CommissionAccrualTest extends AgentTestCase
{
    public function test_accrues_commission_record_with_proper_status_and_counters(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $this->purchaseBet(
            user: $playerData['user'],
            draw: $draw,
            market: '3d_direct',
            number: '123',
            stake: '200.00',
        );

        $commission = AgentCommission::query()->where('agent_id', $agent->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame(CommissionStatus::Accrued, $commission->status);
        $this->assertSame('200.00', (string) $commission->base_amount);
        $this->assertSame('0.0500', (string) $commission->commission_rate);
        $this->assertSame('10.00', (string) $commission->commission_amount);
        $this->assertNull($commission->paid_at);

        $agent->refresh();
        $this->assertSame('10.00', (string) $agent->total_commission_earned);
        $this->assertSame('0.00', (string) $agent->total_commission_paid);
    }

    public function test_multiple_bets_accumulate_commission_earned(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '100.00'); // 5.00
        $this->purchaseBet($playerData['user'], $draw, '2d_top', '45', '100.00');      // 5.00

        $agent->refresh();
        $this->assertSame('10.00', (string) $agent->total_commission_earned);
        $this->assertCount(2, AgentCommission::query()->where('agent_id', $agent->id)->get());
    }
}
