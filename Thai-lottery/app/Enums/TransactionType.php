<?php

namespace App\Enums;

enum TransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case BetPlacement = 'bet_placement';
    case BetRefund = 'bet_refund';
    case Payout = 'payout';
    case Commission = 'commission';
    case Settlement = 'settlement';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::BetPlacement => 'Bet Placement',
            self::BetRefund => 'Bet Refund',
            self::Payout => 'Payout',
            self::Commission => 'Commission',
            self::Settlement => 'Settlement',
            self::Adjustment => 'Adjustment',
            self::Transfer => 'Transfer',
            self::Reversal => 'Reversal',
        };
    }

    public function affectsWallet(): bool
    {
        return in_array($this, [
            self::Deposit,
            self::Withdrawal,
            self::BetPlacement,
            self::BetRefund,
            self::Payout,
            self::Commission,
            self::Settlement,
            self::Transfer,
        ]);
    }

    public function isDebitToUser(): bool
    {
        return in_array($this, [self::Withdrawal, self::BetPlacement]);
    }

    public function isCreditToUser(): bool
    {
        return in_array($this, [self::Deposit, self::Payout, self::Commission, self::BetRefund]);
    }
}
