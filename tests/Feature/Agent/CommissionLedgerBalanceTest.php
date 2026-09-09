<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryType;
use App\Models\AgentCommission;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\WalletService;

final class CommissionLedgerBalanceTest extends AgentTestCase
{
    public function test_commission_settlement_produces_balanced_ledger_entries(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $playerData = $this->createPlayer('1000.00', $agent);
        $draw = $this->createOpenDraw();

        $this->purchaseBet($playerData['user'], $draw, '3d_direct', '123', '200.00'); // 10.00 commission

        // Settle draw commissions
        $result = $this->settlementService()->settleForDraw($draw->id);

        $this->assertSame(1, $result->commissionsSettled);
        $this->assertSame('10.00', $result->totalCommissionPaid);

        $commission = AgentCommission::query()->where('agent_id', $agent->id)->first();
        $this->assertSame(CommissionStatus::Paid, $commission->status);
        $this->assertNotNull($commission->financial_transaction_id);

        // Verify ledger entries for this financial transaction
        $entries = LedgerEntry::query()
            ->where('financial_transaction_id', $commission->financial_transaction_id)
            ->get();

        $this->assertCount(2, $entries);

        $debitEntry = $entries->firstWhere('type', LedgerEntryType::Debit);
        $creditEntry = $entries->firstWhere('type', LedgerEntryType::Credit);

        $this->assertNotNull($debitEntry);
        $this->assertNotNull($creditEntry);

        // Debit: Agent Commission Expense (5100)
        $expenseAccount = LedgerAccount::query()->find($debitEntry->ledger_account_id);
        $this->assertSame(WalletService::ACCOUNT_COMMISSION_EXPENSE, $expenseAccount->code);
        $this->assertSame('10.00', (string) $debitEntry->amount);

        // Credit: Player / Agent Liability (2000)
        $liabilityAccount = LedgerAccount::query()->find($creditEntry->ledger_account_id);
        $this->assertSame(WalletService::ACCOUNT_PLAYER_LIABILITY, $liabilityAccount->code);
        $this->assertSame('10.00', (string) $creditEntry->amount);

        // Assert transaction balance
        $tx = FinancialTransaction::query()->find($commission->financial_transaction_id);
        $validator = app(LedgerBalanceValidator::class);
        $validator->assertTransactionBalanced($tx);
    }
}
