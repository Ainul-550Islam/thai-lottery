<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\InvalidLotteryNumberException;
use Stringable;

/**
 * An immutable canonical lottery number.
 *
 * THE CENTRAL RULE: A LOTTERY NUMBER IS A STRING
 * '007', '099' and '000' are positional digit strings, not integers. Casting one
 * to int destroys the selection: intval('007') is 7, intval('099') is 99 and
 * intval('000') is 0, and each of those is a different bet from the one the player
 * placed - or, for '000', no bet at all. This class therefore stores a string,
 * exposes a string, compares as a string and has no numeric accessor of any kind.
 * There is no (int), no intval(), no (float), no ltrim of zeros and no
 * number_format anywhere in it.
 *
 * CANONICAL MEANS EXACT LENGTH
 * An instance always holds exactly $digits characters. Zero padding is a POLICY
 * decision about how to interpret player input, not a property of the value, so it
 * lives in App\Services\Betting\LotteryNumberService where the configured policy
 * (config('lottery.numbers.zero_pad')) can be consulted. This class refuses a
 * wrong-length input outright: it will never pad and never truncate, because
 * either would turn one selection into a different one without telling anyone.
 *
 * IMMUTABILITY
 * The class is final and readonly. Nothing can mutate an instance after
 * construction, so a number that has been validated stays validated and can be
 * passed through the whole pipeline without defensive re-checking.
 *
 * WHAT IT DOES NOT DO
 * No matching against results, no permutation generation, no market knowledge, no
 * database access. sortedDigits() exists only so a later phase can compare two
 * numbers digit-multiset-wise once the Tod combination rule has been specified; it
 * generates nothing on its own.
 */
final readonly class LotteryNumber implements Stringable
{
    /**
     * Digits only. Anchored, ASCII, no sign, no separator, no exponent, no space.
     */
    private const DIGITS_ONLY = '/^[0-9]+$/';

    /**
     * Longest number this project can persist.
     *
     * bet_items.number, number_limits.number and winning_numbers.number are all
     * varchar(16), so 16 is the hard ceiling regardless of any configured digit
     * count.
     */
    public const MAX_DIGITS = 16;

    private function __construct(
        public string $value,
        public int $digits,
    ) {
    }

    /**
     * Build a canonical number, requiring the exact digit count.
     *
     * The input is accepted only if it is already canonical: digits only, and
     * exactly $digits of them. Surrounding whitespace is the single tolerated
     * deviation and is removed before validation, because leading and trailing
     * spaces carry no meaning; an interior space is a malformed number and is
     * refused.
     *
     * @throws InvalidLotteryNumberException
     */
    public static function of(string $value, int $digits, ?string $marketKey = null): self
    {
        if ($digits < 1 || $digits > self::MAX_DIGITS) {
            throw InvalidLotteryNumberException::digitRuleUnavailable(
                $marketKey ?? 'unknown',
                sprintf(
                    'a digit count of %d is outside the storable range 1-%d',
                    $digits,
                    self::MAX_DIGITS,
                ),
            );
        }

        $raw = trim($value);

        if ($raw === '') {
            throw InvalidLotteryNumberException::malformed($value, 'it is empty');
        }

        if (preg_match(self::DIGITS_ONLY, $raw) !== 1) {
            throw InvalidLotteryNumberException::malformed(
                $value,
                'it must contain ASCII digits only, with no sign, separator, decimal point or space',
            );
        }

        // strlen is correct here and mb_strlen is not needed: the regex above has
        // already proven every character is a single-byte ASCII digit.
        $length = strlen($raw);

        if ($length !== $digits) {
            throw InvalidLotteryNumberException::wrongDigitCount($value, $digits, $length, $marketKey);
        }

        return new self($raw, $digits);
    }

    /**
     * Build from a value already known to be canonical, still validating it.
     *
     * Used when reading a number back out of a database column, where the digit
     * count is whatever was stored. The value is validated as digits-only and the
     * length is taken from the value itself rather than asserted, but nothing is
     * padded or trimmed.
     *
     * @throws InvalidLotteryNumberException
     */
    public static function fromCanonical(string $value): self
    {
        $raw = trim($value);

        if ($raw === '') {
            throw InvalidLotteryNumberException::malformed($value, 'it is empty');
        }

        if (preg_match(self::DIGITS_ONLY, $raw) !== 1) {
            throw InvalidLotteryNumberException::malformed(
                $value,
                'a stored lottery number must contain ASCII digits only',
            );
        }

        $length = strlen($raw);

        if ($length > self::MAX_DIGITS) {
            throw InvalidLotteryNumberException::wrongDigitCount($value, self::MAX_DIGITS, $length);
        }

        return new self($raw, $length);
    }

    /**
     * Build without throwing; null on any invalid input.
     */
    public static function tryOf(string $value, int $digits, ?string $marketKey = null): ?self
    {
        try {
            return self::of($value, $digits, $marketKey);
        } catch (InvalidLotteryNumberException) {
            return null;
        }
    }

    /**
     * The canonical string, leading zeros intact.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * How many digits this number has.
     */
    public function digits(): int
    {
        return $this->digits;
    }

    /**
     * Exact equality of both the digits and the length.
     *
     * '07' does not equal '007': they are different selections in different
     * markets, and treating them as equal is precisely the bug this class exists
     * to prevent.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value && $this->digits === $other->digits;
    }

    /**
     * String equality against a raw value, with no normalisation applied.
     */
    public function matchesRaw(string $raw): bool
    {
        return $this->value === $raw;
    }

    /**
     * The individual digits, left to right, as single-character strings.
     *
     * @return list<string>
     */
    public function digitList(): array
    {
        return str_split($this->value);
    }

    /**
     * The digits ordered ascending, as a string.
     *
     * This is a canonical form for the digit multiset, which is what an
     * order-insensitive comparison needs. It is a pure transformation of this
     * value and expands nothing: it produces one string, not a set of
     * permutations. Whether a Tod bet is settled by comparing these forms is an
     * unspecified business rule and is not decided here.
     */
    public function sortedDigits(): string
    {
        $digits = $this->digitList();
        sort($digits, SORT_STRING);

        return implode('', $digits);
    }

    /**
     * Do two numbers use the same digits in some order.
     *
     * Requires equal length, so '07' and '007' are not considered rearrangements
     * of one another.
     */
    public function isRearrangementOf(self $other): bool
    {
        return $this->digits === $other->digits
            && $this->sortedDigits() === $other->sortedDigits();
    }

    /**
     * Does this number contain a repeated digit.
     *
     * Reported because a repeated digit changes how many distinct arrangements a
     * number has, which is one of the reasons the Tod combination rule cannot be
     * assumed.
     */
    public function hasRepeatedDigits(): bool
    {
        return count(array_unique($this->digitList())) !== $this->digits;
    }

    /**
     * How many distinct arrangements the digits of this number have.
     *
     * '123' has 6, '112' has 3, '111' has 1. This is a mathematical property of
     * the value, not a payout rule: it is exposed so an unspecified Tod rule can
     * be discussed with real figures, and it is used by nothing that prices a bet.
     */
    public function distinctArrangementCount(): int
    {
        $factorial = static function (int $n): int {
            $result = 1;

            for ($i = 2; $i <= $n; $i++) {
                $result *= $i;
            }

            return $result;
        };

        $total = $factorial($this->digits);

        // array_count_values already returns int counts, so no cast is needed and
        // none is used: this class contains no numeric cast of any kind.
        foreach (array_count_values($this->digitList()) as $occurrences) {
            $total = intdiv($total, $factorial($occurrences));
        }

        return $total;
    }

    /**
     * Does a single digit appear anywhere in this number.
     *
     * @throws InvalidLotteryNumberException when $digit is not a single digit
     */
    public function containsDigit(string $digit): bool
    {
        if (preg_match('/^[0-9]$/', $digit) !== 1) {
            throw InvalidLotteryNumberException::malformed($digit, 'a digit test requires exactly one digit');
        }

        return str_contains($this->value, $digit);
    }

    /**
     * Lexicographic comparison, which for equal-length digit strings is also the
     * numeric order, without any numeric cast.
     */
    public function compareTo(self $other): int
    {
        return strcmp($this->value, $other->value);
    }

    /**
     * The value as stored in a varchar column.
     */
    public function toDatabase(): string
    {
        return $this->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @return array{value: string, digits: int}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'digits' => $this->digits,
        ];
    }
}
