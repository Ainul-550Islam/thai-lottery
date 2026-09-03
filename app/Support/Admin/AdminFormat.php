<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Services\Finance\Money;
use Illuminate\Support\Carbon;

/**
 * Display formatting for the panel.
 *
 * WHY THIS EXISTS RATHER THAN Filament's money() COLUMN
 * Filament's money()/numeric() formatters route through Illuminate\Support\Number, which
 * requires ext-intl and, worse, converts to float on the way. This project's first
 * convention is that money is a bcmath *string* and never a float, because a float turns
 * 0.1 + 0.2 into a rounding complaint from an auditor. So the panel formats money itself,
 * as a string, with no arithmetic.
 *
 * Everything here is a pure function of its input: no database, no config writes, no
 * rounding of stored values.
 */
final class AdminFormat
{
    /**
     * A stored decimal string as an operator-readable amount: 1234567.5 -> "1,234,567.50".
     *
     * Never parsed as a float. The integer and fraction parts are handled as strings so a
     * 20-digit ledger figure survives intact.
     */
    public static function money(mixed $value, ?string $currency = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($value instanceof Money) {
            $currency ??= $value->currency()->value ?? null;
            $value = $value->amount();
        }

        $raw = trim((string) $value);
        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');

        if (! preg_match('/^\d+(\.\d+)?$/', $raw)) {
            return (string) $value;
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $grouped = strrev(implode(',', str_split(strrev($integer), 3)));

        return sprintf(
            '%s%s.%s%s',
            $negative ? '-' : '',
            $grouped,
            $fraction,
            $currency !== null && $currency !== '' ? ' '.$currency : '',
        );
    }

    /**
     * A count with thousands separators, without touching Number/intl.
     */
    public static function count(mixed $value): string
    {
        return number_format((float) $value, 0, '.', ',');
    }

    /**
     * A moment in the market timezone, labelled, because "15:00" means nothing to an
     * operator who does not know which clock it is on.
     */
    public static function marketTime(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        $timezone = (string) config('lottery.timezone', 'Asia/Bangkok');

        return Carbon::parse($value)->timezone($timezone)->format('Y-m-d H:i').' '.self::timezoneLabel($timezone);
    }

    public static function timezoneLabel(?string $timezone = null): string
    {
        $timezone ??= (string) config('lottery.timezone', 'Asia/Bangkok');

        return (string) (explode('/', $timezone)[1] ?? $timezone);
    }

    /**
     * The platform currency, from the finance config the domain services already read.
     */
    public static function currency(): string
    {
        return (string) config('lottery.betting.currency', env('FINANCE_DEFAULT_CURRENCY', 'THB'));
    }

    /**
     * A lottery number as entered, kept as a string so a leading zero is never lost.
     */
    public static function number(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }
}
