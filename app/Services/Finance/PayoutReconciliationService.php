<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;

/**
 * Payout-side operational reconciliation.
 *
 * THE DIVISION OF LABOUR WITH FinancialReconciliationService
 * The global reconciliation service sweeps every wallet, every financial
 * transaction, every deposit, every commission: it answers "are the BOOKS
 * balanced?". This service answers the narrower questions that payout
 * operations runs every hour: "is every completed payout accounted for by
 * exactly one completed financial transaction of the same amount?", "is any
 * payout stuck mid-flight?", and "do the payouts table and the prize-expense
 * ledger account tell the same story?".
 *
 * Read-only by construction: this service never writes a payout, a wallet,
 * a ledger row or an audit line. It returns a plain report that an operator
 * (or a scheduled report job, or a health console check) renders. A report
 * whose `status` is not 'pass' names every finding with the ids to act on.
 *
 * CHECKS, IN ORDER
 * ----------------
 * 1. A Completed payout MUST link to a Completed financial transaction of
 *    exactly its own amount and currency. Any of the three otherwise cases is
 *    critical — a payout marked paid without the money necessarily paid, or
 *    paid a different amount than agreed.
 * 2. A Processing payout older than the configured stuck threshold is
 *    suspicious (not proven-wrong, hence High not Critical): it may be a
 *    crashed batch that left a row mid-flight.
 * 3. A Won bet's payout_id must lead to a Completed Payout; every Completed
 *    payout must sit on a Won bet whose payout_id points back at it. The two
 *    directions are checked separately because either one can be broken
 *    independently by a stray manual edit.
 * 4. Financial transactions of type payout with NO payout row linking to them
 *    (orphan credits). A credit with no payout to explain it is money that
 *    left the prize-expense account under no operational authority.
 *
 * Totals are computed in bcmath end-to-end; amounts are decimal strings,
 * never float.
 */
class PayoutReconciliationService
{
    /**
     * Default age in minutes after which a 'processing' payout is reported as
     * stuck. The batch job's own retry window is the source of truth; a row
     * older than the retry window with no progress must be looked at.
     */
    public const DEFAULT_STUCK_PROCESSING_MINUTES = 60;

    public function __construct(
        private readonly ?int $stuckProcessingMinutes = null,
    ) {
    }

    /**
     * Run all payout checks in one report.
     *
     * @return array{
     *     status: string,
     *     totals: array{completed: int, pending: int, processing: int, failed: int, reversed: int, completed_amount: string, currency: string},
     *     findings: list<array{type: string, severity: string, entity_type: string, entity_id: int, expected: string, actual: string, message: string}>,
     *     executed_at: string
     * }
     */
    public function reconcilePayouts(?Currency $currency = null): array
    {
        $currency ??= Currency::THB;
        $findings = [];

        $this->checkCompletedPayouts($currency, $findings);
        $this->checkStuckProcessing($currency, $findings);
        $this->checkBetPayoutLinks($currency, $findings);
        $this->checkOrphanPayoutTransactions($currency, $findings);

        return [
            'status' => $this->statusFor($findings),
            'totals' => $this->totals($currency),
            'findings' => $findings,
            'executed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * A compact sentence for console output or alert titles.
     *
     * @param  array<string, mixed>  $report
     */
    public function describe(array $report): string
    {
        $totals = $report['totals'];

        return sprintf(
            'Payout reconciliation %s: %d completed (%s %s), %d pending, %d processing, %d failed, %d reversed, %d finding(s).',
            $report['status'],
            $totals['completed'],
            $totals['completed_amount'],
            $totals['currency'],
            $totals['pending'],
            $totals['processing'],
            $totals['failed'],
            $totals['reversed'],
            count($report['findings']),
        );
    }

    /**
     * Totals per payout status for one currency. The amount totals are exact
     * decimal strings: bcmath on the raw rows, no SQL SUM on a decimal string.
     *
     * @return array{completed: int, pending: int, processing: int, failed: int, reversed: int, completed_amount: string, currency: string}
     */
    public function totals(Currency $currency): array
    {
        $rows = DB::table('payouts')
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as n, GROUP_CONCAT(amount) as amounts')
            ->groupBy('status')
            ->get();

        $counts = [
            PayoutStatus::Pending->value => 0,
            PayoutStatus::Processing->value => 0,
            PayoutStatus::Completed->value => 0,
            PayoutStatus::Failed->value => 0,
            PayoutStatus::Reversed->value => 0,
        ];
        $completedAmount = '0.00';

        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->n;

            if ($row->status === PayoutStatus::Completed->value) {
                $completedAmount = $this->sumExactList((string) ($row->amounts ?? ''));
            }
        }

        return [
            'completed' => $counts[PayoutStatus::Completed->value],
            'pending' => $counts[PayoutStatus::Pending->value],
            'processing' => $counts[PayoutStatus::Processing->value],
            'failed' => $counts[PayoutStatus::Failed->value],
            'reversed' => $counts[PayoutStatus::Reversed->value],
            'completed_amount' => $completedAmount,
            'currency' => $currency->value,
        ];
    }

    /**
     * CHECK 1 — Completed payout ⇄ exactly-one Completed FT, same amount.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function checkCompletedPayouts(Currency $currency, array &$findings): void
    {
        $payouts = DB::table('payouts')
            ->where('currency', $currency->value)
            ->where('status', PayoutStatus::Completed->value)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($payouts as $payout) {
            $payoutId = (int) $payout->id;

            if ($payout->financial_transaction_id === null) {
                $findings[] = $this->finding(
                    severity: 'critical',
                    type: 'payout_missing_transaction',
                    entityId: $payoutId,
                    expected: (string) $payout->amount,
                    actual: 'transaction NULL',
                    message: sprintf('Completed payout #%d carries no financial transaction link.', $payoutId),
                );

                continue;
            }

            $transaction = DB::table('financial_transactions')
                ->where('id', (int) $payout->financial_transaction_id)
                ->first();

            if ($transaction === null) {
                $findings[] = $this->finding(
                    severity: 'critical',
                    type: 'payout_transaction_broken_link',
                    entityId: $payoutId,
                    expected: (string) $payout->amount,
                    actual: 'transaction missing',
                    message: sprintf(
                        'Completed payout #%d links to financial transaction #%d, which does not exist.',
                        $payoutId,
                        (int) $payout->financial_transaction_id,
                    ),
                );

                continue;
            }

            if ($transaction->status !== 'completed') {
                $findings[] = $this->finding(
                    severity: 'critical',
                    type: 'payout_transaction_not_finished',
                    entityId: $payoutId,
                    expected: 'completed',
                    actual: (string) $transaction->status,
                    message: sprintf(
                        'Completed payout #%d claims financial transaction #%d, whose status is %s.',
                        $payoutId,
                        (int) $transaction->id,
                        (string) $transaction->status,
                    ),
                );
            }

            if (bccomp((string) $transaction->amount, (string) $payout->amount, 2) !== 0) {
                $findings[] = $this->finding(
                    severity: 'critical',
                    type: 'payout_amount_mismatch',
                    entityId: $payoutId,
                    expected: (string) $payout->amount,
                    actual: (string) $transaction->amount,
                    message: sprintf(
                        'Completed payout #%d is recorded for %s but its transaction #%d settles %s.',
                        $payoutId,
                        (string) $payout->amount,
                        (int) $transaction->id,
                        (string) $transaction->amount,
                    ),
                );
            }
        }
    }

    /**
     * CHECK 2 — Processing payouts older than the stuck threshold.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function checkStuckProcessing(Currency $currency, array &$findings): void
    {
        $minutes = $this->stuckProcessingMinutes ?? self::DEFAULT_STUCK_PROCESSING_MINUTES;
        $threshold = now()->subMinutes($minutes);

        $rows = DB::table('payouts')
            ->where('currency', $currency->value)
            ->where('status', PayoutStatus::Processing->value)
            ->whereNotNull('processed_at')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            // A processing payout rows are typically 'Processing' WITHOUT processed_at set;
            // see also the siblings query below for rows that never got stamped.
            if ($row->processed_at < $threshold->toDateTimeString()) {
                $findings[] = $this->finding(
                    severity: 'high',
                    type: 'payout_processing_stalled',
                    entityId: (int) $row->id,
                    expected: 'Completed',
                    actual: sprintf('Processing since %s', (string) $row->processed_at),
                    message: sprintf(
                        'Payout #%d has been processing since %s (over %d minutes); a crashed or stuck batch is the usual cause.',
                        (int) $row->id,
                        (string) $row->processed_at,
                        $minutes,
                    ),
                );
            }
        }

        // Processing rows that NEVER received their processing stamp are stuck
        // by definition: there is no place in the flow where a long-lived
        // unstamped processing row is legitimate.
        $unstammped = DB::table('payouts')
            ->where('currency', $currency->value)
            ->where('status', PayoutStatus::Processing->value)
            ->whereNull('processed_at')
            ->whereNull('deleted_at')
            ->where('created_at', '<', $threshold->toDateTimeString())
            ->orderBy('id')
            ->get();

        foreach ($unstammped as $row) {
            $findings[] = $this->finding(
                severity: 'high',
                type: 'payout_processing_stalled',
                entityId: (int) $row->id,
                expected: 'Completed',
                actual: sprintf('Processing since %s, no processed_at stamp', (string) $row->created_at),
                message: sprintf(
                    'Payout #%d entered Processing at %s and never received its processed_at stamp.',
                    (int) $row->id,
                    (string) $row->created_at,
                ),
            );
        }
    }

    /**
     * CHECK 3 — The two directions of the bet ⇄ payout link.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function checkBetPayoutLinks(Currency $currency, array &$findings): void
    {
        // Direction A: every Completed payout's bet row must be Won and must
        // point payout_id back at the payout.
        $orphaned = DB::table('payouts')
            ->leftJoin('bets', 'bets.id', '=', 'payouts.bet_id')
            ->where('payouts.currency', $currency->value)
            ->where('payouts.status', PayoutStatus::Completed->value)
            ->whereNotNull('payouts.bet_id')
            ->whereNull('payouts.deleted_at')
            ->where(function ($query): void {
                $query->whereNull('bets.id')
                    ->orWhere('bets.status', '!=', 'won')
                    ->orWhereColumn('bets.payout_id', '!=', 'payouts.id');
            })
            ->select(['payouts.id as payout_id', 'payouts.bet_id', 'bets.status as bet_status', 'bets.payout_id as bet_payout_id', 'payouts.amount'])
            ->orderBy('payouts.id')
            ->get();

        foreach ($orphaned as $row) {
            $findings[] = $this->finding(
                severity: 'critical',
                type: 'payout_bet_link_broken',
                entityId: (int) $row->payout_id,
                expected: sprintf('won bet with payout_id=%d', (int) $row->payout_id),
                actual: $row->bet_id === null || $row->bet_status === null
                    ? 'bet missing'
                    : sprintf('bet #%d status=%s payout_id=%s', (int) $row->bet_id, (string) $row->bet_status, $row->bet_payout_id === null ? 'NULL' : (string) $row->bet_payout_id),
                message: sprintf(
                    'Completed payout #%d does not close the loop with its bet %s.',
                    (int) $row->payout_id,
                    $row->bet_id === null ? '(bet row gone)' : sprintf('#%d (status %s, bet-side payout_id %s)', (int) $row->bet_id, (string) $row->bet_status, $row->bet_payout_id === null ? 'NULL' : (string) $row->bet_payout_id),
                ),
            );
        }

        // Direction B: every Won bet with an amount to pay must HAVE a payout
        // and its payout must be Completed. Reuses the payouts.currency scope.
        $unpaid = DB::table('bets')
            ->leftJoin('payouts', 'payouts.id', '=', 'bets.payout_id')
            ->where('bets.currency', $currency->value)
            ->where('bets.status', 'won')
            ->whereNull('bets.deleted_at')
            ->where(function ($query): void {
                $query->whereNull('bets.payout_id')
                    ->orWhereNull('payouts.id')
                    ->where(function ($query): void {
                        $query->where('payouts.status', '!=', 'completed');
                    });
            })
            ->select(['bets.id as bet_id', 'bets.stake_amount', 'bets.actual_payout', 'bets.payout_id', 'payouts.status as payout_status'])
            ->orderBy('bets.id')
            ->get();

        foreach ($unpaid as $row) {
            // Only bets whose actual win exceeds zero carry a payout
            // obligation at all.
            if (bccomp((string) $row->actual_payout, '0.00', 2) <= 0) {
                continue;
            }

            $findings[] = $this->finding(
                severity: 'high',
                type: 'bet_won_without_completed_payout',
                entityId: (int) $row->bet_id,
                expected: (string) $row->actual_payout,
                actual: $row->payout_id === null
                    ? 'no payout row'
                    : sprintf('payout #%s status %s', (string) $row->payout_id, (string) ($row->payout_status ?? 'missing')),
                message: sprintf(
                    'Won bet #%d owes %s %s but its payout %s.',
                    (int) $row->bet_id,
                    (string) $row->actual_payout,
                    $currency->value,
                    $row->payout_id === null ? 'does not exist' : sprintf('(payout #%s) is %s', (string) $row->payout_id, (string) ($row->payout_status ?? 'missing')),
                ),
            );
        }
    }

    /**
     * CHECK 4 — Financial transactions of type payout with no payout row.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function checkOrphanPayoutTransactions(Currency $currency, array &$findings): void
    {
        $rows = DB::table('financial_transactions')
            ->leftJoin('payouts', 'payouts.financial_transaction_id', '=', 'financial_transactions.id')
            ->where('financial_transactions.currency', $currency->value)
            ->where('financial_transactions.type', 'payout')
            ->whereNull('financial_transactions.deleted_at')
            ->whereNull('payouts.id')
            ->select(['financial_transactions.id', 'financial_transactions.amount', 'financial_transactions.status'])
            ->orderBy('financial_transactions.id')
            ->get();

        foreach ($rows as $row) {
            $findings[] = $this->finding(
                severity: 'high',
                type: 'payout_transaction_orphan',
                entityId: (int) $row->id,
                expected: 'payout row',
                actual: sprintf('transaction #%d %s of %s', (int) $row->id, (string) $row->status, (string) $row->amount),
                message: sprintf(
                    'Financial transaction #%d is a %s payout credit of %s %s with no payout to explain it.',
                    (int) $row->id,
                    (string) $row->status,
                    (string) $row->amount,
                    $currency->value,
                ),
            );
        }
    }

    /**
     * @return array{type: string, severity: string, entity_type: string, entity_id: int, expected: string, actual: string, message: string}
     */
    private function finding(
        string $severity,
        string $type,
        int $entityId,
        string $expected,
        string $actual,
        string $message,
    ): array {
        return [
            'type' => $type,
            'severity' => $severity,
            'entity_type' => 'payout',
            'entity_id' => $entityId,
            'expected' => $expected,
            'actual' => $actual,
            'message' => $message,
        ];
    }

    /**
     * The report-level verdict: fail on a critical, warn on anything.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function statusFor(array $findings): string
    {
        foreach ($findings as $finding) {
            if ($finding['severity'] === 'critical') {
                return 'fail';
            }
        }

        return $findings === [] ? 'pass' : 'warn';
    }

    /**
     * Exact-sum of a GROUP_CONCAT'd list of decimal amounts, bcmath only.
     */
    private function sumExactList(string $amounts): string
    {
        $total = '0.00';

        foreach (explode(',', $amounts) as $amount) {
            $amount = trim($amount);

            if ($amount === '') {
                continue;
            }

            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }
}
