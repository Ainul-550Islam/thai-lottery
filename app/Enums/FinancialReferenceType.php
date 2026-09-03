<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Payout;
use App\Models\Withdrawal;

/**
 * Category of the domain record that caused a financial movement.
 *
 * The audited schema carries a polymorphic pointer on both
 * financial_transactions (reference_type / reference_id) and ledger_entries
 * (reference_type / reference_id). Laravel writes the fully qualified model
 * class into reference_type by default; this enum is the stable, machine
 * readable classification of those pointers, so services and reports can group
 * movements without string-matching class names.
 *
 * It is deliberately transport agnostic: no request, no route, no header, no
 * HTTP concept appears here. It also performs no database access.
 */
enum FinancialReferenceType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Bet = 'bet';
    case Payout = 'payout';
    case Commission = 'commission';
    case Fee = 'fee';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Classify a stored reference_type value (a model class name).
     *
     * Returns null when the class is not one of the known financial origins, so
     * callers can decide how to treat an unclassified pointer instead of being
     * handed a wrong category.
     */
    public static function tryFromModelClass(?string $class): ?self
    {
        if ($class === null || $class === '') {
            return null;
        }

        $class = ltrim($class, '\\');

        foreach (self::cases() as $case) {
            if ($case->modelClass() === $class) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Classify the engine operation that produced a movement.
     */
    public static function fromTransactionType(FinancialTransactionType $type): self
    {
        return match ($type) {
            FinancialTransactionType::Deposit => self::Deposit,
            FinancialTransactionType::Withdrawal => self::Withdrawal,
            FinancialTransactionType::BetDebit, FinancialTransactionType::BetRefund => self::Bet,
            FinancialTransactionType::Payout => self::Payout,
            FinancialTransactionType::Commission => self::Commission,
            FinancialTransactionType::Fee => self::Fee,
            FinancialTransactionType::Reversal => self::Reversal,
            FinancialTransactionType::Adjustment,
            FinancialTransactionType::Transfer,
            FinancialTransactionType::Settlement => self::Adjustment,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::Bet => 'Bet',
            self::Payout => 'Payout',
            self::Commission => 'Commission',
            self::Fee => 'Fee',
            self::Reversal => 'Reversal',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * The Eloquent model class stored in reference_type for this category.
     *
     * Fee and Adjustment have no domain record of their own in the audited
     * schema: they are recorded on the financial transaction itself (the `fee`
     * column, or an adjustment transaction), so they return null.
     *
     * @return class-string|null
     */
    public function modelClass(): ?string
    {
        return match ($this) {
            self::Deposit => Deposit::class,
            self::Withdrawal => Withdrawal::class,
            self::Bet => Bet::class,
            self::Payout => Payout::class,
            self::Commission => AgentCommission::class,
            self::Reversal => FinancialTransaction::class,
            self::Fee, self::Adjustment => null,
        };
    }

    /**
     * Whether this category points at a separate domain record.
     */
    public function hasDomainRecord(): bool
    {
        return $this->modelClass() !== null;
    }

    /**
     * Whether movements of this category increase the player's wallet.
     */
    public function isPlayerCredit(): bool
    {
        return match ($this) {
            self::Deposit, self::Payout, self::Commission => true,
            self::Withdrawal, self::Bet, self::Fee, self::Reversal, self::Adjustment => false,
        };
    }
}
