<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Immutable ledger-purpose taxonomy.
 *
 * Every value the ledger tags a posting with; a purpose says WHAT a
 * posting is for semantically (as opposed to the account-level type
 * that says WHAT IT IS mechanically: debit vs credit).
 *
 * Deterministic mapping from FinancialTransactionType: every flow in
 * the house earns its purpose from the transaction, never from a
 * caller's guess.
 */
enum LedgerEntryPurpose: string
{
    case BetStake = 'bet_stake';
    case PrizePayout = 'prize_payout';
    case Tax = 'tax';
    case Fee = 'fee';
    case Reservation = 'reservation';
    case Release = 'release';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    /**
     * The purpose a given FinancialTransactionType earns.
     * Total over the current transaction-type vocabulary.
     */
    public static function for(FinancialTransactionType $type): self
    {
        return match ($type) {
            FinancialTransactionType::Deposit => self::Reservation,
            FinancialTransactionType::Withdrawal => self::Reservation,
            FinancialTransactionType::BetDebit => self::BetStake,
            FinancialTransactionType::BetRefund => self::Release,
            FinancialTransactionType::Payout => self::PrizePayout,
            FinancialTransactionType::Commission => self::Fee,
            FinancialTransactionType::Fee => self::Fee,
            FinancialTransactionType::Reversal => self::Reversal,
            FinancialTransactionType::Adjustment => self::Adjustment,
            FinancialTransactionType::Transfer => self::Adjustment,
            FinancialTransactionType::Settlement => self::PrizePayout,
        };
    }
}
