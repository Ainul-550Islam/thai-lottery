<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;
use App\Models\AgentCommission;
use App\Models\Wallet;
use App\Services\Finance\LedgerBalanceValidator;

final class CommissionDrawCancellationTest extends AgentTestCase
{
    public function test_draw_cancellation_reverses_all_commissions(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData1 = $this->createPlayer('1000.00', $agent);
        $playerData2 = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $this->purchaseBet($playerData1['user'], $draw, '3d_direct', '123', '200.00'); // 10.00
        $this->purchaseBet($playerData2['user'], $draw, '2d_top', '45', '200.00');      // 10.00

        $commissions = AgentCommission::query()->where('draw_id', $draw->id)->get();
        $this->assertCount(2, $commissions);

        $agent->refresh();
        $this->assertSame('20.00', (string) $agent->total_commission_earned);

        // Cancel draw and reverse all commissions
        $reversed = $this->reversalService()->reverseForDraw($draw->id, 'Draw cancelled due to technical failure');

        $this->assertCount(2, $reversed);

        foreach ($reversed as $comm) {
            $comm->refresh();
            $this->assertSame(CommissionStatus::Reversed, $comm->status);
        }

        $agent->refresh();
        $this->assertSame('0.00', (string) $agent->total_commission_earned);
    }
}
