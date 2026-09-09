<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\DTOs\Agent\AgentOnboardingData;
use App\Enums\AgentStatus;
use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Config;

final class AgentHierarchyTest extends AgentTestCase
{
    public function test_enforces_max_depth_bounding(): void
    {
        Config::set('agent.hierarchy.max_depth', 2);

        // Level 1: Master agent
        $masterAgent = $this->createAgent('0.0500', AgentStatus::Active, null);

        // Level 2: Sub agent (parent = master agent) -> Allowed
        $subAgent = $this->createAgent('0.0400', AgentStatus::Active, $masterAgent->id);
        $this->assertSame($masterAgent->id, $subAgent->parent_agent_id);

        // Level 3: Sub-sub agent (parent = sub agent) -> Should fail because max depth is 2
        $user3 = User::factory()->create();
        Wallet::factory()->for($user3)->create(['currency' => Currency::THB]);

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('Hierarchy depth limit');

        $this->onboardingService()->onboard(new AgentOnboardingData(
            userId: (int) $user3->getKey(),
            commissionRate: '0.0300',
            parentAgentId: $subAgent->id,
            currency: Currency::THB,
            status: AgentStatus::Active,
        ));
    }

    public function test_multi_tier_commission_distribution_on_bet(): void
    {
        Config::set('agent.hierarchy.enabled', true);
        Config::set('agent.hierarchy.parent_commission_rate', '0.0100'); // 1% for parent

        // Level 1: Master agent
        $masterAgent = $this->createAgent('0.0500', AgentStatus::Active, null);

        // Level 2: Direct agent
        $directAgent = $this->createAgent('0.0500', AgentStatus::Active, $masterAgent->id);

        // Player referred by direct agent
        $playerData = $this->createPlayer('1000.00', $directAgent);
        $draw = $this->createOpenDraw();

        // Player places a 500.00 bet
        $result = $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '500.00');

        $commissions = AgentCommission::query()
            ->where('bet_id', $result->bet->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $commissions);

        // Direct agent commission (500 * 0.0500 = 25.00)
        $directComm = $commissions->firstWhere('agent_id', $directAgent->id);
        $this->assertNotNull($directComm);
        $this->assertSame('25.00', (string) $directComm->commission_amount);

        // Parent agent commission (500 * 0.0100 = 5.00)
        $parentComm = $commissions->firstWhere('agent_id', $masterAgent->id);
        $this->assertNotNull($parentComm);
        $this->assertSame('5.00', (string) $parentComm->commission_amount);

        // Settle draw
        $this->settlementService()->settleForDraw($draw->id);

        $directWallet = Wallet::query()->where('user_id', $directAgent->user_id)->first();
        $masterWallet = Wallet::query()->where('user_id', $masterAgent->user_id)->first();

        $this->assertSame('25.00', (string) $directWallet->balance);
        $this->assertSame('5.00', (string) $masterWallet->balance);
    }
}
