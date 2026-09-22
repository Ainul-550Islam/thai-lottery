<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused or broken TAX CALCULATION.
 *
 * COVERAGE
 * --------
 * Everything the tax lane may refuse: an invalid basis (taxable > gross,
 * malformed money strings, zero/negative prize), a duplicate computation
 * against an unchanged basis, a missing or malformed RATE configuration,
 * or an inconsistent internal state (a calculation asked to Apply whose
 * stamped answer does not equal the re-derived answer — drift).
 *
 * WHY A SEPARATE DOMAIN EXCEPTION
 * -------------------------------
 * Nothing here is an input-validation error in the framework sense and
 * nothing here is a money-movement error. Tax mistakes are their own
 * class of incident: over-withholding is embezzlement-shaped,
 * under-withholding is an audit finding, and the two want different
 * forensic journeys. One typed exception keeps every tax refusal
 * searchable under one class and one set of codes.
 *
 * Context: calculation keys, payout references, decimal strings, rate
 * configuration keys. Never a credential, stack, SQL, or personal datum.
 */
class TaxCalculationException extends RuntimeException
{
    public const CODE_INVALID_BASIS = 'TAX_INVALID_BASIS';

    public const CODE_TAXABLE_EXCEEDS_GROSS = 'TAX_TAXABLE_EXCEEDS_GROSS';

    public const CODE_DUPLICATE_CALCULATION = 'TAX_DUPLICATE_CALCULATION';

    public const CODE_RATE_UNCONFIGURED = 'TAX_RATE_UNCONFIGURED';

    public const CODE_RATE_MALFORMED = 'TAX_RATE_MALFORMED';

    public const CODE_INCONSISTENT_STATE = 'TAX_INCONSISTENT_STATE';

    public const CODE_ALREADY_RUNNING = 'TAX_ALREADY_RUNNING';

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
     * The basis itself cannot be computed over: malformed money strings,
     * zero/negative prize, etc.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function invalidBasis(string $payoutReference, string $reason, array $context = []): self
    {
        return new self(
            sprintf('Tax basis for payout [%s] is invalid: %s.', $payoutReference, $reason),
            self::CODE_INVALID_BASIS,
            $context + ['payout_reference' => $payoutReference, 'reason' => $reason],
        );
    }

    /**
     * The taxable base exceeds the gross prize. Computing would charge the
     * player tax on money they never won.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function taxableExceedsGross(
        string $payoutReference,
        string $taxable,
        string $gross,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Tax basis for payout [%s]: taxable %s exceeds gross prize %s; charging for phantom money is refused.',
                $payoutReference,
                $taxable,
                $gross,
            ),
            self::CODE_TAXABLE_EXCEEDS_GROSS,
            $context + ['payout_reference' => $payoutReference, 'taxable' => $taxable, 'gross' => $gross],
        );
    }

    /**
     * A calculation with this identity already exists and its basis is
     * unchanged. A second computation would answer the same question twice
     * and silently split the paper trail.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function duplicateCalculation(string $calculationKey, array $context = []): self
    {
        return new self(
            sprintf(
                'Tax calculation [%s] already exists for this exact basis; recomputing is a replay and is answered by re-serving the stamped answer.',
                $calculationKey,
            ),
            self::CODE_DUPLICATE_CALCULATION,
            $context + ['calculation_key' => $calculationKey],
        );
    }

    /**
     * No rate is configured for the jurisdiction/method/currency this basis
     * demands. Guessing a rate is never acceptable: the correct answer to
     * "we don't know the rate" is a loud stop, not zero.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function rateUnconfigured(string $configKey, array $context = []): self
    {
        return new self(
            sprintf('No prize-tax rate is configured at [%s]; computing without a rate is refused rather than guessed.', $configKey),
            self::CODE_RATE_UNCONFIGURED,
            $context + ['config_key' => $configKey],
        );
    }

    /**
     * The configured rate is present but not computable (non-numeric,
     * negative, above 1, float-shaped).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function rateMalformed(string $configKey, string $given, array $context = []): self
    {
        return new self(
            sprintf('Prize-tax rate at [%s] is malformed (%s is not a decimal rate in [0, 1]); refusing to compute.', $configKey, $given),
            self::CODE_RATE_MALFORMED,
            $context + ['config_key' => $configKey, 'given' => $given],
        );
    }

    /**
     * The stamped answer no longer equals the re-derived answer: the rows
     * drifted between computation and application. Applying would sign for
     * arithmetic nobody performed.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function inconsistentState(string $calculationKey, string $detail, array $context = []): self
    {
        return new self(
            sprintf('Tax calculation [%s] is inconsistent (%s); application is refused until the drift is explained.', $calculationKey, $detail),
            self::CODE_INCONSISTENT_STATE,
            $context + ['calculation_key' => $calculationKey, 'detail' => $detail],
        );
    }

    /**
     * The tax lane owns its transaction boundary.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyRunning(int $transactionLevel, array $context = []): self
    {
        return new self(
            sprintf('Tax calculations own their transaction boundary; caller is at transaction level %d.', $transactionLevel),
            self::CODE_ALREADY_RUNNING,
            $context + ['transaction_level' => $transactionLevel],
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
}
