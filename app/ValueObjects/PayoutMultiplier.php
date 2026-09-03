<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\BetDomainException;
use Stringable;

/**
 * An immutable payout multiplier.
 *
 * PRECISION IS DICTATED BY THE SCHEMA
 * payouts.multiplier is DECIMAL(20,4), so four decimal places is the precision the
 * system can actually persist and is the scale used here. bet_items.payout_multiplier
 * is a different and narrower column - unsignedInteger - which cannot hold a
 * fractional rate at all. That mismatch is real and is not papered over:
 * fitsBetItemColumn() reports per multiplier whether it could be written to a bet
 * item, so a later phase detects the problem before writing rather than after
 * truncating. Nothing in this class rounds a value to make it fit.
 *
 * NO FLOAT
 * The value is a decimal string and every comparison is bccomp. There is no
 * (float), no (double), no floatval and no round(). '90', '90.0000' and '90.00' all
 * normalise to the same canonical '90.0000' by string padding, not by numeric
 * conversion.
 *
 * NO RATE IS DECIDED HERE
 * This class validates and carries a multiplier; it never chooses one. Which
 * multiplier applies to which market is resolved by
 * App\Services\Betting\PayoutMultiplierService from the authoritative configuration,
 * and where the project's declared sources contradict one another that service
 * reports SPECIFICATION REQUIRED instead of picking a value.
 */
final readonly class PayoutMultiplier implements Stringable
{
    /**
     * Decimal places, matching payouts.multiplier DECIMAL(20,4).
     */
    public const SCALE = 4;

    /**
     * Integer digits, matching the 20 total digits of DECIMAL(20,4) less its
     * 4 decimal places.
     */
    public const MAX_INTEGER_DIGITS = 16;

    /**
     * Unsigned, anchored, at most 16 integer digits and at most 4 decimals. A
     * leading sign is deliberately not permitted: a negative multiplier would mean
     * a winning bet takes money from the player.
     */
    private const PATTERN = '/^[0-9]{1,16}(?:\.[0-9]{1,4})?$/';

    private function __construct(
        public string $value,
    ) {
    }

    /**
     * Build a multiplier from an exact decimal string or an integer.
     *
     * @throws BetDomainException
     */
    public static function of(string|int $multiplier): self
    {
        $raw = is_int($multiplier) ? (string) $multiplier : trim($multiplier);

        if ($raw === '') {
            throw BetDomainException::invalidMultiplier('it is empty');
        }

        if (preg_match(self::PATTERN, $raw) !== 1) {
            throw BetDomainException::invalidMultiplier(
                sprintf(
                    'a multiplier must be an unsigned decimal with at most %d integer digits and at most '
                    .'%d decimal places, and "%s" is not',
                    self::MAX_INTEGER_DIGITS,
                    self::SCALE,
                    self::echoSafely($raw),
                ),
                ['multiplier' => self::echoSafely($raw)],
            );
        }

        $canonical = bcadd($raw, '0', self::SCALE);

        if (bccomp($canonical, '0', self::SCALE) <= 0) {
            throw BetDomainException::invalidMultiplier(
                'a multiplier must be greater than zero, otherwise a winning selection pays nothing',
                ['multiplier' => $canonical],
            );
        }

        return new self($canonical);
    }

    /**
     * Build from a value read out of configuration, naming the key in the failure.
     *
     * @throws BetDomainException
     */
    public static function fromConfig(mixed $value, string $configKey): self
    {
        if (! is_string($value) && ! is_int($value)) {
            throw BetDomainException::invalidMultiplier(
                sprintf(
                    'configuration key %s must hold a string or integer multiplier, %s given',
                    $configKey,
                    get_debug_type($value),
                ),
                ['config_key' => $configKey],
            );
        }

        try {
            return self::of($value);
        } catch (BetDomainException $exception) {
            throw BetDomainException::invalidMultiplier(
                sprintf('configuration key %s is unusable: %s', $configKey, $exception->getMessage()),
                ['config_key' => $configKey],
            );
        }
    }

    /**
     * Build from a database column value.
     *
     * @throws BetDomainException
     */
    public static function fromDatabase(string|int|null $value): self
    {
        if ($value === null) {
            throw BetDomainException::invalidMultiplier('the stored multiplier is null');
        }

        return self::of($value);
    }

    /**
     * Build without throwing; null on any invalid input.
     */
    public static function tryOf(string|int $multiplier): ?self
    {
        try {
            return self::of($multiplier);
        } catch (BetDomainException) {
            return null;
        }
    }

    /**
     * The canonical decimal string at scale 4.
     */
    public function value(): string
    {
        return $this->value;
    }

    public function scale(): int
    {
        return self::SCALE;
    }

    /**
     * Is this multiplier a whole number.
     */
    public function isInteger(): bool
    {
        return bccomp($this->value, bcadd($this->integerPart(), '0', self::SCALE), self::SCALE) === 0;
    }

    /**
     * The digits before the decimal point, as a string. No numeric cast.
     */
    public function integerPart(): string
    {
        $position = strpos($this->value, '.');

        return $position === false ? $this->value : substr($this->value, 0, $position);
    }

    /**
     * Can this multiplier be written to bet_items.payout_multiplier.
     *
     * That column is unsignedInteger, so only a whole-number multiplier fits. A
     * false here is a schema limitation, not a bad multiplier: the rate is valid
     * and payouts.multiplier could store it.
     */
    public function fitsBetItemColumn(): bool
    {
        return $this->isInteger();
    }

    /**
     * The value as written to payouts.multiplier DECIMAL(20,4).
     */
    public function toDatabase(): string
    {
        return $this->value;
    }

    /**
     * The value as written to bet_items.payout_multiplier.
     *
     * @throws BetDomainException when the multiplier is fractional and would be
     *                            truncated by the integer column
     */
    public function toBetItemColumn(): string
    {
        if (! $this->fitsBetItemColumn()) {
            throw BetDomainException::invalidMultiplier(
                sprintf(
                    'multiplier %s is fractional but bet_items.payout_multiplier is an unsigned integer '
                    .'column; refusing to truncate a payout rate to fit it',
                    $this->value,
                ),
                ['multiplier' => $this->value],
            );
        }

        return $this->integerPart();
    }

    public function equals(self $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) === 0;
    }

    public function compareTo(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Trailing-zero-trimmed form, for display only. Never use for arithmetic or
     * for a database write.
     */
    public function forDisplay(): string
    {
        if (! str_contains($this->value, '.')) {
            return $this->value;
        }

        $trimmed = rtrim(rtrim($this->value, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
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
     * @return array{value: string, scale: int, is_integer: bool, fits_bet_item_column: bool}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'scale' => self::SCALE,
            'is_integer' => $this->isInteger(),
            'fits_bet_item_column' => $this->fitsBetItemColumn(),
        ];
    }

    private static function echoSafely(string $raw): string
    {
        if (mb_strlen($raw) <= 40) {
            return $raw;
        }

        return mb_substr($raw, 0, 40).'...';
    }
}
