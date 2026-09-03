<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The risk engine cannot evaluate because its configuration or data is unsound.
 *
 * This exception is the mechanism that implements the rule "never silently treat
 * missing critical configuration as unlimited". Every place where a ceiling, a
 * threshold or a number format could be absent throws this instead of choosing a
 * convenient default, because the convenient default in a risk engine is always
 * the dangerous one.
 *
 * config('risk.fail_open') is false in the audited configuration, so a caller that
 * catches this must refuse the bet. That decision belongs to the caller; this
 * class only reports the fault.
 *
 * Reason codes are stable and machine-readable:
 *   INVALID_LIMIT   the limit row exists but is unusable
 *   MISSING_LIMIT   no limit row and no configured fallback ceiling
 *   INVALID_NUMBER  the number is malformed or of an unsupported length
 *   INVALID_AMOUNT  the money value is malformed, negative, or over-precise
 *   RISK_MISCONFIGURED  anything else, including missing thresholds
 */
class RiskConfigurationException extends RiskException
{
    public const REASON_INVALID_LIMIT = 'INVALID_LIMIT';

    public const REASON_MISSING_LIMIT = 'MISSING_LIMIT';

    public const REASON_INVALID_NUMBER = 'INVALID_NUMBER';

    public const REASON_INVALID_AMOUNT = 'INVALID_AMOUNT';

    public const REASON_MISCONFIGURED = 'RISK_MISCONFIGURED';

    /**
     * A required configuration key is absent.
     *
     * @param  string  $key  dotted config key, e.g. 'risk.exposure.max_per_number'
     */
    public static function missingKey(string $key): self
    {
        return new self(
            sprintf(
                'Required risk configuration "%s" is missing. Refusing to assume an unlimited '
                .'or default value for a risk ceiling.',
                $key,
            ),
            self::REASON_MISCONFIGURED,
            ['config_key' => $key],
        );
    }

    /**
     * A configured value is present but unusable.
     *
     * The offending value is included only when it is a safe scalar such as a
     * threshold or an amount. Never pass a secret here.
     *
     * @param  string  $key  dotted config key
     * @param  string  $reason  short human explanation
     */
    public static function invalidKey(string $key, string $reason, string|int|null $value = null): self
    {
        return new self(
            sprintf('Risk configuration "%s" is invalid: %s', $key, $reason),
            self::REASON_MISCONFIGURED,
            ['config_key' => $key, 'value' => $value],
        );
    }

    /**
     * No number_limits row exists for this draw, bet type and number, and no
     * configured fallback ceiling is available.
     *
     * SCHEMA NOTE: `number_limits.draw_id` is a non-nullable foreign key, so the
     * table cannot hold a global or per-bet-type default row. A missing limit
     * therefore has no row to lock and no capacity to reserve.
     */
    public static function missingLimit(string $number, string $betType, ?int $drawId = null): self
    {
        return new self(
            sprintf(
                'No number limit is configured for number %s (%s) on this draw, and no fallback '
                .'ceiling is available. Refusing to treat the number as unlimited.',
                $number,
                $betType,
            ),
            self::REASON_MISSING_LIMIT,
            [
                'number' => $number,
                'bet_type' => $betType,
                'draw_id' => $drawId,
            ],
        );
    }

    /**
     * A limit row exists but cannot be used for a decision.
     *
     * The main real case is a NULL `maximum_payout_exposure` with no configured
     * fallback: the row is present, the payout ceiling is not.
     */
    public static function invalidLimit(string $reason, array $context = []): self
    {
        /** @var array<string, scalar|null> $context */
        return new self(
            sprintf('Number limit is unusable: %s', $reason),
            self::REASON_INVALID_LIMIT,
            $context,
        );
    }

    /**
     * The submitted number is not a canonical lottery number.
     *
     * @param  string  $reason  short human explanation
     * @param  string|null  $submitted  the raw input, kept as a string so a value
     *                                  like '007' is never shown as 7
     */
    public static function invalidNumber(string $reason, ?string $submitted = null, ?string $betType = null): self
    {
        return new self(
            sprintf('Invalid lottery number: %s', $reason),
            self::REASON_INVALID_NUMBER,
            [
                'submitted' => $submitted,
                'bet_type' => $betType,
            ],
        );
    }

    /**
     * The submitted money value cannot be used.
     *
     * Covers malformed decimals, negative stakes, zero stakes where a positive
     * amount is required, and precision beyond the currency scale. Excess
     * precision is reported rather than rounded away.
     */
    public static function invalidAmount(string $reason, ?string $submitted = null): self
    {
        return new self(
            sprintf('Invalid monetary amount: %s', $reason),
            self::REASON_INVALID_AMOUNT,
            ['submitted' => $submitted],
        );
    }

    public function reasonCode(): string
    {
        $code = $this->errorCode();

        return is_string($code) && $code !== '' ? $code : self::REASON_MISCONFIGURED;
    }
}
