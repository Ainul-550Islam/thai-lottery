<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Models\Bet;
use Illuminate\Support\Facades\Config;

final class CommissionCalculationTest extends AgentTestCase
{
    public function test_turnover_commission_calculation(): void
    {
        Config::set('agent.commission.mode', 'turnover');

        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $bet = new Bet();
        $bet->stake_amount = '250.00';
        $bet->actual_payout = '0.00';

        $result = $this->calculationService()->calculate($bet, $agent, 1);

        $this->assertSame('250.00', $result->baseAmount);
        $this->assertSame('0.0500', $result->commissionRate);
        // 250.00 * 0.0500 = 12.50
        $this->assertSame('12.50', $result->commissionAmount);
    }

    public function test_net_revenue_commission_calculation_when_house_wins(): void
    {
        Config::set('agent.commission.mode', 'net_revenue');

        $agent = $this->createAgent('0.1000', AgentStatus::Active);
        $bet = new Bet();
        $bet->stake_amount = '1000.00';
        $bet->actual_payout = '200.00'; // Net revenue = 800.00

        $result = $this->calculationService()->calculate($bet, $agent, 1);

        $this->assertSame('800.00', $result->baseAmount);
        $this->assertSame('0.1000', $result->commissionRate);
        // 800.00 * 0.1000 = 80.00
        $this->assertSame('80.00', $result->commissionAmount);
    }

    public function test_net_revenue_commission_calculation_when_player_wins_more_than_stake(): void
    {
        Config::set('agent.commission.mode', 'net_revenue');

        $agent = $this->createAgent('0.1000', AgentStatus::Active);
        $bet = new Bet();
        $bet->stake_amount = '100.00';
        $bet->actual_payout = '900.00'; // Player won prize, house lost money

        $result = $this->calculationService()->calculate($bet, $agent, 1);

        $this->assertSame('0.00', $result->baseAmount);
        $this->assertSame('0.00', $result->commissionAmount);
    }

    public function test_half_up_decimal_rounding(): void
    {
        Config::set('agent.commission.mode', 'turnover');

        $agent = $this->createAgent('0.0333', AgentStatus::Active);
        $bet = new Bet();
        $bet->stake_amount = '15.00'; // 15 * 0.0333 = 0.4995 -> rounds to 0.50

        $result = $this->calculationService()->calculate($bet, $agent, 1);

        $this->assertSame('0.50', $result->commissionAmount);
    }
}
