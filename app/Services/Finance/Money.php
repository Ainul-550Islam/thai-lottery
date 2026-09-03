<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use Stringable;

/**
 * Immutable, currency-aware monetary value.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * IEEE-754 binary floating point cannot represent most decimal money values, so
 * a single (float) cast anywhere in the engine silently corrupts a balance. This
 * value object is the only monetary type the financial services accept, which
 * makes unsafe arithmetic impossible to write by accident: there is no
 * constructor that takes a float, no method that returns one, and every
 * operation is performed by bcmath on decimal strings.
 *
 * GUARANTEES
 * - Immutable: every operation returns a new instance.
 * - Exact: all arithmetic goes through bcmath at a fixed scale.
 * - Currency aware: mixing currencies throws instead of producing a number.
 * - No silent rounding: an input carrying more decimals than the currency scale
 *   is rejected unless the extra digits are all zeros.
 * - Fails loudly: if bcmath is unavailable the class refuses to construct at all
 *   rather than falling back to float arithmetic.
 *
 * Usage: Money::of('10.55', Currency::THB)
 */
final class Money implements Stringable
{
    /**
     * Largest scale accepted from an input string before normalisation. Beyond
     * this the value is treated as malformed rather than rounded.
     */
    private const MAX_INPUT_SCALE = 12;

    /**
     * Optional sign, up to 24 integer digits (the widest money column in the
     * audited schema is DECIMAL(24,2)), optional fractional part.
     */
    private const AMOUNT_PATTERN = '/^[+-]?[0-9]{1,24}(?:\.[0-9]{1,'.self::MAX_INPUT_SCALE.'})?$/';

    private function __construct(
        private readonly string $amount,
        private readonly Currency $currency,
        private readonly int $scale,
    ) {
    }

    /**
     * Build a money value from an exact decimal string (preferred) or an integer.
     *
     * Floats are impossible to pass: under strict_types a float argument is a
     * TypeError, which is exactly the intent.
     *
     * @param  string|int  $amount  e.g. '10.55', '-1200.00', 0
     * @param  int|null  $scale  defaults to the currency's own scale
     *
     * @throws FinancialException when bcmath is missing, the scale is invalid,
     *                            the string is malformed, or precision would be
     *                            lost by normalising it
     */
    public static function of(string|int $amount, Currency $currency, ?int $scale = null): self
    {
        self::assertExactArithmeticIsAvailable();

        $scale ??= $currency->scale();

        if ($scale < 0 || $scale > self::MAX_INPUT_SCALE) {
            throw FinancialException::withCode(
                'money_invalid_scale',
                sprintf('Monetary scale %d is out of the supported range 0-%d.', $scale, self::MAX_INPUT_SCALE),
                ['scale' => $scale, 'currency' => $currency->value],
            );
        }

        $raw = is_int($amount) ? (string) $amount : trim($amount);

        if ($raw === '' || preg_match(self::AMOUNT_PATTERN, $raw) !== 1) {
            throw FinancialException::withCode(
                'money_malformed_amount',
                'Monetary amount is not a valid exact decimal value.',
                ['currency' => $currency->value, 'scale' => $scale],
            );
        }

        return new self(self::normalise($raw, $scale, $currency), $currency, $scale);
    }

    /**
     * Zero in the given currency.
     */
    public static function zero(Currency $currency, ?int $scale = null): self
    {
        return self::of('0', $currency, $scale);
    }

    /**
     * Build from a value read out of the database.
     *
     * Eloquent's decimal cast already returns a string at the column scale, and
     * a null column is treated as zero, which is the correct reading of a
     * nullable money column such as ledger_entries.balance_after.
     */
    public static function fromDatabase(string|int|null $amount, Currency $currency, ?int $scale = null): self
    {
        return self::of($amount ?? '0', $currency, $scale);
    }

    /**
     * The engine refuses to operate without arbitrary-precision arithmetic.
     *
     * This is a hard failure on purpose. Falling back to float arithmetic would
     * produce wrong balances that reconcile against nothing, which is worse than
     * refusing the operation.
     *
     * @throws FinancialException
     */
    public static function assertExactArithmeticIsAvailable(): void
    {
        if (! extension_loaded('bcmath')) {
            throw FinancialException::withCode(
                'money_exact_arithmetic_unavailable',
                'The bcmath extension is required for exact monetary arithmetic. '
                .'Refusing to perform money calculations with binary floating point.',
            );
        }
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            bcadd($this->amount, $other->amount, $this->scale),
            $this->currency,
            $this->scale,
        );
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            self::stripNegativeZero(bcsub($this->amount, $other->amount, $this->scale), $this->scale),
            $this->currency,
            $this->scale,
        );
    }

    /**
     * Same magnitude, opposite sign. Used when posting a reversal.
     */
    public function negated(): self
    {
        return new self(
            self::stripNegativeZero(bcmul($this->amount, '-1', $this->scale), $this->scale),
            $this->currency,
            $this->scale,
        );
    }

    /**
     * Magnitude without the sign.
     */
    public function absolute(): self
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    /**
     * -1, 0 or 1, comparing exactly at this value's scale.
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, $this->scale);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->compareTo($other) === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', $this->scale) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', $this->scale) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', $this->scale) < 0;
    }

    /**
     * Guard for any amount that must move real money: strictly greater than
     * zero. financial_transactions and ledger_entries both carry a database
     * CHECK constraint to the same effect, and this is the application-side
     * half of that rule.
     *
     * @throws FinancialException
     */
    public function assertPositive(string $subject = 'amount'): self
    {
        if (! $this->isPositive()) {
            throw FinancialException::withCode(
                'money_not_positive',
                sprintf('The %s must be greater than zero.', $subject),
                ['currency' => $this->currency->value, 'amount' => $this->amount, 'subject' => $subject],
            );
        }

        return $this;
    }

    /**
     * Guard for values that may be zero but never negative, such as a fee or a
     * resulting wallet balance.
     *
     * @throws FinancialException
     */
    public function assertNotNegative(string $subject = 'amount'): self
    {
        if ($this->isNegative()) {
            throw FinancialException::withCode(
                'money_negative',
                sprintf('The %s must not be negative.', $subject),
                ['currency' => $this->currency->value, 'amount' => $this->amount, 'subject' => $subject],
            );
        }

        return $this;
    }

    /**
     * @throws FinancialException when the two values are in different currencies
     */
    public function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw FinancialException::withCode(
                'money_currency_mismatch',
                sprintf(
                    'Cannot combine %s with %s: monetary values must share a currency.',
                    $this->currency->value,
                    $other->currency->value,
                ),
                ['currency' => $this->currency->value, 'other_currency' => $other->currency->value],
            );
        }
    }

    /**
     * Exact decimal string, ready to be written to a DECIMAL column.
     */
    public function toString(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    /**
     * Human-readable form with the currency symbol. Presentation only: never
     * feed the result back into arithmetic.
     */
    public function format(): string
    {
        return $this->currency->format($this->amount);
    }

    /**
     * Log-safe representation. Contains no account or ownership data.
     *
     * @return array{amount: string, currency: string, scale: int}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency->value,
            'scale' => $this->scale,
        ];
    }

    /**
     * Bring a validated input string to the target scale.
     *
     * Truncation is never silent: if the input carries significant digits below
     * the target scale the value is rejected, because quietly dropping a
     * fraction of a cent is how ledgers stop reconciling.
     *
     * @throws FinancialException
     */
    private static function normalise(string $raw, int $scale, Currency $currency): string
    {
        $value = ltrim($raw, '+');

        $dotPosition = strpos($value, '.');

        if ($dotPosition !== false) {
            $fraction = substr($value, $dotPosition + 1);

            if (strlen($fraction) > $scale) {
                $dropped = substr($fraction, $scale);

                if (ltrim($dropped, '0') !== '') {
                    throw FinancialException::withCode(
                        'money_precision_loss',
                        sprintf(
                            'Monetary amount carries more than %d decimal place(s); '
                            .'refusing to round away financial precision.',
                            $scale,
                        ),
                        ['currency' => $currency->value, 'scale' => $scale],
                    );
                }
            }
        }

        return self::stripNegativeZero(bcadd($value, '0', $scale), $scale);
    }

    /**
     * bcmath can produce '-0.00'; normalise it so equality and string
     * comparison behave predictably.
     */
    private static function stripNegativeZero(string $value, int $scale): string
    {
        if (bccomp($value, '0', $scale) === 0) {
            return bcadd('0', '0', $scale);
        }

        return $value;
    }
}
