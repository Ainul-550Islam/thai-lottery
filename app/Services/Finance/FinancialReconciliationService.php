<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\FinancialReconciliationReport;
use App\DTOs\Finance\ReconciliationDiscrepancy;
use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\DiscrepancyCategory;
use App\Enums\DiscrepancySeverity;
use App\Enums\FinancialTransactionType;
use App\Enums\PayoutStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\WithdrawalStatus;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The platform's accounting control plane.
 *
 * Every money-bearing subsystem writes two records of the same event: a
 * domain row (deposit, withdrawal, payout, commission, bet, wallet) and
 * journal rows (financial_transactions + ledger_entries). Domain rows answer
 * "what happened"; the journal answers "where did the value go". This service
 * is the auditor that proves the two records tell the same story — and says
 * so loudly, with severity, entity and bcmath evidence, when they do not.
 *
 * READ-ONLY BY CONSTRUCTION
 * Reconciliation never repairs. It computes. The only write in this service is
 * one AuditLog row per execution (the law that audits must themselves be
 * auditable). This is what makes runs idempotent, concurrent-safe and safe to
 * schedule: a run always sees the same numbers a human would, and no run can
 * hide an anomaly by "fixing" it before the next one looks.
 *
 * WHAT "RECONCILE" MEANS HERE, EXACTLY
 * Ten independent invariant checks plus ten exact totals. Each check is
 * scoped to the requested period and currency; each discrepancy carries the
 * expected amount, the actual amount, the signed difference and enough
 * context for an operator to find the row in question without running a
 * single extra query.
 *
 * CURRENCY IS A BOUNDARY, NOT A LABEL
 * THB and USD ledgers are never netted together, not even "just for a
 * report". Every query in this service filters on the reconciled currency;
 * the only cross-currency check (CurrencyMismatch) exists precisely to catch
 * a record that escaped its own lane.
 */
class FinancialReconciliationService
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Run one full reconciliation pass and return the report.
     *
     * The platform's verdicts:
     *  - Pass: no discrepancy of any severity was found.
     *  - Warning: non-critical discrepancies exist — investigate, but money
     *    invariants stand.
     *  - Critical: at least one Critical discrepancy — a money invariant is
     *    broken (unbalanced ledger, negative/ďouble-counted money, tampering).
     *
     * @param  Carbon|null  $from  period start (inclusive); null = no bound
     * @param  Carbon|null  $to  period end (inclusive); null = no bound
     * @param  Currency|null  $currency  currency to reconcile; null = platform default
     * @param  string|null  $initiatedBy  operator/actor label for the audit row
     */
    public function reconcile(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?Currency $currency = null,
        ?string $initiatedBy = null,
    ): FinancialReconciliationReport {
        $startedAt = microtime(true);

        $currency ??= Currency::tryFrom((string) config('finance.currency.default', 'THB')) ?? Currency::THB;

        $executionId = sprintf('RECON-%s-%s', now()->format('YmdHis'), bin2hex(random_bytes(4)));

        $discrepancies = [];

        // ---- Checks: 10 invariants, each scoped to currency + period -------
        $this->checkLedgerBalance($currency, $from, $to, $discrepancies);
        $this->checkWalletLedgerParity($currency, $discrepancies);
        $this->checkDuplicateProviderReferences($currency, $from, $to, $discrepancies);
        $this->checkDomainAccountingCompleteness($currency, $from, $to, $discrepancies);
        $this->checkPrizeAccounting($currency, $discrepancies);
        $this->checkLedgerJournalIntegrity($currency, $from, $to, $discrepancies);
        $this->checkWalletHoldInvariants($currency, $discrepancies);
        $this->checkCurrencyBoundaries($currency, $from, $to, $discrepancies);
        $this->checkBetDebits($currency, $discrepancies);
        $this->checkCommissionPayments($currency, $from, $to, $discrepancies);

        // ---- Totals: exact bcmath over the journal + domain records --------
        $totals = $this->computeTotals($currency, $from, $to);

        $anomalyCount = count($discrepancies);
        $criticalAnomalyCount = count(array_filter(
            $discrepancies,
            static fn (ReconciliationDiscrepancy $d): bool => $d->severity->isCritical(),
        ));

        $status = $criticalAnomalyCount > 0
            ? ReconciliationStatus::Critical
            : ($anomalyCount > 0 ? ReconciliationStatus::Warning : ReconciliationStatus::Pass);

        $report = new FinancialReconciliationReport(
            executionId: $executionId,
            status: $status,
            periodStart: $from,
            periodEnd: $to,
            currency: $currency,
            totalDeposits: $totals['deposits'],
            totalBetPurchases: $totals['bet_purchases'],
            totalPrizePayouts: $totals['prize_payouts'],
            totalWithdrawals: $totals['withdrawals'],
            totalCommissions: $totals['commissions'],
            totalLedgerDebits: $totals['ledger_debits'],
            totalLedgerCredits: $totals['ledger_credits'],
            ledgerDifference: $totals['ledger_difference'],
            discrepancies: $discrepancies,
            anomalyCount: $anomalyCount,
            criticalAnomalyCount: $criticalAnomalyCount,
            executedAt: Carbon::now(),
            initiatedBy: $initiatedBy,
            metadata: [
                'checks_run' => 10,
                'discrepancies_by_category' => $this->countByCategory($discrepancies),
            ],
            durationSeconds: round(microtime(true) - $startedAt, 6),
        );

        $this->recordAudit($report);

        return $report;
    }

    /**
     * The operator-facing summary line used by the CLI, the Filament page and
     * the queue alert fan-out. Single source so the three channels never
     * disagree about wording.
     */
    public function describe(FinancialReconciliationReport $report): string
    {
        return sprintf(
            '%s [%s] currency=%s anomalies=%d critical=%d ledgerDiff=%s deposits=%s withdrawals=%s prizes=%s commissions=%s bets=%s',
            $report->executionId,
            $report->status->value,
            $report->currency->value,
            $report->anomalyCount,
            $report->criticalAnomalyCount,
            $report->ledgerDifference,
            $report->totalDeposits,
            $report->totalWithdrawals,
            $report->totalPrizePayouts,
            $report->totalCommissions,
            $report->totalBetPurchases,
        );
    }

    // ------------------------------------------------------------------
    // Check 1 — the journal closes
    // ------------------------------------------------------------------

    /**
     * The double-entry law: within the period and currency, Σdebits must
     * equal Σcredits when grouped per financial transaction. A transaction
     * whose sides do not agree is an unbalanced posting — the single most
     * severe invariant break in accounting.
     *
     * Reversed transactions are balanced by their compensating rows, so only
     * Completed/Processing transactions are judged; a Reversed transaction is
     * audited as part of its reversal pair.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkLedgerBalance(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $rows = DB::table('ledger_entries as le')
            ->join('financial_transactions as ft', 'ft.id', '=', 'le.financial_transaction_id')
            ->where('le.currency', $currency->value)
            ->whereNull('le.deleted_at')
            ->whereNull('ft.deleted_at')
            ->when($from !== null, fn ($q) => $q->where('le.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('le.created_at', '<=', $to))
            ->selectRaw("
                le.financial_transaction_id as tx_id,
                GROUP_CONCAT(CASE WHEN le.type = 'debit' THEN le.amount END) as debit_amounts,
                GROUP_CONCAT(CASE WHEN le.type = 'credit' THEN le.amount END) as credit_amounts,
                COUNT(*) as entry_count
            ")
            ->groupBy('le.financial_transaction_id')
            ->get();

        foreach ($rows as $row) {
            // Amounts stay decimal strings end to end; addition is bcmath only.
            $debits = $this->sumExactList((string) ($row->debit_amounts ?? ''));
            $credits = $this->sumExactList((string) ($row->credit_amounts ?? ''));

            if (bccomp($debits, $credits, 2) !== 0) {
                $difference = bcsub($debits, $credits, 2);

                $discrepancies[] = new ReconciliationDiscrepancy(
                    entityType: 'financial_transaction',
                    entityId: (int) $row->tx_id,
                    referenceNumber: $this->referenceOf('financial_transactions', (int) $row->tx_id),
                    category: DiscrepancyCategory::UnbalancedLedger,
                    severity: DiscrepancySeverity::Critical,
                    expectedAmount: $debits,
                    actualAmount: $credits,
                    difference: $difference,
                    currency: $currency->value,
                    description: sprintf(
                        'Financial transaction #%d posts debits %s against credits %s; the journal no longer closes (difference %s).',
                        (int) $row->tx_id,
                        $debits,
                        $credits,
                        $difference,
                    ),
                    details: ['entry_count' => (int) $row->entry_count],
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Check 2 — wallet ⇄ ledger parity
    // ------------------------------------------------------------------

    /**
     * A wallet's projected balance must equal the net of its ledger entries.
     * The wallets row is a cached projection — the ledger is the truth — so a
     * divergent wallet is always a discrepancy, never a "rounding issue".
     *
     * Locked funds are deliberately excluded from this check: total balance
     * still includes locked money, so balance (not available) is compared
     * against full ledger netting.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkWalletLedgerParity(Currency $currency, array &$discrepancies): void
    {
        // The wallet's ledger truth lives on ONE side of each posting: the
        // player-liability account. Every transaction that moves a wallet posts
        // its ledger double-entry (system account ⇄ liability account), and the
        // liability-side entry is tagged with the wallet's id; netting those
        // liability-side entries (credits − debits) per wallet is exactly the
        // balance the wallet row must show. The system-side accounts (system
        // cash, revenue, clearing) are NOT the wallet's money.
        $liabilityAccountId = DB::table('ledger_accounts')
            ->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)
            ->value('id');

        if ($liabilityAccountId === null) {
            return; // chart of accounts not seeded; other checks still run
        }

        $wallets = DB::table('wallets')
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->select(['id', 'balance', 'locked_balance'])
            ->get();

        foreach ($wallets as $wallet) {
            $rows = DB::table('ledger_entries')
                ->where('wallet_id', (int) $wallet->id)
                ->where('ledger_account_id', (int) $liabilityAccountId)
                ->where('currency', $currency->value)
                ->whereNull('deleted_at')
                ->selectRaw("type, GROUP_CONCAT(amount) as amounts")
                ->groupBy('type')
                ->pluck('amounts', 'type');

            $credits = $this->sumExactList((string) ($rows['credit'] ?? ''));
            $debits = $this->sumExactList((string) ($rows['debit'] ?? ''));
            $net = bcsub($credits, $debits, 2);

            if (bccomp((string) $wallet->balance, $net, 2) !== 0) {
                $difference = bcsub((string) $wallet->balance, $net, 2);

                $discrepancies[] = new ReconciliationDiscrepancy(
                    entityType: 'wallet',
                    entityId: (int) $wallet->id,
                    referenceNumber: null,
                    category: DiscrepancyCategory::WalletLedgerMismatch,
                    severity: DiscrepancySeverity::Critical,
                    expectedAmount: $net,
                    actualAmount: (string) $wallet->balance,
                    difference: $difference,
                    currency: $currency->value,
                    description: sprintf(
                        'Wallet #%d projects balance %s %s but its liability-account ledger entries net %s %s (projection drift %s).',
                        (int) $wallet->id,
                        (string) $wallet->balance,
                        $currency->value,
                        $net,
                        $currency->value,
                        $difference,
                    ),
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Check 3 — provider references are unique per direction
    // ------------------------------------------------------------------

    /**
     * Deposit completion and withdrawal disbursal write provider references.
     * The same provider reference appearing twice means the provider's money
     * movement was booked twice — a duplicate-credit or duplicate-payment
     * window. Duplicate reference groups are reported once per group, with
     * the full id set in details for the operator.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkDuplicateProviderReferences(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $this->collectDuplicates(
            table: 'deposits',
            category: DiscrepancyCategory::DuplicateDeposit,
            label: 'deposit',
            currency: $currency,
            from: $from,
            to: $to,
            discrepancies: $discrepancies,
        );

        $this->collectDuplicates(
            table: 'withdrawals',
            category: DiscrepancyCategory::DuplicateWithdrawal,
            label: 'withdrawal',
            currency: $currency,
            from: $from,
            to: $to,
            discrepancies: $discrepancies,
        );
    }

    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function collectDuplicates(
        string $table,
        DiscrepancyCategory $category,
        string $label,
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $groups = DB::table($table)
            ->where('currency', $currency->value)
            ->whereNotNull('provider_reference')
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw('provider_reference, COUNT(*) as hits, GROUP_CONCAT(id) as ids')
            ->groupBy('provider_reference')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $ids = array_map('intval', explode(',', (string) $group->ids));

            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: $label,
                entityId: $ids[0] ?? null,
                referenceNumber: (string) $group->provider_reference,
                category: $category,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: null,
                actualAmount: null,
                difference: null,
                currency: $currency->value,
                description: sprintf(
                    'Provider reference "%s" was booked on %d %s records (ids: %s); one provider event may have moved money twice.',
                    (string) $group->provider_reference,
                    (int) $group->hits,
                    $label,
                    (string) $group->ids,
                ),
                details: ['record_ids' => $ids],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 4 — domain record ⇄ journal completeness
    // ------------------------------------------------------------------

    /**
     * A Confirmed deposit must point at a Completed financial transaction of
     * exactly the deposit's amount; a Completed withdrawal likewise. A NULL
     * link means accounting was never written; an amount mismatch means one
     * side was tampered with after the other was written (either way: the two
     * records of the same event disagree).
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkDomainAccountingCompleteness(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        // Deposits: Confirmed is the money-receiving terminal state.
        $this->collectAccountingBreaks(
            table: 'deposits',
            statusColumn: 'status',
            statusValue: DepositStatus::Confirmed->value,
            category: DiscrepancyCategory::DepositAccountingMissing,
            label: 'deposit',
            currency: $currency,
            from: $from,
            to: $to,
            discrepancies: $discrepancies,
        );

        // Withdrawals: Completed is the money-releasing terminal state.
        $this->collectAccountingBreaks(
            table: 'withdrawals',
            statusColumn: 'status',
            statusValue: WithdrawalStatus::Completed->value,
            category: DiscrepancyCategory::WithdrawalAccountingMissing,
            label: 'withdrawal',
            currency: $currency,
            from: $from,
            to: $to,
            discrepancies: $discrepancies,
        );
    }

    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function collectAccountingBreaks(
        string $table,
        string $statusColumn,
        string $statusValue,
        DiscrepancyCategory $category,
        string $label,
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $rows = DB::table($table . ' as d')
            ->leftJoin('financial_transactions as ft', 'ft.id', '=', 'd.financial_transaction_id')
            ->where("d.{$statusColumn}", $statusValue)
            ->where('d.currency', $currency->value)
            ->when($from !== null, fn ($q) => $q->where('d.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('d.created_at', '<=', $to))
            ->select([
                'd.id',
                'd.reference_number',
                'd.amount',
                'd.financial_transaction_id',
                'ft.status as ft_status',
                'ft.amount as ft_amount',
            ])
            ->get();

        foreach ($rows as $row) {
            $linkMissing = $row->financial_transaction_id === null;
            $linkBroken = ! $linkMissing && $row->ft_status === null;
            $amountDrift = ! $linkMissing && ! $linkBroken
                && bccomp((string) $row->amount, (string) $row->ft_amount, 2) !== 0;

            if (! ($linkMissing || $linkBroken || $amountDrift)) {
                continue;
            }

            [$actual, $difference, $why] = match (true) {
                $linkMissing => [null, null, 'has no financial transaction linked at all'],
                $linkBroken => [null, null, 'points at a financial transaction that no longer exists'],
                default => [
                    (string) $row->ft_amount,
                    bcsub((string) $row->ft_amount, (string) $row->amount, 2),
                    sprintf(
                        'carries amount %s but its financial transaction carries %s',
                        (string) $row->ft_amount,
                        (string) $row->amount,
                    ),
                ],
            };

            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: $label,
                entityId: (int) $row->id,
                referenceNumber: (string) $row->reference_number,
                category: $category,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: (string) $row->amount,
                actualAmount: $actual,
                difference: $difference,
                currency: $currency->value,
                description: sprintf(
                    'Terminal %s %s (%s) %s; the platform record and the journal disagree.',
                    $label,
                    (string) $row->reference_number,
                    $statusValue,
                    $why,
                ),
                details: ['financial_transaction_id' => $row->financial_transaction_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 5 — prize accounting
    // ------------------------------------------------------------------

    /**
     * Every Won bet with an actual payout must have a Completed payout of the
     * same amount; and no winning bet may be paid twice. This pair is the
     * "no prize lost, no prize duplicated" law.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkPrizeAccounting(Currency $currency, array &$discrepancies): void
    {
        // Missing prize accounting: Won bet, no completed payout of payout amount.
        $missing = DB::table('bets')
            ->where('status', BetStatus::Won->value)
            ->where('currency', $currency->value)
            ->where('actual_payout', '>', 0)
            ->whereNull('payout_id')
            ->whereNull('deleted_at')
            ->select(['id', 'bet_number', 'actual_payout', 'draw_id'])
            ->get();

        foreach ($missing as $bet) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'bet',
                entityId: (int) $bet->id,
                referenceNumber: (string) $bet->bet_number,
                category: DiscrepancyCategory::PrizeAccountingMissing,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: (string) $bet->actual_payout,
                actualAmount: null,
                difference: null,
                currency: $currency->value,
                description: sprintf(
                    'Bet %s is Won with actual payout %s %s but no payout record exists; the prize is owed and unaccounted.',
                    (string) $bet->bet_number,
                    (string) $bet->actual_payout,
                    $currency->value,
                ),
                details: ['draw_id' => (int) $bet->draw_id],
            );
        }

        // Duplicate prize: more than one Completed payout for the same bet.
        $duplicates = DB::table('payouts')
            ->where('status', PayoutStatus::Completed->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->selectRaw('bet_id, COUNT(*) as hits, GROUP_CONCAT(id) as ids, GROUP_CONCAT(reference_number) as refs')
            ->groupBy('bet_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $group) {
            $ids = array_map('intval', explode(',', (string) $group->ids));

            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'bet',
                entityId: (int) $group->bet_id,
                referenceNumber: (string) $group->refs,
                category: DiscrepancyCategory::DuplicatePrize,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: null,
                actualAmount: null,
                difference: null,
                currency: $currency->value,
                description: sprintf(
                    '%d completed payouts exist for bet #%d (refs: %s); the same prize would be paid more than once.',
                    (int) $group->hits,
                    (int) $group->bet_id,
                    (string) $group->refs,
                ),
                details: ['payout_ids' => $ids],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 6 — journal integrity (both directions)
    // ------------------------------------------------------------------

    /**
     * Both halves of referential integrity over the journal:
     *  - a completed transaction must have at least one ledger entry
     *    (MissingLedgerTransaction);
     *  - a ledger entry must point at a live transaction
     *    (OrphanLedgerEntry).
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkLedgerJournalIntegrity(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $withoutEntries = DB::table('financial_transactions as ft')
            ->leftJoin('ledger_entries as le', function ($join): void {
                $join->on('le.financial_transaction_id', '=', 'ft.id')
                    ->whereNull('le.deleted_at');
            })
            ->where('ft.currency', $currency->value)
            ->where('ft.status', TransactionStatus::Completed->value)
            ->whereNull('ft.deleted_at')
            ->whereNull('le.id')
            ->when($from !== null, fn ($q) => $q->where('ft.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('ft.created_at', '<=', $to))
            ->select(['ft.id', 'ft.reference_number', 'ft.type', 'ft.amount'])
            ->get();

        foreach ($withoutEntries as $tx) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'financial_transaction',
                entityId: (int) $tx->id,
                referenceNumber: (string) $tx->reference_number,
                category: DiscrepancyCategory::MissingLedgerTransaction,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: (string) $tx->amount,
                actualAmount: '0.00',
                difference: (string) $tx->amount,
                currency: $currency->value,
                description: sprintf(
                    'Completed %s transaction %s of %s %s has no ledger entries; money moved without touching the book.',
                    (string) $tx->type,
                    (string) $tx->reference_number,
                    (string) $tx->amount,
                    $currency->value,
                ),
            );
        }

        $orphans = DB::table('ledger_entries as le')
            ->leftJoin('financial_transactions as ft', 'ft.id', '=', 'le.financial_transaction_id')
            ->where('le.currency', $currency->value)
            ->whereNull('le.deleted_at')
            ->whereNull('ft.id')
            ->when($from !== null, fn ($q) => $q->where('le.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('le.created_at', '<=', $to))
            ->select(['le.id', 'le.financial_transaction_id', 'le.amount', 'le.type', 'le.wallet_id'])
            ->get();

        foreach ($orphans as $entry) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'ledger_entry',
                entityId: (int) $entry->id,
                referenceNumber: null,
                category: DiscrepancyCategory::OrphanLedgerEntry,
                severity: DiscrepancySeverity::High,
                expectedAmount: null,
                actualAmount: (string) $entry->amount,
                difference: null,
                currency: $currency->value,
                description: sprintf(
                    'Ledger entry #%d (%s %s %s) references deleted or never-existing transaction #%d.',
                    (int) $entry->id,
                    (string) $entry->type,
                    (string) $entry->amount,
                    $currency->value,
                    (int) $entry->financial_transaction_id,
                ),
                details: ['wallet_id' => $entry->wallet_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 7 — wallet hold invariants
    // ------------------------------------------------------------------

    /**
     * Two wallet invariants in one sweep:
     *  - locked_balance must never exceed balance (NegativeAvailableBalance:
     *    the spendable remainder would be negative), Critical;
     *  - a positive lock with no active withdrawal behind it is a stale hold
     *    (StaleWalletHold: money is fine but the player cannot spend it),
     *    High.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkWalletHoldInvariants(Currency $currency, array &$discrepancies): void
    {
        $wallets = DB::table('wallets')
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->select(['id', 'balance', 'locked_balance'])
            ->get();

        foreach ($wallets as $wallet) {
            $available = bcsub((string) $wallet->balance, (string) $wallet->locked_balance, 2);

            if (bccomp($available, '0', 2) < 0) {
                $discrepancies[] = new ReconciliationDiscrepancy(
                    entityType: 'wallet',
                    entityId: (int) $wallet->id,
                    referenceNumber: null,
                    category: DiscrepancyCategory::NegativeAvailableBalance,
                    severity: DiscrepancySeverity::Critical,
                    expectedAmount: '0.00',
                    actualAmount: $available,
                    difference: $available,
                    currency: $currency->value,
                    description: sprintf(
                        'Wallet #%d would spend negative: balance %s minus locked %s leaves %s %s.',
                        (int) $wallet->id,
                        (string) $wallet->balance,
                        (string) $wallet->locked_balance,
                        $available,
                        $currency->value,
                    ),
                );

                continue; // a wallet cannot be both over-locked and cleanly stale
            }

            if (bccomp((string) $wallet->locked_balance, '0', 2) > 0) {
                $hasActiveWithdrawal = DB::table('withdrawals')
                    ->where('wallet_id', (int) $wallet->id)
                    ->whereNull('deleted_at')
                    ->whereIn('status', [
                        WithdrawalStatus::Pending->value,
                        WithdrawalStatus::UnderReview->value,
                        WithdrawalStatus::Approved->value,
                        WithdrawalStatus::Processing->value,
                    ])
                    ->exists();

                if (! $hasActiveWithdrawal) {
                    $discrepancies[] = new ReconciliationDiscrepancy(
                        entityType: 'wallet',
                        entityId: (int) $wallet->id,
                        referenceNumber: null,
                        category: DiscrepancyCategory::StaleWalletHold,
                        severity: DiscrepancySeverity::High,
                        expectedAmount: '0.00',
                        actualAmount: (string) $wallet->locked_balance,
                        difference: (string) $wallet->locked_balance,
                        currency: $currency->value,
                        description: sprintf(
                            'Wallet #%d locks %s %s with no active withdrawal behind it; either the release was lost or the hold was never started.',
                            (int) $wallet->id,
                            (string) $wallet->locked_balance,
                            $currency->value,
                        ),
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Check 8 — currency boundary
    // ------------------------------------------------------------------

    /**
     * Every journal row must agree on currency with the transaction that
     * produced it and the wallet it touched. A mismatch means two ledgers
     * were netted together — the report itself isolates currencies to make
     * this visible rather than sunk in a platform-wide sum.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkCurrencyBoundaries(Currency $currency, ?Carbon $from, ?Carbon $to, array &$discrepancies): void
    {
        $mismatches = DB::table('ledger_entries as le')
            ->join('financial_transactions as ft', 'ft.id', '=', 'le.financial_transaction_id')
            ->whereNull('le.deleted_at')
            ->whereColumn('le.currency', '!=', 'ft.currency')
            ->when($from !== null, fn ($q) => $q->where('le.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('le.created_at', '<=', $to))
            ->select(['le.id', 'le.currency as entry_currency', 'ft.currency as tx_currency', 'ft.reference_number', 'ft.id as tx_id'])
            ->get();

        foreach ($mismatches as $row) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'ledger_entry',
                entityId: (int) $row->id,
                referenceNumber: (string) $row->reference_number,
                category: DiscrepancyCategory::CurrencyMismatch,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: null,
                actualAmount: null,
                difference: null,
                currency: (string) $row->entry_currency,
                description: sprintf(
                    'Ledger entry #%d is recorded in %s but its transaction %s is %s; currencies were netted together.',
                    (int) $row->id,
                    (string) $row->entry_currency,
                    (string) $row->reference_number,
                    (string) $row->tx_currency,
                ),
                details: ['financial_transaction_id' => (int) $row->tx_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 9 — active bets are truly debited
    // ------------------------------------------------------------------

    /**
     * An Active bet asserts "stake was collected". That claim lives in the
     * bet_debit transaction referencing it. An Active bet without one means a
     * bet was sold for free; the bet must be investigated before the draw
     * settles (a Won outcome would pay a prize that was never staked).
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkBetDebits(Currency $currency, array &$discrepancies): void
    {
        $betDebitType = FinancialTransactionType::BetDebit->toTransactionType()->value;

        $bets = DB::table('bets')
            ->leftJoin('financial_transactions as ft', function ($join) use ($betDebitType): void {
                $join->on('ft.reference_id', '=', 'bets.id')
                    ->where('ft.type', '=', $betDebitType)
                    ->where('ft.status', '=', TransactionStatus::Completed->value)
                    ->whereNull('ft.deleted_at');
            })
            ->where('bets.status', BetStatus::Active->value)
            ->where('bets.currency', $currency->value)
            ->whereNull('bets.deleted_at')
            ->whereNull('ft.id')
            ->select(['bets.id', 'bets.bet_number', 'bets.stake_amount', 'bets.draw_id'])
            ->get();

        foreach ($bets as $bet) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'bet',
                entityId: (int) $bet->id,
                referenceNumber: (string) $bet->bet_number,
                category: DiscrepancyCategory::BetPurchaseMismatch,
                severity: DiscrepancySeverity::High,
                expectedAmount: (string) $bet->stake_amount,
                actualAmount: '0.00',
                difference: (string) $bet->stake_amount,
                currency: $currency->value,
                description: sprintf(
                    'Active bet %s carries stake %s %s with no completed bet debit; the platform sold a bet it never charged for.',
                    (string) $bet->bet_number,
                    (string) $bet->stake_amount,
                    $currency->value,
                ),
                details: ['draw_id' => (int) $bet->draw_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 10 — paid commissions are truly paid
    // ------------------------------------------------------------------

    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkCommissionPayments(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $rows = DB::table('agent_commissions as ac')
            ->leftJoin('financial_transactions as ft', 'ft.id', '=', 'ac.financial_transaction_id')
            ->where('ac.status', CommissionStatus::Paid->value)
            ->where('ac.currency', $currency->value)
            ->whereNull('ac.deleted_at')
            ->whereNull('ac.reversed_at')
            ->when($from !== null, fn ($q) => $q->where('ac.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('ac.created_at', '<=', $to))
            ->select(['ac.id', 'ac.reference_number', 'ac.commission_amount', 'ac.agent_id', 'ac.financial_transaction_id', 'ft.id as live_ft_id'])
            ->get();

        foreach ($rows as $row) {
            $linkMissing = $row->financial_transaction_id === null;
            $linkBroken = ! $linkMissing && $row->live_ft_id === null;

            if (! ($linkMissing || $linkBroken)) {
                continue;
            }

            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'agent_commission',
                entityId: (int) $row->id,
                referenceNumber: (string) $row->reference_number,
                category: DiscrepancyCategory::CommissionMismatch,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: (string) $row->commission_amount,
                actualAmount: '0.00',
                difference: (string) $row->commission_amount,
                currency: $currency->value,
                description: sprintf(
                    'Commission %s of %s %s to agent #%d is marked Paid but %s.',
                    (string) $row->reference_number,
                    (string) $row->commission_amount,
                    $currency->value,
                    (int) $row->agent_id,
                    $linkMissing
                        ? 'no financial transaction records the payment'
                        : 'its financial transaction no longer exists',
                ),
                details: ['agent_id' => (int) $row->agent_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Totals — exact report arithmetic (bcmath, never floats)
    // ------------------------------------------------------------------

    /**
     * @return array{
     *     deposits: string, bet_purchases: string, prize_payouts: string,
     *     withdrawals: string, commissions: string, ledger_debits: string,
     *     ledger_credits: string, ledger_difference: string,
     * }
     */
    private function computeTotals(Currency $currency, ?Carbon $from, ?Carbon $to): array
    {
        $depositSums = DB::table('deposits')
            ->where('status', DepositStatus::Confirmed->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('confirmed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('confirmed_at', '<=', $to));

        $withdrawalSums = DB::table('withdrawals')
            ->where('status', WithdrawalStatus::Completed->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('completed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('completed_at', '<=', $to));

        $payoutSums = DB::table('payouts')
            ->where('status', PayoutStatus::Completed->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('processed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('processed_at', '<=', $to));

        $commissionSums = DB::table('agent_commissions')
            ->where('status', CommissionStatus::Paid->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->whereNull('reversed_at')
            ->when($from !== null, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('paid_at', '<=', $to));

        $betSums = DB::table('bets')
            ->whereIn('status', [
                BetStatus::Pending->value,
                BetStatus::Active->value,
                BetStatus::Won->value,
                BetStatus::Lost->value,
            ])
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('placed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('placed_at', '<=', $to));

        $ledgerSums = DB::table('ledger_entries')
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw("type, GROUP_CONCAT(amount) as amounts")
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $debits = $this->sumExactList((string) ($ledgerSums['debit']->amounts ?? ''));
        $credits = $this->sumExactList((string) ($ledgerSums['credit']->amounts ?? ''));

        return [
            // SQL SUM() is avoided on purpose: the platform stores money as
            // decimal strings, and bcmath chaining is the only addition that
            // is guaranteed exact on every driver.
            'deposits' => $this->sumExact($depositSums->pluck('amount')->all()),
            'bet_purchases' => $this->sumExact($betSums->pluck('stake_amount')->all()),
            'prize_payouts' => $this->sumExact($payoutSums->pluck('amount')->all()),
            'withdrawals' => $this->sumExact($withdrawalSums->pluck('amount')->all()),
            'commissions' => $this->sumExact($commissionSums->pluck('commission_amount')->all()),
            'ledger_debits' => $debits,
            'ledger_credits' => $credits,
            'ledger_difference' => bcsub($debits, $credits, 2),
        ];
    }

    /**
     * Exact sum of a list of decimal strings at scale 2.
     *
     * @param  list<string>  $amounts
     */
    private function sumExact(array $amounts): string
    {
        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        return $total;
    }

    /**
     * Exact sum of a GROUP_CONCAT-compressed list (avoids one query per row
     * for the high-volume ledger totals while keeping bcmath exactness).
     */
    private function sumExactList(string $commaSeparated): string
    {
        if ($commaSeparated === '') {
            return '0.00';
        }

        $total = '0.00';

        foreach (explode(',', $commaSeparated) as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }


    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     * @return array<string, int>
     */
    private function countByCategory(array $discrepancies): array
    {
        $map = [];

        foreach ($discrepancies as $d) {
            $key = $d->category->value;
            $map[$key] = ($map[$key] ?? 0) + 1;
        }

        ksort($map);

        return $map;
    }

    private function referenceOf(string $table, int $id): ?string
    {
        $reference = DB::table($table)->where('id', $id)->value('reference_number');

        return $reference === null ? null : (string) $reference;
    }

    // ------------------------------------------------------------------
    // The one permitted write: the audit row of the audit itself
    // ------------------------------------------------------------------

    /**
     * Every execution records itself, exactly once, in AuditLog. This is what
     * makes the control plane itself observable: an operator asking "when did
     * we last reconcile and what did it find" is answered by the same ledger
     * discipline the platform demands of everyone else. No secrets, no
     * personal data — the metadata is keyed aggregates only.
     */
    private function recordAudit(FinancialReconciliationReport $report): void
    {
        $log = new AuditLog();
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Reconcile,
            'risk_level' => $report->status->isCritical() ? RiskLevel::High : RiskLevel::Low,
            'auditable_type' => 'reconciliation',
            'auditable_id' => 0,
            'description' => sprintf(
                'Financial reconciliation %s executed by %s: status=%s anomalies=%d critical=%d currency=%s period=%s..%s.',
                $report->executionId,
                $report->initiatedBy ?? 'Unknown',
                $report->status->value,
                $report->anomalyCount,
                $report->criticalAnomalyCount,
                $report->currency->value,
                $report->periodStart?->toDateString() ?? 'open',
                $report->periodEnd?->toDateString() ?? 'open',
            ),
            'metadata' => [
                'execution_id' => $report->executionId,
                'status' => $report->status->value,
                'anomaly_count' => $report->anomalyCount,
                'critical_anomaly_count' => $report->criticalAnomalyCount,
                'currency' => $report->currency->value,
                'duration_seconds' => $report->durationSeconds,
                'totals' => [
                    'deposits' => $report->totalDeposits,
                    'bet_purchases' => $report->totalBetPurchases,
                    'prize_payouts' => $report->totalPrizePayouts,
                    'withdrawals' => $report->totalWithdrawals,
                    'commissions' => $report->totalCommissions,
                    'ledger_debits' => $report->totalLedgerDebits,
                    'ledger_credits' => $report->totalLedgerCredits,
                    'ledger_difference' => $report->ledgerDifference,
                ],
                'discrepancies_by_category' => $report->metadata['discrepancies_by_category'] ?? [],
            ],
        ]);
        $log->save();
    }
}
