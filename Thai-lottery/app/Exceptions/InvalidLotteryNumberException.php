<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BetValidationCode;

/**
 * A lottery number cannot be accepted as a canonical selection.
 *
 * WHY THE RAW INPUT IS ECHOED BACK
 * The rejected value is included in the context and in the message because a
 * player needs to see which of their entries was refused, and because a
 * leading-zero bug is invisible without it: reporting that "the number is
 * invalid" while hiding that '007' arrived as '7' would make the exact class of
 * defect this platform guards against undiagnosable. A lottery number is not
 * sensitive data.
 *
 * WHAT IS NEVER DONE HERE
 * The raw value is never trimmed of leading zeros, never cast to int and never
 * reformatted before being reported. It is carried through as the caller supplied
 * it, apart from being length-capped so a hostile oversized payload cannot be
 * pushed into a log line.
 */
class InvalidLotteryNumberException extends BetDomainException
{
    /**
     * Maximum characters of the rejected input echoed back.
     *
     * bet_items.number and number_limits.number are both varchar(16), so anything
     * meaningfully longer than that is already invalid; the cap exists only to
     * keep an abusive payload out of the logs.
     */
    public const MAX_ECHOED_LENGTH = 32;

    /**
     * The number does not consist solely of digits, is empty, or is otherwise not
     * a lottery number at all.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function malformed(string $raw, string $reason, array $context = []): self
    {
        return new self(
            sprintf('Lottery number "%s" is not valid: %s.', self::echoSafely($raw), $reason),
            BetValidationCode::InvalidNumber->value,
            $context + ['number' => self::echoSafely($raw)],
        );
    }

    /**
     * The number is made of digits but has the wrong length for its market.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function wrongDigitCount(
        string $raw,
        int $expectedDigits,
        int $actualDigits,
        ?string $marketKey = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Lottery number "%s" has %d digit(s) but %s requires exactly %d; refusing to pad or '
                .'truncate it.',
                self::echoSafely($raw),
                $actualDigits,
                $marketKey ?? 'this market',
                $expectedDigits,
            ),
            BetValidationCode::InvalidDigits->value,
            $context + [
                'number' => self::echoSafely($raw),
                'expected_digits' => $expectedDigits,
                'actual_digits' => $actualDigits,
                'market' => $marketKey,
            ],
        );
    }

    /**
     * The number is well formed but lies outside the configured range for its
     * market.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function outOfRange(
        string $raw,
        string $minimum,
        string $maximum,
        ?string $marketKey = null,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Lottery number "%s" is outside the configured range %s-%s for %s.',
                self::echoSafely($raw),
                $minimum,
                $maximum,
                $marketKey ?? 'this market',
            ),
            BetValidationCode::InvalidNumber->value,
            $context + [
                'number' => self::echoSafely($raw),
                'minimum' => $minimum,
                'maximum' => $maximum,
                'market' => $marketKey,
            ],
        );
    }

    /**
     * The digit rule for a market cannot be determined, so no number can be
     * validated against it.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function digitRuleUnavailable(string $marketKey, string $reason, array $context = []): self
    {
        return new self(
            sprintf(
                'SPECIFICATION REQUIRED: the digit rule for market %s cannot be determined (%s), so no '
                .'number can be validated against it.',
                $marketKey,
                $reason,
            ),
            BetValidationCode::SpecificationRequired->value,
            $context + ['market' => $marketKey],
        );
    }

    /**
     * The rejected number as reported by this exception.
     */
    public function number(): ?string
    {
        $value = $this->contextValue('number');

        return is_string($value) ? $value : null;
    }

    /**
     * The digit count the market required, when the failure was a length failure.
     */
    public function expectedDigits(): ?int
    {
        $value = $this->contextValue('expected_digits');

        return is_int($value) ? $value : null;
    }

    /**
     * Length-cap the rejected input without altering its digits.
     *
     * No trimming of zeros, no numeric cast, no reformatting.
     */
    private static function echoSafely(string $raw): string
    {
        if (mb_strlen($raw) <= self::MAX_ECHOED_LENGTH) {
            return $raw;
        }

        return mb_substr($raw, 0, self::MAX_ECHOED_LENGTH).'...';
    }
}
