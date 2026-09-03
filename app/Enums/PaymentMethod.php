<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Stripe = 'stripe';
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Crypto = 'crypto';
    case BankTransfer = 'bank_transfer';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
            self::Crypto => 'Cryptocurrency',
            self::BankTransfer => 'Bank Transfer',
            self::Manual => 'Manual',
        };
    }

    public function isAutomatic(): bool
    {
        return in_array($this, [self::Stripe, self::Bkash, self::Nagad, self::Crypto]);
    }

    public function supportsWebhook(): bool
    {
        return in_array($this, [self::Stripe, self::Bkash, self::Nagad, self::Crypto]);
    }

    public function supportedCurrencies(): array
    {
        return match ($this) {
            self::Stripe => [Currency::THB, Currency::USD],
            self::Bkash => [Currency::BDT],
            self::Nagad => [Currency::BDT],
            self::Crypto => [Currency::USD],
            self::BankTransfer, self::Manual => [Currency::THB, Currency::USD, Currency::BDT],
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Stripe => 'credit-card',
            self::Bkash => 'wallet',
            self::Nagad => 'wallet',
            self::Crypto => 'bitcoin',
            self::BankTransfer => 'building-2',
            self::Manual => 'hand',
        };
    }
}
