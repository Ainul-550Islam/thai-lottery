<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base domain exception for every failure inside the financial engine.
 *
 * Anything thrown from the wallet, ledger, idempotency or reversal services
 * derives from this class, so a caller can catch the whole financial domain with
 * one type and still branch on the specific subclass when it needs to.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - It runs no queries and touches no models: an exception must be safe to throw
 *   from inside a rolled-back database transaction.
 * - It builds no HTTP response and knows nothing about status codes. Translating
 *   a financial failure into a response belongs to the HTTP layer, which is not
 *   part of Phase 2.1.
 * - The stable machine-readable $errorCode is what API clients and logs should
 *   key on; the human message may be reworded at any time.
 *
 * The context array is for safe diagnostic identifiers only (wallet id,
 * transaction uuid, currency, amounts). Never put credentials, tokens, payment
 * details or raw request payloads into it.
 */
class FinancialException extends RuntimeException
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
     * A single context value.
     */
    public function contextValue(string $key): string|int|float|bool|null
    {
        return $this->context[$key] ?? null;
    }

    /**
     * Log-safe representation. Only the fields already placed in $context are
     * exposed, so nothing sensitive can leak through this method.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return array_merge(
            ['error_code' => $this->errorCode, 'exception' => static::class],
            $this->context,
        );
    }
}
