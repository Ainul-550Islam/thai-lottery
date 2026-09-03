<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A rejected official draw result: a malformed first prize, a malformed bottom two,
 * a lost leading zero, or a value that is not a plain digit string.
 *
 * WHY A NEW CLASS AND NOT AN EXISTING ONE
 * ---------------------------------------
 * App\Exceptions\InvalidLotteryNumberException already rejects malformed numbers, but
 * it describes a PLAYER'S SELECTION: its factories are phrased around a market key
 * and a chosen number, and the API surface built in Phase 4.4 maps it to a 422 field
 * error on the player's input. An operator publishing a wrong first prize is a
 * different failure with a different audience, and must not be reported to a player
 * as though the player mistyped something.
 *
 * App\Exceptions\MarketResultUnavailableException is about a result that is MISSING
 * or unreadable when a market rule asks for it. This class is about a result that is
 * PRESENT and being rejected before it is stored. Publication guards the write;
 * MarketResultUnavailableException guards the read.
 *
 * The (message, errorCode, context) shape matches BetDomainException and
 * DrawLifecycleException.
 *
 * NO FLOATING POINT, ANYWHERE
 * Every value carried here stays a STRING. A rejected first prize such as '007123'
 * is reported as '007123' and never as 7123. That is why the constructor and every
 * factory type the number parameters as string and why no factory calls intval(),
 * floatval(), round() or a numeric cast.
 *
 * SECURITY
 * Context carries the draw id, the offending value and the rule it broke. The
 * offending value is operator-supplied lottery digits, not a credential. No stack
 * trace, connection string or query is ever placed in context.
 */
class DrawResultValidationException extends RuntimeException
{
    public const CODE_FIRST_PRIZE_REQUIRED = 'RESULT_FIRST_PRIZE_REQUIRED';

    public const CODE_FIRST_PRIZE_NOT_DIGITS = 'RESULT_FIRST_PRIZE_NOT_DIGITS';

    public const CODE_FIRST_PRIZE_LENGTH = 'RESULT_FIRST_PRIZE_LENGTH';

    public const CODE_BOTTOM_TWO_REQUIRED = 'RESULT_BOTTOM_TWO_REQUIRED';

    public const CODE_BOTTOM_TWO_NOT_DIGITS = 'RESULT_BOTTOM_TWO_NOT_DIGITS';

    public const CODE_BOTTOM_TWO_LENGTH = 'RESULT_BOTTOM_TWO_LENGTH';

    public const CODE_DERIVATION_FAILED = 'RESULT_DERIVATION_FAILED';

    public const CODE_UNEXPECTED_FIELD = 'RESULT_UNEXPECTED_FIELD';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * No first prize supplied at all.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function firstPrizeRequired(array $context = []): self
    {
        return new self(
            'The official first prize number is required and may not be empty.',
            self::CODE_FIRST_PRIZE_REQUIRED,
            $context + ['field' => 'first_prize'],
        );
    }

    /**
     * The first prize contains something other than ASCII digits.
     *
     * The offending value is echoed back verbatim as a string so a leading zero, a
     * space or a plus sign is visible in the report.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function firstPrizeNotDigits(string $given, array $context = []): self
    {
        return new self(
            sprintf(
                'The official first prize number must consist only of the digits 0-9. Received '
                .'"%s". Signs, separators, spaces and decimal points are refused, and the value is '
                .'never coerced to a number.',
                $given,
            ),
            self::CODE_FIRST_PRIZE_NOT_DIGITS,
            $context + [
                'field' => 'first_prize',
                'given' => $given,
                'given_length' => strlen($given),
            ],
        );
    }

    /**
     * The first prize has the wrong number of digits.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function firstPrizeLength(string $given, int $expected, array $context = []): self
    {
        return new self(
            sprintf(
                'The official first prize number must be exactly %d digits. Received "%s", which is '
                .'%d. Leading zeroes count as digits and are never stripped.',
                $expected,
                $given,
                strlen($given),
            ),
            self::CODE_FIRST_PRIZE_LENGTH,
            $context + [
                'field' => 'first_prize',
                'given' => $given,
                'given_length' => strlen($given),
                'expected_length' => $expected,
            ],
        );
    }

    /**
     * No bottom two supplied.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function bottomTwoRequired(array $context = []): self
    {
        return new self(
            'The official bottom two number is required and may not be empty. It is an independently '
            .'drawn value and is never derived from the first prize.',
            self::CODE_BOTTOM_TWO_REQUIRED,
            $context + ['field' => 'bottom_two'],
        );
    }

    /**
     * The bottom two contains something other than ASCII digits.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function bottomTwoNotDigits(string $given, array $context = []): self
    {
        return new self(
            sprintf(
                'The official bottom two number must consist only of the digits 0-9. Received "%s".',
                $given,
            ),
            self::CODE_BOTTOM_TWO_NOT_DIGITS,
            $context + [
                'field' => 'bottom_two',
                'given' => $given,
                'given_length' => strlen($given),
            ],
        );
    }

    /**
     * The bottom two has the wrong number of digits.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function bottomTwoLength(string $given, int $expected, array $context = []): self
    {
        return new self(
            sprintf(
                'The official bottom two number must be exactly %d digits. Received "%s", which is '
                .'%d. A value such as "07" must be sent as "07" and not as 7.',
                $expected,
                $given,
                strlen($given),
            ),
            self::CODE_BOTTOM_TWO_LENGTH,
            $context + [
                'field' => 'bottom_two',
                'given' => $given,
                'given_length' => strlen($given),
                'expected_length' => $expected,
            ],
        );
    }

    /**
     * A value that should have been derivable from a validated first prize could not
     * be derived. A defect guard, not an input error.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function derivationFailed(string $what, string $from, array $context = []): self
    {
        return new self(
            sprintf(
                'Could not derive %s from the validated first prize "%s". The result was not stored.',
                $what,
                $from,
            ),
            self::CODE_DERIVATION_FAILED,
            $context + [
                'derived' => $what,
                'first_prize' => $from,
            ],
        );
    }

    /**
     * A field was supplied that publication refuses to accept from a caller, such as
     * an attempt to dictate a winner, a payout amount or a multiplier.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function unexpectedField(string $field, array $context = []): self
    {
        return new self(
            sprintf(
                'The field "%s" is not accepted when publishing an official result. Winning numbers, '
                .'multipliers and prize amounts are derived server side from the drawn numbers and '
                .'configuration, never taken from the caller.',
                $field,
            ),
            self::CODE_UNEXPECTED_FIELD,
            $context + ['field' => $field],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function contextValue(string $key): string|int|float|bool|null
    {
        return $this->context[$key] ?? null;
    }

    /**
     * The field this rejection concerns, when it concerns one.
     */
    public function field(): ?string
    {
        $field = $this->context['field'] ?? null;

        return is_string($field) ? $field : null;
    }

    /**
     * @return array{type: string, error_code: string, message: string, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'type' => static::class,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
