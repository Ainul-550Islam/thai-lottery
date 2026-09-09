<?php

declare(strict_types=1);

namespace App\Enums;

enum DiscrepancyCategory: string
{
    case UnbalancedLedger = 'unbalanced_ledger';
    case WalletLedgerMismatch = 'wallet_ledger_mismatch';
    case DepositAccountingMissing = 'deposit_accounting_missing';
    case DuplicateDeposit = 'duplicate_deposit';
    case WithdrawalAccountingMissing = 'withdrawal_accounting_missing';
    case DuplicateWithdrawal = 'duplicate_withdrawal';
    case PrizeAccountingMissing = 'prize_accounting_missing';
    case DuplicatePrize = 'duplicate_prize';
    case CurrencyMismatch = 'currency_mismatch';
    case OrphanLedgerEntry = 'orphan_ledger_entry';
    case MissingLedgerTransaction = 'missing_ledger_transaction';
    case ProviderMismatch = 'provider_mismatch';
    case StaleWalletHold = 'stale_wallet_hold';
    case NegativeAvailableBalance = 'negative_available_balance';
    case BetPurchaseMismatch = 'bet_purchase_mismatch';
    case CommissionMismatch = 'commission_mismatch';
    case ImmutabilityViolation = 'immutability_violation';

    public function label(): string
    {
        return match ($this) {
            self::UnbalancedLedger => 'Unbalanced Ledger Posting',
            self::WalletLedgerMismatch => 'Wallet vs Ledger Balance Mismatch',
            self::DepositAccountingMissing => 'Deposit Accounting Missing',
            self::DuplicateDeposit => 'Duplicate Deposit Detected',
            self::WithdrawalAccountingMissing => 'Withdrawal Accounting Missing',
            self::DuplicateWithdrawal => 'Duplicate Withdrawal Detected',
            self::PrizeAccountingMissing => 'Prize Accounting Missing',
            self::DuplicatePrize => 'Duplicate Prize Settlement',
            self::CurrencyMismatch => 'Currency Mismatch',
            self::OrphanLedgerEntry => 'Orphan Ledger Entry',
            self::MissingLedgerTransaction => 'Missing Ledger Transaction',
            self::ProviderMismatch => 'Provider Record Mismatch',
            self::StaleWalletHold => 'Stale Wallet Hold',
            self::NegativeAvailableBalance => 'Negative Available Balance',
            self::BetPurchaseMismatch => 'Bet Purchase Accounting Mismatch',
            self::CommissionMismatch => 'Agent Commission Mismatch',
            self::ImmutabilityViolation => 'Immutability Violation',
        };
    }
}
