<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BetValidationCode;

/**
 * A stake cannot be accepted.
 *
 * NO FINANCIAL SIDE EFFECT
 * Throwing this changes no balance, locks nothing and posts nothing. It is raised
 * before any money is touched, which is the entire point of validating a stake in
 * the domain layer: an unusable amount must never reach the wallet services.
 *
 * AMOUNTS ARE REPORTED AS EXACT DECIMAL STRINGS
 * Every amount carried in the context is the string the caller supplied or the
 * exact configured bound. Nothing is cast to float and nothing is rounded on its
 * way into the message, so a rejection caused by an extra decimal place is
 * visible as such rather than being masked by display formatting.
 */
class InvalidBetAmountException extends BetDomainException
{
    /**
     * Maximum characters of the rejected input echoed back, so an oversized
     * payload cannot be pushed into a log line.
     */
    public const MAX_ECHOED_LENGTH = 40;

    /**
     * The value is not a decimal amount at all.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function malformed(string $raw, string $reason, array $context = []): self
    {
        return new self(
            sprintf('Stake "%s" is not a valid amount: %s.', self::echoSafely($raw), $reason),
            BetValidationCode::InvalidAmount->value,
            $context + ['stake' => self::echoSafely($raw)],
        );
    }

    /**
     * The amount is well formed but is zero or negative.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notPositive(string $amount, array $context = []): self
    {
        return new self(
            sprintf('Stake %s must be greater than zero.', self::echoSafely($amount)),
            BetValidationCode::InvalidAmount->value,
            $context + ['stake' => self::echoSafely($amount)],
        );
    }

    /**
     * The amount is below the configured minimum stake.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function belowMinimum(string $amount, string $minimum, array $context = []): self
    {
        return new self(
            sprintf('Stake %s is below the minimum stake of %s.', self::echoSafely($amount), $minimum),
            BetValidationCode::InvalidAmount->value,
            $context + ['stake' => self::echoSafely($amount), 'minimum' => $minimum],
        );
    }

    /**
     * The amount is above the configured maximum stake.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function aboveMaximum(string $amount, string $maximum, array $context = []): self
    {
        return new self(
            sprintf('Stake %s is above the maximum stake of %s.', self::echoSafely($amount), $maximum),
            BetValidationCode::InvalidAmount->value,
            $context + ['stake' => self::echoSafely($amount), 'maximum' => $maximum],
        );
    }

    /**
     * The amount carries more decimal places than the money column can store.
     *
     * bets.stake_amount and bet_items.amount are both DECIMAL(20,2). An amount
     * with a third decimal place is refused rather than rounded, because rounding
     * a stake silently changes what the player agreed to pay.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function tooPrecise(string $amount, int $allowedScale, array $context = []): self
    {
        return new self(
            sprintf(
                'Stake %s has more than %d decimal place(s); refusing to round a stake to fit the '
                .'money column.',
                self::echoSafely($amount),
                $allowedScale,
            ),
            BetValidationCode::InvalidAmount->value,
            $context + ['stake' => self::echoSafely($amount), 'allowed_scale' => $allowedScale],
        );
    }

    /**
     * The amount does not sit on the configured stake step.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function offStep(string $amount, string $step, array $context = []): self
    {
        return new self(
            sprintf('Stake %s is not a multiple of the configured step %s.', self::echoSafely($amount), $step),
            BetValidationCode::InvalidAmount->value,
            $context + ['stake' => self::echoSafely($amount), 'step' => $step],
        );
    }

    /**
     * The rejected stake as reported by this exception.
     */
    public function stake(): ?string
    {
        $value = $this->contextValue('stake');

        return is_string($value) ? $value : null;
    }

    /**
     * Length-cap the rejected input without altering its digits.
     */
    private static function echoSafely(string $raw): string
    {
        if (mb_strlen($raw) <= self::MAX_ECHOED_LENGTH) {
            return $raw;
        }

        return mb_substr($raw, 0, self::MAX_ECHOED_LENGTH).'...';
    }
}
