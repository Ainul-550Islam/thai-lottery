<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base domain exception for every failure inside the risk engine.
 *
 * Anything thrown from App\Services\Risk derives from this class, so a caller can
 * catch the whole risk domain with one type and still branch on the specific
 * subclass or on the stable error code when it needs to.
 *
 * It mirrors the shape of App\Exceptions\FinancialException on purpose so the
 * betting flow can handle money faults and risk faults with the same idioms, but
 * it does NOT extend it: a risk refusal is not a financial fault, and code that
 * catches FinancialException to roll back a wallet operation must not swallow a
 * risk rejection by accident.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - It runs no queries and touches no models: an exception must be safe to throw
 *   from inside a transaction that is about to roll back, including from inside a
 *   SELECT ... FOR UPDATE critical section.
 * - It builds no HTTP response and knows nothing about status codes. Translating
 *   a risk failure into a response belongs to the HTTP layer, which is not part
 *   of Phase 3.1.
 * - The stable machine-readable $errorCode is what API clients and logs key on;
 *   the human message may be reworded at any time.
 *
 * SECURITY
 * The context array is for safe diagnostic identifiers only: draw id, number
 * limit id, bet type, canonical number, amounts, currency. Never put credentials,
 * tokens, API keys, payment details, raw request payloads or user personal data
 * into it, because these values are logged and are attached to audit rows.
 */
class RiskException extends RuntimeException
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
     * Named constructor for the common "message plus code" case.
     *
     * Returns static so every subclass inherits it without redeclaring.
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
     * Machine-readable rejection reason for the risk decision layer.
     *
     * Subclasses that correspond to a specific rejection cause override this so
     * App\Services\Risk\RiskDecisionService can turn a caught exception into the
     * same stable reason code it would have produced without one. The default is
     * the generic code, never null, so a rejection is never reported without a
     * reason.
     */
    public function reasonCode(): string
    {
        return $this->errorCode ?? 'RISK_EVALUATION_FAILED';
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
