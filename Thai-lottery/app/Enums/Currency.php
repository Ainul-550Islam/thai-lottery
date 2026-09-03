<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Supported currencies.
 *
 * THB is the primary lottery currency; USD and BDT exist because deposits and
 * withdrawals can be settled through non-Thai payment methods.
 *
 * This enum is a pure value definition: it performs no database access, no
 * service resolution and no currency conversion. Exchange rates change
 * constantly and must never be hard-coded, so rate lookup belongs to a
 * dedicated service backed by a rate provider, not to this enum.
 *
 * Monetary amounts are handled as decimal strings with bcmath. The helpers here
 * therefore return scale information rather than performing arithmetic.
 */
enum Currency: string
{
    case THB = 'THB';
    case USD = 'USD';
    case BDT = 'BDT';

    /**
     * The primary currency of the platform.
     */
    public static function primary(): self
    {
        return self::THB;
    }

    /**
     * Resolve a currency from a code, case-insensitively.
     */
    public static function fromCode(string $code): self
    {
        return self::from(strtoupper(trim($code)));
    }

    /**
     * Resolve a currency from a code, returning null when unsupported.
     */
    public static function tryFromCode(?string $code): ?self
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return self::tryFrom(strtoupper(trim($code)));
    }

    /**
     * All supported ISO 4217 codes.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Select options keyed by code, suitable for forms and admin panels.
     *
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
            self::THB => 'Thai Baht',
            self::USD => 'US Dollar',
            self::BDT => 'Bangladeshi Taka',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::THB => '฿',
            self::USD => '$',
            self::BDT => '৳',
        };
    }

    /**
     * Number of decimal places used when storing and formatting this currency.
     */
    public function scale(): int
    {
        return 2;
    }

    public function isPrimary(): bool
    {
        return $this === self::primary();
    }

    /**
     * Format a decimal amount string for display.
     *
     * The amount stays a string from end to end: bcadd() normalises the scale
     * and the thousands separators are inserted textually, so the value is never
     * cast to a float and no precision can be lost.
     */
    public function format(string $amount): string
    {
        $normalised = bcadd($amount, '0', $this->scale());

        $negative = str_starts_with($normalised, '-');
        $normalised = ltrim($normalised, '-');

        [$whole, $fraction] = array_pad(explode('.', $normalised, 2), 2, '');

        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        $formatted = $this->symbol().$grouped;

        if ($this->scale() > 0) {
            $formatted .= '.'.str_pad($fraction, $this->scale(), '0');
        }

        return $negative ? '-'.$formatted : $formatted;
    }
}
