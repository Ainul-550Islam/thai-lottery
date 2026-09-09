<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\DTOs\Agent\AgentReportData;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Carbon;

final class AgentReportingTest extends AgentTestCase
{
    public function test_generates_accurate_agent_performance_report(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $player1 = $this->createPlayer('5000.00', $agent);
        $player2 = $this->createPlayer('5000.00', $agent);
        $draw = $this->createOpenDraw();

        // Player 1 places bet (200.00) -> 10.00 commission
        $this->purchaseBet($player1['user'], $draw, '3d_direct', '123', '200.00');

        // Player 2 places bet (300.00) -> 15.00 commission
        $this->purchaseBet($player2['user'], $draw, '2d_top', '45', '300.00');

        // Settle draw
        $this->settlementService()->settleForDraw($draw->id);

        $report = $this->reportingService()->getAgentReport($agent);

        $this->assertInstanceOf(AgentReportData::class, $report);
        $this->assertSame($agent->id, $report->agentId);
        $this->assertSame($agent->agent_code, $report->agentCode);
        $this->assertSame(2, $report->referredPlayers);
        $this->assertSame(2, $report->activePlayers);
        $this->assertSame('500.00', $report->totalTurnover);
        $this->assertSame('25.00', $report->commissionAccrued);
        $this->assertSame('25.00', $report->commissionPaid);
        $this->assertSame('THB', $report->currency);
    }

    public function test_report_for_agent_with_no_referrals(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);

        $report = $this->reportingService()->getAgentReport($agent);

        $this->assertSame(0, $report->referredPlayers);
        $this->assertSame(0, $report->activePlayers);
        $this->assertSame('0.00', $report->totalTurnover);
        $this->assertSame('0.00', $report->commissionAccrued);
        $this->assertSame('0.00', $report->commissionPaid);
    }
}
