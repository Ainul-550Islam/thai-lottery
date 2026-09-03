<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\BetValidationCode;
use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Exceptions\InvalidBetAmountException;
use App\Services\Finance\Money;
use Stringable;

/**
 * An immutable stake.
 *
 * WHY IT WRAPS Money RATHER THAN REIMPLEMENTING IT
 * App\Services\Finance\Money is the audited money type of Phase 2.1: it validates
 * its input against an anchored decimal pattern, carries a currency, does all
 * arithmetic with bcmath at a fixed scale and refuses to mix currencies. Building
 * a second money type would create a second place where a rounding rule could
 * differ from the ledger's. BetAmount therefore delegates every arithmetic and
 * comparison operation to Money and adds only what a stake specifically needs:
 * the guarantee that it is strictly positive, and a stake-shaped set of failures.
 *
 * ALWAYS POSITIVE
 * A stake of zero is not a bet and a negative stake is not a stake. Both are
 * refused at construction, so any BetAmount that exists is positive. The database
 * agrees: bets.stake_amount and bet_items.amount both carry a CHECK (> 0).
 *
 * NO FLOAT, NO ROUNDING
 * There is no (float), no (double), no floatval and no round() in this class. An
 * amount carrying more decimal places than the currency scale is rejected rather
 * than rounded, because quietly rounding a stake changes what the player agreed to
 * pay. Nothing here debits a wallet, locks a balance or posts a ledger entry.
 */
final readonly class BetAmount implements Stringable
{
    private function __construct(
        public Money $money,
    ) {
    }

    /**
     * Build a stake from an exact decimal string.
     *
     * @throws InvalidBetAmountException
     */
    public static function of(string $amount, Currency $currency): self
    {
        $raw = trim($amount);

        if ($raw === '') {
            throw InvalidBetAmountException::malformed($amount, 'it is empty');
        }

        // Reject excess precision before Money sees it, so the failure is reported
        // as a stake failure with the column scale named, rather than as a generic
        // money failure.
        $scale = $currency->scale();
        $decimalPart = self::decimalPartOf($raw);

        if ($decimalPart !== null && strlen($decimalPart) > $scale) {
            throw InvalidBetAmountException::tooPrecise($raw, $scale, ['currency' => $currency->value]);
        }

        try {
            $money = Money::of($raw, $currency);
        } catch (FinancialException $exception) {
            // Money's own failure is translated rather than propagated: a caller
            // catching FinancialException is unwinding a wallet operation, and a
            // malformed stake is not one. The original is kept as $previous so the
            // underlying money error code is not lost.
            throw new InvalidBetAmountException(
                sprintf('Stake "%s" is not a valid amount: it is not an exact decimal amount.', $raw),
                BetValidationCode::InvalidAmount->value,
                ['stake' => $raw, 'currency' => $currency->value],
                $exception,
            );
        }

        if (! $money->isPositive()) {
            throw InvalidBetAmountException::notPositive($money->amount(), ['currency' => $currency->value]);
        }

        return new self($money);
    }

    /**
     * Wrap an existing Money, still enforcing positivity.
     *
     * @throws InvalidBetAmountException
     */
    public static function fromMoney(Money $money): self
    {
        if (! $money->isPositive()) {
            throw InvalidBetAmountException::notPositive($money->amount(), [
                'currency' => $money->currency()->value,
            ]);
        }

        return new self($money);
    }

    /**
     * Build from a database column value.
     *
     * @throws InvalidBetAmountException
     */
    public static function fromDatabase(string|int|null $amount, Currency $currency): self
    {
        if ($amount === null) {
            throw InvalidBetAmountException::malformed('', 'the stored stake is null');
        }

        return self::of((string) $amount, $currency);
    }

    /**
     * Build without throwing; null on any invalid input.
     */
    public static function tryOf(string $amount, Currency $currency): ?self
    {
        try {
            return self::of($amount, $currency);
        } catch (InvalidBetAmountException) {
            return null;
        }
    }

    /**
     * The underlying audited money value.
     */
    public function money(): Money
    {
        return $this->money;
    }

    /**
     * The exact decimal string at the currency scale.
     */
    public function amount(): string
    {
        return $this->money->amount();
    }

    public function currency(): Currency
    {
        return $this->money->currency();
    }

    public function scale(): int
    {
        return $this->money->scale();
    }

    /**
     * Exact equality of amount and currency.
     */
    public function equals(self $other): bool
    {
        return $this->money->currency() === $other->money->currency()
            && $this->money->compareTo($other->money) === 0;
    }

    /**
     * -1, 0 or 1. Throws when the currencies differ, inheriting Money's guard.
     */
    public function compareTo(self $other): int
    {
        return $this->money->compareTo($other->money);
    }

    public function isAtLeast(Money $minimum): bool
    {
        return $this->money->isGreaterThanOrEqualTo($minimum);
    }

    public function isAtMost(Money $maximum): bool
    {
        return $this->money->isLessThanOrEqualTo($maximum);
    }

    public function isBelow(Money $bound): bool
    {
        return $this->money->isLessThan($bound);
    }

    public function isAbove(Money $bound): bool
    {
        return $this->money->isGreaterThan($bound);
    }

    /**
     * Sum of two stakes, as a stake.
     *
     * Delegates to Money::plus, which is exact and currency-checked.
     *
     * @throws InvalidBetAmountException
     */
    public function plus(self $other): self
    {
        return self::fromMoney($this->money->plus($other->money));
    }

    /**
     * Total of a set of stakes.
     *
     * Returns null for an empty set rather than a zero stake, because zero is not
     * a valid BetAmount and inventing one would be a lie about what was staked.
     *
     * @param  iterable<self>  $amounts
     *
     * @throws InvalidBetAmountException
     */
    public static function total(iterable $amounts): ?self
    {
        $total = null;

        foreach ($amounts as $amount) {
            $total = $total === null ? $amount : $total->plus($amount);
        }

        return $total;
    }

    /**
     * The value as written to a DECIMAL(20,2) column.
     */
    public function toDatabase(): string
    {
        return $this->money->amount();
    }

    /**
     * Currency-formatted for display only. Never use for arithmetic.
     */
    public function format(): string
    {
        return $this->money->format();
    }

    public function toString(): string
    {
        return $this->money->amount();
    }

    public function __toString(): string
    {
        return $this->money->amount();
    }

    /**
     * @return array{amount: string, currency: string, scale: int}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->money->amount(),
            'currency' => $this->money->currency()->value,
            'scale' => $this->money->scale(),
        ];
    }

    /**
     * The decimal places of a raw amount string, or null when it has none.
     *
     * Pure string inspection: no cast, no arithmetic.
     */
    private static function decimalPartOf(string $raw): ?string
    {
        $position = strpos($raw, '.');

        if ($position === false) {
            return null;
        }

        return substr($raw, $position + 1);
    }
}
