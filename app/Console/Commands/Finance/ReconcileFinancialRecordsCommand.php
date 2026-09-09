<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Enums\Currency;
use App\Enums\ReconciliationStatus;
use App\Services\Finance\FinancialReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Artisan command to execute financial reconciliation and accounting control verification.
 */
class ReconcileFinancialRecordsCommand extends Command
{
    protected $signature = 'finance:reconcile
        {--from= : Start date/time (e.g. 2026-09-01 or 2026-09-01T00:00:00)}
        {--to= : End date/time (e.g. 2026-09-04 or 2026-09-04T23:59:59)}
        {--currency= : Filter reconciliation by currency (e.g. THB, USD, BDT)}
        {--json : Output report in structured JSON format}';

    protected $description = 'Execute production-grade financial reconciliation across wallets, transactions, and ledger';

    public function handle(FinancialReconciliationService $reconciliationService): int
    {
        $fromInput = $this->option('from');
        $toInput = $this->option('to');
        $currencyInput = $this->option('currency');
        $jsonOutput = (bool) $this->option('json');

        $from = is_string($fromInput) && trim($fromInput) !== '' ? Carbon::parse($fromInput) : null;
        $to = is_string($toInput) && trim($toInput) !== '' ? Carbon::parse($toInput) : null;
        $currency = is_string($currencyInput) && trim($currencyInput) !== '' ? Currency::tryFrom(strtoupper($currencyInput)) : null;

        if (! $jsonOutput) {
            $this->line('<info>Initiating Financial Reconciliation & Accounting Integrity Verification...</info>');
        }

        $report = $reconciliationService->reconcile(
            from: $from,
            to: $to,
            currency: $currency,
            initiatedBy: 'CLI:finance:reconcile',
        );

        if ($jsonOutput) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT));

            return $report->isCritical() ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->line('================================================================');
        $this->line('             FINANCIAL RECONCILIATION SUMMARY REPORT            ');
        $this->line('================================================================');
        $this->line(sprintf(' Execution ID:      <comment>%s</comment>', $report->executionId));
        $this->line(sprintf(' Status:            %s', match ($report->status) {
            ReconciliationStatus::Pass => '<info>[ PASS ]</info>',
            ReconciliationStatus::Warning => '<comment>[ WARNING ]</comment>',
            ReconciliationStatus::Critical => '<error>[ CRITICAL ]</error>',
        }));
        $this->line(sprintf(' Period:            %s to %s', $report->periodStart?->toDateTimeString() ?? 'Beginning of Time', $report->periodEnd?->toDateTimeString() ?? 'Present'));
        $this->line(sprintf(' Currency Scope:    %s', $report->currency?->value ?? 'ALL (Multi-Currency Segregated)'));
        $this->line(sprintf(' Executed At:       %s', $report->executedAt->toDateTimeString()));
        $this->line('----------------------------------------------------------------');
        $this->line(' METRICS & TOTALS:');
        $this->line(sprintf('   - Total Deposits Confirmed:     %s', $report->totalDeposits));
        $this->line(sprintf('   - Total Bet Purchases Wagered:  %s', $report->totalBetPurchases));
        $this->line(sprintf('   - Total Prize Payouts Won:      %s', $report->totalPrizePayouts));
        $this->line(sprintf('   - Total Withdrawals Settled:    %s', $report->totalWithdrawals));
        $this->line(sprintf('   - Total Agent Commissions Paid: %s', $report->totalCommissions));
        $this->line(sprintf('   - Total Ledger Debits:          %s', $report->totalLedgerDebits));
        $this->line(sprintf('   - Total Ledger Credits:         %s', $report->totalLedgerCredits));
        $this->line(sprintf('   - Double-Entry Difference:      %s', $report->ledgerDifference));
        $this->line('----------------------------------------------------------------');
        $this->line(sprintf(' Anomaly Count:     %d (Critical: %d)', $report->anomalyCount, $report->criticalAnomalyCount));

        if ($report->anomalyCount > 0) {
            $this->newLine();
            $this->line('<error>DISCREPANCIES DETECTED:</error>');
            $rows = [];
            foreach ($report->discrepancies as $d) {
                $rows[] = [
                    $d->category->value,
                    $d->severity->value,
                    $d->entityType,
                    $d->referenceNumber ?? (string) $d->entityId,
                    $d->expectedAmount ?? '-',
                    $d->actualAmount ?? '-',
                    $d->difference ?? '-',
                    $d->description,
                ];
            }
            $this->table(
                ['Category', 'Severity', 'Entity', 'Reference', 'Expected', 'Actual', 'Diff', 'Description'],
                $rows,
            );
        } else {
            $this->newLine();
            $this->line('<info>✔ All financial invariants, wallet balances, and double-entry ledger records are 100% balanced and consistent.</info>');
        }

        return $report->isCritical() ? self::FAILURE : self::SUCCESS;
    }
}
