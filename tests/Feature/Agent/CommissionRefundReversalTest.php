<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;
use App\Models\AgentCommission;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use App\Services\Finance\LedgerBalanceValidator;

final class CommissionRefundReversalTest extends AgentTestCase
{
    public function test_reversal_of_unpaid_accrued_commission(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $result = $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '200.00');
        $bet = $result->bet;

        $commission = AgentCommission::query()->where('bet_id', $bet->id)->first();
        $this->assertSame(CommissionStatus::Accrued, $commission->status);

        $agent->refresh();
        $this->assertSame('10.00', (string) $agent->total_commission_earned);

        // Reverse bet commission
        $reversed = $this->reversalService()->reverseForBet($bet, 'Bet cancelled');

        $this->assertCount(1, $reversed);
        $commission->refresh();
        $this->assertSame(CommissionStatus::Reversed, $commission->status);
        $this->assertNotNull($commission->reversed_at);

        $agent->refresh();
        $this->assertSame('0.00', (string) $agent->total_commission_earned);
    }

    public function test_reversal_of_already_paid_commission(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $result = $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '200.00');
        $bet = $result->bet;

        // Settle first
        $this->settlementService()->settleForDraw($draw->id);

        $agentWallet = Wallet::query()->where('user_id', $agent->user_id)->first();
        $this->assertSame('10.00', (string) $agentWallet->balance);

        $agent->refresh();
        $this->assertSame('10.00', (string) $agent->total_commission_earned);
        $this->assertSame('10.00', (string) $agent->total_commission_paid);

        // Reverse bet commission
        $reversed = $this->reversalService()->reverseForBet($bet, 'Bet voided after settlement');

        $this->assertCount(1, $reversed);

        $agentWallet->refresh();
        $this->assertSame('0.00', (string) $agentWallet->balance);

        $agent->refresh();
        $this->assertSame('0.00', (string) $agent->total_commission_earned);
        $this->assertSame('0.00', (string) $agent->total_commission_paid);

        // Ledger transactions must all be balanced
        $validator = app(LedgerBalanceValidator::class);
        $txs = FinancialTransaction::query()->get();
        foreach ($txs as $tx) {
            $validator->assertTransactionBalanced($tx);
        }
    }
}
