<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BetValidationCode;
use RuntimeException;
use Throwable;

/**
 * Base exception for every failure inside the betting domain layer.
 *
 * Anything thrown from App\Services\Betting, App\ValueObjects or App\DTOs derives
 * from this class, so a caller can catch the whole betting domain with one type
 * and still branch on the subclass or on the stable validation code.
 *
 * WHY IT EXTENDS RuntimeException AND NOTHING ELSE
 * It deliberately does not extend App\Exceptions\FinancialException, because a
 * rejected bet is not a money fault and code that catches FinancialException to
 * unwind a wallet operation must not swallow a validation refusal. It equally
 * does not extend App\Exceptions\RiskException: risk is Phase 3.1's domain and
 * its exceptions carry the risk reason vocabulary. The shape of all three is
 * intentionally identical so the future betting workflow can handle money faults,
 * risk faults and domain faults with the same idioms.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No HTTP status, no render() and no response building. Translating a domain
 *   failure into a response belongs to the HTTP layer, which is not part of
 *   Phase 4.1.
 * - No queries and no models. An exception must be safe to throw from inside a
 *   transaction that is about to roll back, including inside a
 *   SELECT ... FOR UPDATE critical section held by the Phase 3.1 lock service.
 * - No wallet, ledger, bet, ticket or payout mutation of any kind.
 *
 * SECURITY
 * The context array is for safe diagnostic identifiers only: draw id, market key,
 * canonical number, digit counts, amounts, currency. Never place credentials,
 * tokens, API keys, payment details, raw request payloads or personal data into
 * it, because these values are logged.
 */
class BetDomainException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly ?string $errorCode = null,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Named constructor for the common "code plus message" case.
     *
     * Returns static so every subclass inherits it without redeclaring it.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function withCode(
        string $errorCode,
        string $message,
        array $context = [],
        ?Throwable $previous = null,
    ): static {
        return new static($message, $errorCode, $context, $previous);
    }

    /**
     * A payout multiplier that is absent, negative, zero or otherwise unusable.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function invalidMultiplier(string $reason, array $context = []): self
    {
        return new self(
            sprintf('The payout multiplier is invalid: %s.', $reason),
            BetValidationCode::InvalidMultiplier->value,
            $context,
        );
    }

    /**
     * A business rule the domain needs has not been declared by the project.
     *
     * This is the exception that keeps the platform honest: it is thrown instead
     * of choosing a plausible default, so an unspecified rule stops a sale rather
     * than silently producing a made-up price or match behaviour.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function specificationRequired(string $subject, array $context = []): self
    {
        return new self(
            sprintf(
                'SPECIFICATION REQUIRED: %s is not declared by the project, so the betting domain '
                .'refuses to assume a value.',
                $subject,
            ),
            BetValidationCode::SpecificationRequired->value,
            $context,
        );
    }

    /**
     * A configuration key the domain depends on is missing or unusable.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function misconfigured(string $configKey, string $reason, array $context = []): self
    {
        return new self(
            sprintf('Configuration key %s is unusable: %s.', $configKey, $reason),
            BetValidationCode::ValidationFailed->value,
            $context + ['config_key' => $configKey],
        );
    }

    /**
     * Stable identifier for this failure, or null when none was supplied.
     */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Safe diagnostic identifiers attached to the failure.
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * A single context value, or null when the key was not supplied.
     */
    public function contextValue(string $key): string|int|float|bool|null
    {
        return $this->context[$key] ?? null;
    }

    /**
     * The validation code this failure maps onto.
     *
     * Subclasses override this so App\Services\Betting\BetValidationService can
     * turn a caught exception into the same stable code it would have produced
     * without one. It is never null, so a rejection is never reported without a
     * code.
     */
    public function validationCode(): BetValidationCode
    {
        if ($this->errorCode !== null) {
            $code = BetValidationCode::tryFrom($this->errorCode);

            if ($code instanceof BetValidationCode) {
                return $code;
            }
        }

        return BetValidationCode::ValidationFailed;
    }

    /**
     * Stable machine-readable reason code, always present.
     */
    public function reasonCode(): string
    {
        return $this->validationCode()->value;
    }

    /**
     * Structured, log-safe representation.
     *
     * @return array{type: string, error_code: string|null, reason_code: string, message: string, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'type' => static::class,
            'error_code' => $this->errorCode,
            'reason_code' => $this->reasonCode(),
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
