<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Enums\TransactionType;
use App\Models\AgentCommission;
use App\Models\FinancialTransaction;
use App\Models\Wallet;

final class CommissionWalletCreditTest extends AgentTestCase
{
    public function test_settlement_credits_agent_wallet_and_updates_totals(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $agentWallet = Wallet::query()->where('user_id', $agent->user_id)->first();
        $this->assertSame('0.00', (string) $agentWallet->balance);

        $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '300.00'); // 15.00 commission

        $this->settlementService()->settleForDraw($draw->id);

        $agentWallet->refresh();
        $this->assertSame('15.00', (string) $agentWallet->balance);

        $agent->refresh();
        $this->assertSame('15.00', (string) $agent->total_commission_paid);

        $commission = AgentCommission::query()->where('agent_id', $agent->id)->first();
        $tx = FinancialTransaction::query()->find($commission->financial_transaction_id);

        $this->assertNotNull($tx);
        $this->assertSame(TransactionType::Commission, $tx->type);
        $this->assertSame('15.00', (string) $tx->amount);
    }
}
