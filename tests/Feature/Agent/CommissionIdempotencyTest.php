<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Models\AgentCommission;
use App\Models\FinancialTransaction;
use App\Models\Wallet;

final class CommissionIdempotencyTest extends AgentTestCase
{
    public function test_settling_draw_multiple_times_is_idempotent(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '200.00'); // 10.00 commission

        // First settlement run
        $firstResult = $this->settlementService()->settleForDraw($draw->id);
        $this->assertSame(1, $firstResult->commissionsSettled);
        $this->assertSame('10.00', $firstResult->totalCommissionPaid);
        $this->assertFalse($firstResult->alreadySettled);

        $agentWallet = Wallet::query()->where('user_id', $agent->user_id)->first();
        $this->assertSame('10.00', (string) $agentWallet->balance);

        // Second settlement run on same draw
        $secondResult = $this->settlementService()->settleForDraw($draw->id);
        $this->assertSame(0, $secondResult->commissionsSettled);
        $this->assertSame('0.00', $secondResult->totalCommissionPaid);
        $this->assertTrue($secondResult->alreadySettled);

        $agentWallet->refresh();
        $this->assertSame('10.00', (string) $agentWallet->balance);

        // Transaction count should still be 1 (plus bet debit)
        $commissionTxCount = FinancialTransaction::query()
            ->where('type', 'commission')
            ->count();
        $this->assertSame(1, $commissionTxCount);
    }
}
