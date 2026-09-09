<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\DTOs\Agent\AgentOnboardingData;
use App\Enums\AgentStatus;
use App\Enums\Currency;
use App\Models\Agent;
use App\Models\User;

final class AgentReferralCodeUniquenessTest extends AgentTestCase
{
    public function test_generates_unique_codes_with_ag_prefix(): void
    {
        $codes = [];

        for ($i = 0; $i < 10; $i++) {
            $user = User::factory()->create();

            $agent = $this->onboardingService()->onboard(new AgentOnboardingData(
                userId: (int) $user->getKey(),
                commissionRate: '0.0500',
                currency: Currency::THB,
                status: AgentStatus::Active,
            ));

            $this->assertStringStartsWith('AG', $agent->agent_code);
            $this->assertSame(8, strlen($agent->agent_code));
            $this->assertNotContains($agent->agent_code, $codes);

            $codes[] = $agent->agent_code;
        }

        $this->assertCount(10, array_unique($codes));
    }
}
