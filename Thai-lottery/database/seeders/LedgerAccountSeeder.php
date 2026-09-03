<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LedgerAccount;
use App\Services\Finance\WalletService;
use Illuminate\Database\Seeder;

/**
 * The chart of accounts.
 *
 * WHY THIS EXISTS
 * Every financial movement in this application is posted double-entry through
 * LedgerPostingService, which resolves an account by its code and throws
 * `ledger_account_missing` when it is absent — its message reads "Seed the chart of
 * accounts before posting financial transactions." Until this file existed, nothing did.
 * A fresh install could therefore migrate cleanly, register a player, and then fail on the
 * very first deposit with what looks like an internal error, because the eight account
 * codes WalletService declares as constants existed only as PHP constants and never as
 * rows. This seeder closes that gap: the codes the code posts to and the codes the
 * database holds are now generated from the same eight constants, so they cannot drift.
 *
 * WHY THE CONSTANTS ARE THE SOURCE OF TRUTH
 * Each entry below is keyed by `WalletService::ACCOUNT_*` rather than by a literal string.
 * If someone renames a code in WalletService without updating this file, the seeder writes
 * the new code and the old row simply stops being used — a visible, inspectable state. If
 * the codes were duplicated as literals here, the two would disagree silently and the
 * failure would surface only in production, on a real deposit.
 *
 * IDEMPOTENT BY CONSTRUCTION
 * Uses updateOrCreate keyed on `code`, which is uniquely indexed. Running this seeder on a
 * populated production database refreshes the descriptive fields (name, type, description)
 * and touches nothing else. It deliberately does NOT write `opening_balance` or
 * `current_balance` on an existing row: those are posting-derived state owned by
 * LedgerPostingService, and a seeder that reset them would silently destroy the audit
 * position of a live ledger. New rows get the schema default of 0.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No parent/child hierarchy is built. The schema supports `parent_account_id` and a
 *   real finance department would group these under 1-Assets / 2-Liabilities and so on,
 *   but no code in the application reads the hierarchy, and inventing a tree that nothing
 *   consumes would be a guess presented as a decision.
 * - No account is created beyond the eight the application actually posts to. An unused
 *   account in a chart of accounts is a liability, not a feature.
 * - Currency is left at the schema default (THB) rather than being set per account. These
 *   are the platform's own books; multi-currency ledgers need per-currency account sets,
 *   which is a design question this phase does not answer.
 */
class LedgerAccountSeeder extends Seeder
{
    /**
     * Code => [name, type, description].
     *
     * The type of each account decides the sign convention LedgerAccountType applies, so
     * these are not cosmetic labels: putting player liability under `asset` would invert
     * the balance check that LedgerBalanceWidget performs.
     *
     * @return array<string, array{string, string, string}>
     */
    private function accounts(): array
    {
        return [
            WalletService::ACCOUNT_SYSTEM_CASH => [
                'System Cash',
                'asset',
                'Funds the platform holds. Debited when a player deposit is confirmed.',
            ],
            WalletService::ACCOUNT_WITHDRAWAL_CLEARING => [
                'Withdrawal Clearing',
                'asset',
                'Withdrawals approved and deducted from a wallet but not yet paid out. Holds the money in transit so an approved-but-unpaid withdrawal is never invisible.',
            ],
            WalletService::ACCOUNT_PLAYER_LIABILITY => [
                'Player Balances',
                'liability',
                'What the platform owes its players. The sum of every wallet balance and the single most important reconciliation figure on the books.',
            ],
            WalletService::ACCOUNT_ADJUSTMENT_EQUITY => [
                'Manual Adjustments',
                'equity',
                'The contra account for operator-initiated credits and debits. Every manual wallet adjustment lands here, which makes discretionary intervention auditable as a single total rather than scattered across the ledger.',
            ],
            WalletService::ACCOUNT_BET_REVENUE => [
                'Bet Revenue',
                'revenue',
                'Stakes accepted from players.',
            ],
            WalletService::ACCOUNT_FEE_REVENUE => [
                'Fee Revenue',
                'revenue',
                'Transaction and processing fees charged to players.',
            ],
            WalletService::ACCOUNT_PRIZE_EXPENSE => [
                'Prize Expense',
                'expense',
                'Prizes attributed to winning selections. In this build settlement is a simulation, so this account records what would have been paid.',
            ],
            WalletService::ACCOUNT_COMMISSION_EXPENSE => [
                'Agent Commission',
                'expense',
                'Commission attributed to agents on the turnover they introduce.',
            ],
        ];
    }

    public function run(): void
    {
        foreach ($this->accounts() as $code => [$name, $type, $description]) {
            LedgerAccount::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'description' => $description,
                    'is_active' => true,
                ],
            );
        }
    }
}
