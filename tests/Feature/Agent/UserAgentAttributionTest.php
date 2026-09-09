<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\User;

final class UserAgentAttributionTest extends AgentTestCase
{
    public function test_can_attribute_player_to_agent_by_referral_code(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $user = User::factory()->create();

        $attributedAgent = $this->referralService()->attributeUser($user, $agent->agent_code);

        $this->assertSame($agent->id, $attributedAgent->id);

        $user->refresh();
        $this->assertSame($agent->id, $user->preferences['referred_by_agent_id']);
        $this->assertSame($agent->agent_code, $user->preferences['agent_code']);

        $agent->refresh();
        $this->assertSame(1, (int) $agent->total_referrals);
    }

    public function test_refuses_self_referral(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $agentUser = User::query()->find($agent->user_id);

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('A user cannot refer themselves');

        $this->referralService()->attributeUser($agentUser, $agent->agent_code);
    }

    public function test_refuses_attribution_to_inactive_or_suspended_agent(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Inactive);
        $user = User::factory()->create();

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('cannot accept new players');

        $this->referralService()->attributeUser($user, $agent->agent_code);
    }

    public function test_refuses_invalid_referral_code(): void
    {
        $user = User::factory()->create();

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('is not valid or does not exist');

        $this->referralService()->attributeUser($user, 'AGNONEXIST');
    }

    public function test_resolve_agent_for_user(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);

        $resolved = $this->referralService()->resolveAgentForUser($playerData['user']);

        $this->assertNotNull($resolved);
        $this->assertSame($agent->id, $resolved->id);
    }

    public function test_resolve_agent_returns_null_when_agent_is_suspended(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);

        $this->onboardingService()->suspend($agent, 'Investigation');

        $resolved = $this->referralService()->resolveAgentForUser($playerData['user']);
        $this->assertNull($resolved);
    }
}
