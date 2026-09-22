<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What KIND of money inconsistency one discrepancy reports.
 *
 * Each category corresponds to exactly one detector inside
 * App\Services\Finance\FinancialReconciliationService, and the category label
 * is what operators read first when triaging the report. The detector keeps
 * the evidence (expected vs actual, entity reference) in the discrepancy;
 * the category keeps the vocabulary stable.
 *
 * CURRENCY MIXING IS A DISTINCT CATEGORY
 * CurrencyMismatch is isolated from WalletLedgerMismatch on purpose: an amount
 * drift says "the projection is wrong", a currency drift says "two ledgers were
 * netted together" — the second contaminates every total downstream of it and
 * must be identifiable at a glance.
 *
 * VALUES ARE PUBLISHED in reports, CLI output, alert metadata and the Filament
 * operator page. Add new categories at the end; never rename or remove one.
 */
enum DiscrepancyCategory: string
{
    case UnbalancedLedger = 'unbalanced_ledger';
    case WalletLedgerMismatch = 'wallet_ledger_mismatch';
    case DuplicateDeposit = 'duplicate_deposit';
    case DepositAccountingMissing = 'deposit_accounting_missing';
    case DuplicateWithdrawal = 'duplicate_withdrawal';
    case WithdrawalAccountingMissing = 'withdrawal_accounting_missing';
    case DuplicatePrize = 'duplicate_prize';
    case PrizeAccountingMissing = 'prize_accounting_missing';
    case CurrencyMismatch = 'currency_mismatch';
    case OrphanLedgerEntry = 'orphan_ledger_entry';
    case MissingLedgerTransaction = 'missing_ledger_transaction';
    case StaleWalletHold = 'stale_wallet_hold';
    case NegativeAvailableBalance = 'negative_available_balance';
    case BetPurchaseMismatch = 'bet_purchase_mismatch';
    case CommissionMismatch = 'commission_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::UnbalancedLedger => 'Unbalanced Ledger Entry',
            self::WalletLedgerMismatch => 'Wallet / Ledger Mismatch',
            self::DuplicateDeposit => 'Duplicate Deposit Reference',
            self::DepositAccountingMissing => 'Deposit Accounting Missing',
            self::DuplicateWithdrawal => 'Duplicate Withdrawal Reference',
            self::WithdrawalAccountingMissing => 'Withdrawal Accounting Missing',
            self::DuplicatePrize => 'Duplicate Prize Payout',
            self::PrizeAccountingMissing => 'Prize Accounting Missing',
            self::CurrencyMismatch => 'Currency Mismatch',
            self::OrphanLedgerEntry => 'Orphan Ledger Entry',
            self::MissingLedgerTransaction => 'Missing Ledger Transaction',
            self::StaleWalletHold => 'Stale Wallet Hold',
            self::NegativeAvailableBalance => 'Negative Available Balance',
            self::BetPurchaseMismatch => 'Bet Purchase Without Debit',
            self::CommissionMismatch => 'Commission Without Payment',
        };
    }

    /**
     * A short operator-facing explanation of what the detector proves when it
     * fires. Used as the description prefix in reports.
     */
    public function description(): string
    {
        return match ($this) {
            self::UnbalancedLedger => 'Ledger entries of a financial transaction do not net to zero.',
            self::WalletLedgerMismatch => 'Wallet balance does not equal the sum of its ledger entries.',
            self::DuplicateDeposit => 'The same provider reference appears on more than one deposit.',
            self::DepositAccountingMissing => 'A confirmed deposit has no complete financial accounting.',
            self::DuplicateWithdrawal => 'The same provider reference appears on more than one withdrawal.',
            self::WithdrawalAccountingMissing => 'A completed withdrawal has no complete financial accounting.',
            self::DuplicatePrize => 'More than one completed payout exists for the same winning bet.',
            self::PrizeAccountingMissing => 'A winning bet has no payout record attached.',
            self::CurrencyMismatch => 'A ledger record carries a currency different from its transaction.',
            self::OrphanLedgerEntry => 'A ledger entry references a financial transaction that no longer exists.',
            self::MissingLedgerTransaction => 'A completed financial transaction has no ledger entries.',
            self::StaleWalletHold => 'A wallet holds locked funds with no active withdrawal behind them.',
            self::NegativeAvailableBalance => 'Wallet spendable balance is negative or locked funds exceed balance.',
            self::BetPurchaseMismatch => 'An active bet has no matching bet-debit financial transaction.',
            self::CommissionMismatch => 'A paid agent commission has no matching financial transaction.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
