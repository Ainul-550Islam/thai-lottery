<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;
use App\Models\AgentCommission;
use App\Models\FinancialTransaction;
use App\Models\Wallet;

final class DuplicateCommissionPreventionTest extends AgentTestCase
{
    public function test_prevents_duplicate_commission_payouts_for_same_record(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '200.00');

        $commission = AgentCommission::query()->where('agent_id', $agent->id)->first();
        $this->assertSame(CommissionStatus::Accrued, $commission->status);

        // First settlement
        $this->settlementService()->settleForDraw($draw->id);

        $commission->refresh();
        $this->assertSame(CommissionStatus::Paid, $commission->status);

        $agentWallet = Wallet::query()->where('user_id', $agent->user_id)->first();
        $this->assertSame('10.00', (string) $agentWallet->balance);

        // Subsequent attempt to settle
        $this->settlementService()->settleForDraw($draw->id);

        $agentWallet->refresh();
        $this->assertSame('10.00', (string) $agentWallet->balance);

        $txs = FinancialTransaction::query()->where('type', 'commission')->get();
        $this->assertCount(1, $txs);
    }
}
