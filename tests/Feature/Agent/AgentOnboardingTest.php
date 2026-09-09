<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\DTOs\Agent\AgentOnboardingData;
use App\Enums\AgentStatus;
use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;

final class AgentOnboardingTest extends AgentTestCase
{
    public function test_can_successfully_onboard_agent(): void
    {
        $user = User::factory()->create();

        $data = new AgentOnboardingData(
            userId: (int) $user->getKey(),
            commissionRate: '0.0500',
            currency: Currency::THB,
            status: AgentStatus::Active,
        );

        $agent = $this->onboardingService()->onboard($data);

        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertSame($user->id, $agent->user_id);
        $this->assertSame('0.0500', (string) $agent->commission_rate);
        $this->assertSame(AgentStatus::Active, $agent->status);
        $this->assertStringStartsWith('AG', $agent->agent_code);
        $this->assertSame(8, strlen($agent->agent_code));

        // Wallet was initialized
        $wallet = Wallet::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($wallet);
        $this->assertSame('THB', $wallet->currency->value);

        // Audit log created
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Agent::class,
            'auditable_id' => $agent->id,
        ]);
    }

    public function test_refuses_duplicate_agent_onboarding_for_same_user(): void
    {
        $user = User::factory()->create();

        $data = new AgentOnboardingData(
            userId: (int) $user->getKey(),
            commissionRate: '0.0500',
            currency: Currency::THB,
            status: AgentStatus::Active,
        );

        $this->onboardingService()->onboard($data);

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('already an agent');

        $this->onboardingService()->onboard($data);
    }

    public function test_refuses_invalid_commission_rates(): void
    {
        $user = User::factory()->create();

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('Commission rate must be between 0.0000 and 0.1000');

        $data = new AgentOnboardingData(
            userId: (int) $user->getKey(),
            commissionRate: '0.2500', // Exceeds max rate 0.1000
            currency: Currency::THB,
            status: AgentStatus::Active,
        );

        $this->onboardingService()->onboard($data);
    }

    public function test_can_approve_inactive_agent_to_active(): void
    {
        $user = User::factory()->create();

        $data = new AgentOnboardingData(
            userId: (int) $user->getKey(),
            commissionRate: '0.0500',
            currency: Currency::THB,
            status: AgentStatus::Inactive,
        );

        $agent = $this->onboardingService()->onboard($data);
        $this->assertSame(AgentStatus::Inactive, $agent->status);
        $this->assertFalse($agent->isActive());

        $approved = $this->onboardingService()->approve($agent);
        $this->assertSame(AgentStatus::Active, $approved->status);
        $this->assertTrue($approved->isActive());
    }

    public function test_can_suspend_and_terminate_agent(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);

        $suspended = $this->onboardingService()->suspend($agent, 'Compliance review');
        $this->assertSame(AgentStatus::Suspended, $suspended->status);
        $this->assertFalse($suspended->canEarnCommission());

        $terminated = $this->onboardingService()->terminate($agent, 'Fraud violation');
        $this->assertSame(AgentStatus::Terminated, $terminated->status);
        $this->assertFalse($terminated->canEarnCommission());
    }
}
