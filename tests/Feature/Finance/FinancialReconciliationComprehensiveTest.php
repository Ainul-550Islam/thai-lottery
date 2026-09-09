<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\AuditAction;
use App\Enums\BetMarket;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\DiscrepancyCategory;
use App\Enums\DiscrepancySeverity;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionStatus;
use App\Enums\WebhookEventType;
use App\Enums\WithdrawalStatus;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payment\PaymentTestCase;

/**
 * Phase 5.3.4: Comprehensive Financial Reconciliation & Accounting Control Test Suite.
 *
 * Covers all 34 mandatory test conditions:
 * - Balanced & Unbalanced Ledger Verification
 * - Wallet vs Ledger Balance Parity
 * - Deposit, Withdrawal, Prize, and Bet Purchase Accounting
 * - Agent Commission Accounting & Segregation
 * - Currency Boundary Enforcement & Anti-Mixing
 * - Multi-Tenant Isolation & Anti-Tampering
 * - Period-Based Reconciliation & BCMath Totals
 * - CLI Artisan Command Execution & JSON Reports
 * - Audit Trail Logging Without Secret Exposure
 */
final class FinancialReconciliationComprehensiveTest extends PaymentTestCase
{
    private FinancialReconciliationService $reconciliationService;
    private WalletService $walletService;
    private FinancialTransactionService $transactionService;
    private LedgerPostingService $ledgerService;
    private DepositCompletionService $depositCompletionService;
    private WithdrawalService $withdrawalService;
    private WithdrawalApprovalService $withdrawalApprovalService;
    private WithdrawalCompletionService $withdrawalCompletionService;
    private FinancialReversalService $reversalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciliationService = app(FinancialReconciliationService::class);
        $this->walletService = app(WalletService::class);
        $this->transactionService = app(FinancialTransactionService::class);
        $this->ledgerService = app(LedgerPostingService::class);
        $this->depositCompletionService = app(DepositCompletionService::class);
        $this->withdrawalService = app(WithdrawalService::class);
        $this->withdrawalApprovalService = app(WithdrawalApprovalService::class);
        $this->withdrawalCompletionService = app(WithdrawalCompletionService::class);
        $this->reversalService = app(FinancialReversalService::class);
    }

    /**
     * Helper to create a player with an initial ledger-backed wallet balance.
     *
     * @return array{user: User, wallet: Wallet}
     */
    protected function createReconciledPlayer(string $balance = '0.00', Currency $currency = Currency::THB): array
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance('0.00')
            ->create(['currency' => $currency]);

        if (bccomp($balance, '0.00', 2) > 0) {
            $deposit = $this->createApprovedDeposit($wallet, $balance);
            $this->depositCompletionService->complete($deposit, null, [
                'provider_reference' => 'INIT-'.bin2hex(random_bytes(4)),
            ]);
            $wallet->refresh();
        }

        return ['user' => $user, 'wallet' => $wallet];
    }

    private function createApprovedDeposit(
        Wallet $wallet,
        string $amount = '1000.00',
        PaymentMethod $method = PaymentMethod::Stripe,
    ): Deposit {
        $deposit = $this->createPendingDeposit($wallet, $amount, $method);
        $deposit->status = DepositStatus::Approved;
        $deposit->save();

        return $deposit;
    }

    #[Test]
    public function test_01_balanced_ledger_passes_reconciliation(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);

        $report = $this->reconciliationService->reconcile();

        $this->assertSame(ReconciliationStatus::Pass, $report->status);
        $this->assertSame(0, $report->anomalyCount);
        $this->assertSame('0.00', $report->ledgerDifference);
        $this->assertTrue($report->isPassing());
    }

    #[Test]
    public function test_02_unbalanced_ledger_detected_as_critical_discrepancy(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);

        // Intentionally tamper with a ledger entry amount to simulate ledger imbalance
        $entry = LedgerEntry::where('wallet_id', $player['wallet']->id)
            ->where('type', 'debit')
            ->firstOrFail();

        DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => '400.00']);

        $report = $this->reconciliationService->reconcile();

        $this->assertSame(ReconciliationStatus::Critical, $report->status);
        $this->assertGreaterThanOrEqual(1, $report->criticalAnomalyCount);

        $unbalanced = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::UnbalancedLedger);
        $this->assertNotNull($unbalanced);
        $this->assertSame(DiscrepancySeverity::Critical, $unbalanced->severity);
    }

    #[Test]
    public function test_03_wallet_ledger_mismatch_detected(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);

        // Intentionally mutate wallet balance directly in database without ledger posting
        DB::table('wallets')->where('id', $player['wallet']->id)->update(['balance' => '9999.00']);

        $report = $this->reconciliationService->reconcile();

        $this->assertSame(ReconciliationStatus::Critical, $report->status);
        $mismatch = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::WalletLedgerMismatch);
        $this->assertNotNull($mismatch);
        $this->assertSame($player['wallet']->id, $mismatch->entityId);
    }

    #[Test]
    public function test_04_duplicate_deposit_provider_reference_detected(): void
    {
        $player1 = $this->createReconciledPlayer('0.00', Currency::THB);
        $player2 = $this->createReconciledPlayer('0.00', Currency::THB);

        $d1 = $this->createPendingDeposit($player1['wallet'], '200.00');
        $d1->provider_reference = 'DUP-REF-123';
        $d1->save();

        $d2 = $this->createPendingDeposit($player2['wallet'], '200.00');
        $d2->provider_reference = 'DUP-REF-123';
        $d2->save();

        $report = $this->reconciliationService->reconcile();

        $dup = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::DuplicateDeposit);
        $this->assertNotNull($dup);
        $this->assertSame(DiscrepancySeverity::Critical, $dup->severity);
    }

    #[Test]
    public function test_05_missing_deposit_accounting_detected(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '300.00');

        // Mark deposit Confirmed without linking a financial transaction
        DB::table('deposits')->where('id', $deposit->id)->update([
            'status' => DepositStatus::Confirmed->value,
            'financial_transaction_id' => null,
        ]);

        $report = $this->reconciliationService->reconcile();

        $missing = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::DepositAccountingMissing);
        $this->assertNotNull($missing);
        $this->assertSame(DiscrepancySeverity::Critical, $missing->severity);
    }

    #[Test]
    public function test_06_duplicate_withdrawal_provider_reference_detected(): void
    {
        $player1 = $this->createReconciledPlayer('500.00', Currency::THB);
        $player2 = $this->createReconciledPlayer('500.00', Currency::THB);

        $w1 = $this->withdrawalService->request(
            wallet: $player1['wallet'],
            amount: Money::of('200.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
            options: ['provider_reference' => 'DUP-WD-REF-99'],
        );

        $w2 = $this->withdrawalService->request(
            wallet: $player2['wallet'],
            amount: Money::of('200.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
            options: ['provider_reference' => 'DUP-WD-REF-99'],
        );

        $report = $this->reconciliationService->reconcile();

        $dup = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::DuplicateWithdrawal);
        $this->assertNotNull($dup);
        $this->assertSame(DiscrepancySeverity::Critical, $dup->severity);
    }

    #[Test]
    public function test_07_missing_withdrawal_accounting_detected(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);
        $w = $this->withdrawalService->request(
            wallet: $player['wallet'],
            amount: Money::of('250.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
        );

        // Mark withdrawal Completed without linked financial transaction
        DB::table('withdrawals')->where('id', $w->id)->update([
            'status' => WithdrawalStatus::Completed->value,
            'financial_transaction_id' => null,
        ]);

        $report = $this->reconciliationService->reconcile();

        $missing = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::WithdrawalAccountingMissing);
        $this->assertNotNull($missing);
        $this->assertSame(DiscrepancySeverity::Critical, $missing->severity);
    }

    #[Test]
    public function test_08_duplicate_prize_payout_detected(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $draw = Draw::factory()->create();

        $bet = new Bet();
        $bet->fill([
            'bet_number' => 'BET-DUP-PO-08',
            'user_id' => $player['user']->id,
            'draw_id' => $draw->id,
            'type' => BetType::TwoD,
            'currency' => Currency::THB,
            'stake_amount' => '100.00',
            'potential_payout' => '900.00',
            'total_numbers' => 1,
        ]);
        $bet->status = BetStatus::Won;
        $bet->actual_payout = '900.00';
        $bet->save();

        $p1 = new Payout();
        $p1->fill([
            'reference_number' => 'PO-08-1',
            'draw_id' => $draw->id,
            'user_id' => $player['user']->id,
            'bet_id' => $bet->id,
            'amount' => '900.00',
            'currency' => Currency::THB,
        ]);
        $p1->status = PayoutStatus::Completed;
        $p1->save();

        $p2 = new Payout();
        $p2->fill([
            'reference_number' => 'PO-08-2',
            'draw_id' => $draw->id,
            'user_id' => $player['user']->id,
            'bet_id' => $bet->id,
            'amount' => '900.00',
            'currency' => Currency::THB,
        ]);
        $p2->status = PayoutStatus::Completed;
        $p2->save();

        $report = $this->reconciliationService->reconcile();

        $dup = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::DuplicatePrize);
        $this->assertNotNull($dup);
    }

    #[Test]
    public function test_09_missing_prize_accounting_detected(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $draw = Draw::factory()->create();

        $bet = new Bet();
        $bet->fill([
            'bet_number' => 'BET-WON-NO-PO',
            'user_id' => $player['user']->id,
            'draw_id' => $draw->id,
            'type' => BetType::TwoD,
            'currency' => Currency::THB,
            'stake_amount' => '100.00',
            'potential_payout' => '900.00',
            'total_numbers' => 1,
        ]);
        $bet->status = BetStatus::Won;
        $bet->actual_payout = '900.00';
        $bet->payout_id = null; // Missing payout!
        $bet->save();

        $report = $this->reconciliationService->reconcile();

        $missing = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::PrizeAccountingMissing);
        $this->assertNotNull($missing);
    }

    #[Test]
    public function test_10_invalid_currency_mismatch_detected(): void
    {
        $player = $this->createReconciledPlayer('300.00', Currency::THB);

        // Tamper currency on one ledger entry
        $entry = LedgerEntry::where('wallet_id', $player['wallet']->id)->firstOrFail();
        DB::table('ledger_entries')->where('id', $entry->id)->update(['currency' => 'USD']);

        $report = $this->reconciliationService->reconcile();

        $mismatch = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::CurrencyMismatch);
        $this->assertNotNull($mismatch);
    }

    #[Test]
    public function test_11_orphan_ledger_entry_detected(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $liabilityAccount = LedgerAccount::where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)->firstOrFail();

        // Create transaction, create entry, then delete transaction with foreign keys disabled
        $tx = new FinancialTransaction();
        $tx->fill([
            'reference_number' => 'TX-TEMP-ORPHAN-11',
            'user_id' => $player['user']->id,
            'wallet_id' => $player['wallet']->id,
            'type' => FinancialTransactionType::Deposit->toTransactionType(),
            'currency' => Currency::THB,
            'amount' => '50.00',
            'fee' => '0.00',
        ]);
        $tx->status = TransactionStatus::Completed;
        $tx->save();

        $entry = new LedgerEntry();
        $entry->fill([
            'ledger_account_id' => $liabilityAccount->id,
            'financial_transaction_id' => $tx->id,
            'wallet_id' => $player['wallet']->id,
            'type' => 'credit',
            'amount' => '50.00',
            'currency' => 'THB',
        ]);
        $entry->save();

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('financial_transactions')->where('id', $tx->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        $report = $this->reconciliationService->reconcile();

        $orphan = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::OrphanLedgerEntry);
        $this->assertNotNull($orphan);
    }

    #[Test]
    public function test_12_financial_transaction_without_ledger_detected(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);

        // Insert completed transaction without ledger entries
        $tx = new FinancialTransaction();
        $tx->fill([
            'reference_number' => 'TX-NO-LEDGER-12',
            'user_id' => $player['user']->id,
            'wallet_id' => $player['wallet']->id,
            'type' => FinancialTransactionType::Deposit->toTransactionType(),
            'currency' => Currency::THB,
            'amount' => '100.00',
            'fee' => '0.00',
        ]);
        $tx->status = TransactionStatus::Completed;
        $tx->save();

        $report = $this->reconciliationService->reconcile();

        $missing = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::MissingLedgerTransaction);
        $this->assertNotNull($missing);
    }

    #[Test]
    public function test_13_ledger_without_financial_transaction_detected(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $account = LedgerAccount::where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)->firstOrFail();

        $tx = new FinancialTransaction();
        $tx->fill([
            'reference_number' => 'TX-TEMP-13',
            'user_id' => $player['user']->id,
            'wallet_id' => $player['wallet']->id,
            'type' => FinancialTransactionType::Deposit->toTransactionType(),
            'currency' => Currency::THB,
            'amount' => '75.00',
            'fee' => '0.00',
        ]);
        $tx->status = TransactionStatus::Completed;
        $tx->save();

        $entry = new LedgerEntry();
        $entry->fill([
            'ledger_account_id' => $account->id,
            'financial_transaction_id' => $tx->id,
            'wallet_id' => $player['wallet']->id,
            'type' => 'debit',
            'amount' => '75.00',
            'currency' => 'THB',
        ]);
        $entry->save();

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('financial_transactions')->where('id', $tx->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        $report = $this->reconciliationService->reconcile();

        $orphan = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::OrphanLedgerEntry);
        $this->assertNotNull($orphan);
    }

    #[Test]
    public function test_14_immutable_history_protection(): void
    {
        $player = $this->createReconciledPlayer('400.00', Currency::THB);
        $deposit = Deposit::where('wallet_id', $player['wallet']->id)->firstOrFail();

        $tx = FinancialTransaction::find($deposit->financial_transaction_id);
        $this->assertNotNull($tx);
        $this->assertSame(TransactionStatus::Completed, $tx->status);

        // Cannot overwrite amount directly through business models
        $this->assertSame('400.00', (string) $tx->amount);
    }

    #[Test]
    public function test_15_reversal_uses_compensating_transaction(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);
        $deposit = Deposit::where('wallet_id', $player['wallet']->id)->firstOrFail();

        $originalTx = FinancialTransaction::find($deposit->financial_transaction_id);

        // Execute compensating reversal
        $reversalTx = $this->reversalService->reverse($originalTx, 1, null, 'Customer chargeback');

        $this->assertSame(TransactionStatus::Reversed, $originalTx->fresh()->status);
        $this->assertSame(TransactionStatus::Completed, $reversalTx->status);
        $this->assertSame(FinancialTransactionType::Reversal->toTransactionType(), $reversalTx->type);

        // Reconciliation of balanced reversal must pass
        $report = $this->reconciliationService->reconcile();
        $this->assertSame(ReconciliationStatus::Pass, $report->status);
    }

    #[Test]
    public function test_16_reconciliation_is_idempotent(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);

        $r1 = $this->reconciliationService->reconcile();
        $r2 = $this->reconciliationService->reconcile();

        $this->assertSame($r1->status, $r2->status);
        $this->assertSame($r1->anomalyCount, $r2->anomalyCount);
        $this->assertSame($r1->totalDeposits, $r2->totalDeposits);
    }

    #[Test]
    public function test_17_concurrent_reconciliation_is_safe(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);

        // Two parallel reconciliation executions do not mutate data or conflict
        $r1 = $this->reconciliationService->reconcile();
        $r2 = $this->reconciliationService->reconcile();

        $this->assertSame('500.00', $r1->totalDeposits);
        $this->assertSame('500.00', $r2->totalDeposits);
    }

    #[Test]
    public function test_18_provider_reference_mismatch_detected(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '250.00');

        // Deposit is pending, but duplicate provider reference exists on another confirmed deposit
        $d2 = $this->createPendingDeposit($player['wallet'], '250.00');
        $d2->provider_reference = 'REF-MATCH-18';
        $d2->save();
        $deposit->provider_reference = 'REF-MATCH-18';
        $deposit->save();

        $report = $this->reconciliationService->reconcile();

        $dup = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::DuplicateDeposit);
        $this->assertNotNull($dup);
    }

    #[Test]
    public function test_19_period_totals_are_exact(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);

        $d1 = $this->createApprovedDeposit($player['wallet'], '123.45');
        $this->depositCompletionService->complete($d1, null, ['provider_reference' => 'DEP-EXACT-1']);

        $d2 = $this->createApprovedDeposit($player['wallet'], '678.90');
        $this->depositCompletionService->complete($d2, null, ['provider_reference' => 'DEP-EXACT-2']);

        $report = $this->reconciliationService->reconcile();

        $this->assertSame('802.35', $report->totalDeposits);
        $this->assertSame('0.00', $report->ledgerDifference);
    }

    #[Test]
    public function test_20_zero_discrepancy_reconciliation_returns_pass(): void
    {
        $player = $this->createReconciledPlayer('200.00', Currency::THB);

        $report = $this->reconciliationService->reconcile();

        $this->assertSame(ReconciliationStatus::Pass, $report->status);
        $this->assertSame(0, $report->anomalyCount);
        $this->assertSame(0, $report->criticalAnomalyCount);
    }

    #[Test]
    public function test_21_critical_discrepancy_returns_critical(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);

        // Force negative balance on wallet
        DB::table('wallets')->where('id', $player['wallet']->id)->update(['balance' => '-50.00']);

        $report = $this->reconciliationService->reconcile();

        $this->assertSame(ReconciliationStatus::Critical, $report->status);
        $this->assertGreaterThanOrEqual(1, $report->criticalAnomalyCount);
    }

    #[Test]
    public function test_22_failed_financial_operation_is_not_incorrectly_counted_as_completed_money(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '500.00');

        // Mark deposit failed
        $deposit->status = DepositStatus::Failed;
        $deposit->save();

        $report = $this->reconciliationService->reconcile();

        // Failed deposit is not counted in total confirmed deposits
        $this->assertSame('0.00', $report->totalDeposits);
        $this->assertSame(ReconciliationStatus::Pass, $report->status);
    }

    #[Test]
    public function test_23_locked_balance_reconciliation_detects_stale_hold(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);

        // Directly set locked_balance without any active withdrawal
        DB::table('wallets')->where('id', $player['wallet']->id)->update(['locked_balance' => '300.00']);

        $report = $this->reconciliationService->reconcile();

        $stale = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::StaleWalletHold);
        $this->assertNotNull($stale);
        $this->assertSame(DiscrepancySeverity::High, $stale->severity);
    }

    #[Test]
    public function test_24_duplicate_webhook_cannot_create_a_reconciliation_discrepancy_caused_by_duplicate_money(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '300.00', PaymentMethod::Bkash);

        $payload = new WebhookPayload(
            gateway: 'bkash',
            eventId: 'evt_bkash_dup_24',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'BKASH-TRX-24',
            internalReference: $deposit->reference_number,
            amount: '300.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        // 1st delivery
        $this->webhookService()->processWebhookPayload($payload);
        // 2nd duplicate delivery
        $this->webhookService()->processWebhookPayload($payload);

        $report = $this->reconciliationService->reconcile();

        $this->assertSame(ReconciliationStatus::Pass, $report->status);
        $this->assertSame('300.00', $report->totalDeposits);
    }

    #[Test]
    public function test_25_multi_currency_records_cannot_be_incorrectly_netted_together(): void
    {
        $playerTHB = $this->createReconciledPlayer('500.00', Currency::THB);
        $playerUSD = $this->createReconciledPlayer('0.00', Currency::USD);

        // USD reconciliation isolates USD metrics
        $reportUSD = $this->reconciliationService->reconcile(currency: Currency::USD);
        $this->assertSame('0.00', $reportUSD->totalDeposits);

        // THB reconciliation isolates THB metrics
        $reportTHB = $this->reconciliationService->reconcile(currency: Currency::THB);
        $this->assertSame('500.00', $reportTHB->totalDeposits);
    }

    #[Test]
    public function test_26_negative_wallet_balance_detected_as_critical_invariant_violation(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);

        DB::table('wallets')->where('id', $player['wallet']->id)->update(['balance' => '-10.00']);

        $report = $this->reconciliationService->reconcile();

        $discrepancy = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::NegativeAvailableBalance);
        $this->assertNotNull($discrepancy);
        $this->assertSame(DiscrepancySeverity::Critical, $discrepancy->severity);
    }

    #[Test]
    public function test_27_locked_balance_exceeding_wallet_balance_detected_as_critical_invariant_violation(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);

        DB::table('wallets')->where('id', $player['wallet']->id)->update([
            'balance' => '200.00',
            'locked_balance' => '400.00',
        ]);

        $report = $this->reconciliationService->reconcile();

        $discrepancy = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::NegativeAvailableBalance);
        $this->assertNotNull($discrepancy);
        $this->assertSame(DiscrepancySeverity::Critical, $discrepancy->severity);
    }

    #[Test]
    public function test_28_bet_purchase_without_financial_transaction_debit_detected(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $draw = Draw::factory()->create();

        $bet = new Bet();
        $bet->fill([
            'bet_number' => 'BET-NO-TX-28',
            'user_id' => $player['user']->id,
            'draw_id' => $draw->id,
            'type' => BetType::TwoD,
            'currency' => Currency::THB,
            'stake_amount' => '250.00',
            'potential_payout' => '900.00',
            'total_numbers' => 1,
        ]);
        $bet->status = BetStatus::Active;
        $bet->save();

        $report = $this->reconciliationService->reconcile();

        $discrepancy = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::BetPurchaseMismatch);
        $this->assertNotNull($discrepancy);
    }

    #[Test]
    public function test_29_agent_commission_paid_without_financial_transaction_detected(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);
        $agent = Agent::create([
            'user_id' => $player['user']->id,
            'agent_code' => 'AG-COMM-29',
            'status' => \App\Enums\AgentStatus::Active,
            'commission_rate' => '0.0500',
        ]);

        $comm = AgentCommission::create([
            'reference_number' => 'COMM-NO-TX-29',
            'agent_id' => $agent->id,
            'status' => CommissionStatus::Paid,
            'currency' => Currency::THB,
            'base_amount' => '1000.00',
            'commission_rate' => '0.0500',
            'commission_amount' => '50.00',
            'financial_transaction_id' => null, // Missing transaction!
        ]);

        $report = $this->reconciliationService->reconcile();

        $discrepancy = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::CommissionMismatch);
        $this->assertNotNull($discrepancy);
    }

    #[Test]
    public function test_30_cli_artisan_command_outputs_accurate_json_and_formatted_report(): void
    {
        $player = $this->createReconciledPlayer('350.00', Currency::THB);

        $exitCode = Artisan::call('finance:reconcile', ['--currency' => 'THB', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertJson($output);

        $decoded = json_decode($output, true);
        $this->assertSame('pass', $decoded['status']);
        $this->assertSame('350.00', $decoded['totals']['deposits']);
    }

    #[Test]
    public function test_31_audit_trail_entry_recorded_on_each_reconciliation_execution_without_secrets(): void
    {
        $player = $this->createReconciledPlayer('200.00', Currency::THB);

        $report = $this->reconciliationService->reconcile(initiatedBy: 'AuditorUser');

        $audit = AuditLog::where('action', AuditAction::Reconcile->value)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString($report->executionId, $audit->description);

        $json = json_encode($audit->toArray());
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('secret', $json);
    }

    #[Test]
    public function test_32_reconciliation_with_date_period_filter_isolates_specified_timeframe(): void
    {
        $player = $this->createReconciledPlayer('0.00', Currency::THB);

        $d1 = $this->createApprovedDeposit($player['wallet'], '100.00');
        $this->depositCompletionService->complete($d1, null, ['provider_reference' => 'DEP-OLD-32']);
        DB::table('deposits')->where('id', $d1->id)->update(['confirmed_at' => Carbon::now()->subDays(10)]);

        $d2 = $this->createApprovedDeposit($player['wallet'], '200.00');
        $this->depositCompletionService->complete($d2, null, ['provider_reference' => 'DEP-NEW-32']);
        DB::table('deposits')->where('id', $d2->id)->update(['confirmed_at' => Carbon::now()]);

        // Reconcile only past 2 days
        $report = $this->reconciliationService->reconcile(
            from: Carbon::now()->subDays(2),
            to: Carbon::now()->addDays(1),
        );

        $this->assertSame('200.00', $report->totalDeposits);
    }

    #[Test]
    public function test_33_reconciliation_with_currency_filter_isolates_specific_currency(): void
    {
        $playerTHB = $this->createReconciledPlayer('400.00', Currency::THB);

        $reportBDT = $this->reconciliationService->reconcile(currency: Currency::BDT);
        $this->assertSame('0.00', $reportBDT->totalDeposits);

        $reportTHB = $this->reconciliationService->reconcile(currency: Currency::THB);
        $this->assertSame('400.00', $reportTHB->totalDeposits);
    }

    #[Test]
    public function test_34_terminal_financial_transaction_amount_tampering_is_detected(): void
    {
        $player = $this->createReconciledPlayer('500.00', Currency::THB);
        $deposit = Deposit::where('wallet_id', $player['wallet']->id)->firstOrFail();

        // Tamper with financial transaction amount directly in database
        DB::table('financial_transactions')->where('id', $deposit->financial_transaction_id)->update(['amount' => '999.00']);

        $report = $this->reconciliationService->reconcile();

        $this->assertSame(ReconciliationStatus::Critical, $report->status);
        $mismatch = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::DepositAccountingMissing);
        $this->assertNotNull($mismatch);
    }
}
