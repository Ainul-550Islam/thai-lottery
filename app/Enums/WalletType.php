<?php

namespace App\Enums;

enum WalletType: string
{
    case Primary = 'primary';
    case Bonus = 'bonus';
    case Commission = 'commission';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary Wallet',
            self::Bonus => 'Bonus Wallet',
            self::Commission => 'Commission Wallet',
        };
    }

    public function canWithdraw(): bool
    {
        return in_array($this, [self::Primary, self::Commission]);
    }

    public function canDeposit(): bool
    {
        return $this === self::Primary;
    }

    public function canBet(): bool
    {
        return in_array($this, [self::Primary, self::Bonus]);
    }
}
