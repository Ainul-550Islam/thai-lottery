<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Chart-of-accounts category for the double-entry ledger.
 *
 * The five standard accounting categories. This enum is the cast target of
 * App\Models\LedgerAccount::$type (ledger_accounts.type, VARCHAR(32)), so the
 * backing values must never change once accounts exist in the database.
 *
 * The only intelligence here is the normal balance of each category, which the
 * ledger posting service needs in order to decide whether a debit increases or
 * decreases an account's cached current_balance. No arithmetic, no database
 * access and no posting logic lives in this enum.
 */
enum LedgerAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

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

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Revenue => 'Revenue',
            self::Expense => 'Expense',
        };
    }

    /**
     * The side that increases this category of account.
     */
    public function normalBalance(): LedgerEntryType
    {
        return match ($this) {
            self::Asset, self::Expense => LedgerEntryType::Debit,
            self::Liability, self::Equity, self::Revenue => LedgerEntryType::Credit,
        };
    }

    public function isDebitNormal(): bool
    {
        return $this->normalBalance() === LedgerEntryType::Debit;
    }

    public function isCreditNormal(): bool
    {
        return $this->normalBalance() === LedgerEntryType::Credit;
    }

    /**
     * Whether an entry of the given side increases this account's balance.
     */
    public function increasesWith(LedgerEntryType $side): bool
    {
        return $side === $this->normalBalance();
    }

    /**
     * Whether the category appears on the balance sheet (as opposed to the
     * profit and loss statement). Useful for reporting groupings.
     */
    public function isBalanceSheet(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Revenue, self::Expense => false,
        };
    }
}
