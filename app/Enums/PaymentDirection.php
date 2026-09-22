<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Immutable money-flow direction of a payment act.
 *
 * Direction is part of IDENTITY: a deposit intent and a withdrawal
 * intent with identical everything else are different facts, and
 * every idempotency key in the payment lane salts the direction in.
 */
enum PaymentDirection: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
        };
    }

    public function isMoneyIn(): bool
    {
        return $this === self::Deposit;
    }

    /**
     * The financial transaction type this direction posts money as
     * when it settles (the WALLET lane's vocabulary).
     */
    public function settlementType(): FinancialTransactionType
    {
        return match ($this) {
            self::Deposit => FinancialTransactionType::Deposit,
            self::Withdrawal => FinancialTransactionType::Withdrawal,
        };
    }
}
