<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;
use App\Exceptions\FinancialException;
use App\Models\AgentCommission;
use App\Models\User;
use App\Models\Wallet;

final class AgentInactiveBehaviorTest extends AgentTestCase
{
    public function test_inactive_agent_cannot_accept_new_referrals(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Inactive);
        $user = User::factory()->create();

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('cannot accept new players');

        $this->referralService()->attributeUser($user, $agent->agent_code);
    }

    public function test_suspended_agent_does_not_accrue_commission_on_new_bets(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        // Suspend agent
        $this->onboardingService()->suspend($agent, 'Suspicious activity');

        // Player places a bet
        $result = $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '200.00');

        $commissions = AgentCommission::query()->where('bet_id', $result->bet->id)->get();
        $this->assertCount(0, $commissions);
    }

    public function test_agent_suspended_after_accrual_does_not_get_paid_at_settlement(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '200.00'); // 10.00 accrued

        $commission = AgentCommission::query()->where('agent_id', $agent->id)->first();
        $this->assertSame(CommissionStatus::Accrued, $commission->status);

        // Suspend agent before settlement
        $this->onboardingService()->suspend($agent, 'Investigation');

        // Settlement run
        $this->settlementService()->settleForDraw($draw->id);

        $agentWallet = Wallet::query()->where('user_id', $agent->user_id)->first();
        $this->assertSame('0.00', (string) $agentWallet->balance);

        $commission->refresh();
        $this->assertSame(CommissionStatus::Cancelled, $commission->status);
    }
}
