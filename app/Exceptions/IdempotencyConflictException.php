<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * An idempotency key was reused for a different operation.
 *
 * THE DISTINCTION THIS TYPE EXISTS TO MAKE
 * ----------------------------------------
 * same key + same operation  -> a legitimate retry. The idempotency service
 *                               returns the original financial transaction and
 *                               this exception is NOT thrown.
 * same key + different operation -> a client bug or an attack. Honouring it
 *                               would either duplicate money movement or apply
 *                               the wrong one, so the request is refused with
 *                               this exception and nothing is written.
 *
 * PRIVACY
 * Only the names of the fields that disagreed are reported, never their values
 * and never the request payload. That is enough for a client to fix its own
 * bug (it already knows what it sent) without this exception, its message, or
 * any log line built from it echoing amounts, wallet ownership or gateway data
 * belonging to whoever used the key first.
 */
final class IdempotencyConflictException extends FinancialException
{
    public const ERROR_CODE = 'idempotency_conflict';

    /**
     * @param  list<string>  $conflictingFields  field names only, no values
     */
    public function __construct(
        private readonly string $idempotencyKey,
        private readonly array $conflictingFields,
        private readonly ?int $existingTransactionId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Idempotency key "%s" was already used for a different operation; '
                .'the following field(s) do not match the original request: %s.',
                self::maskKey($idempotencyKey),
                $conflictingFields === [] ? 'unknown' : implode(', ', $conflictingFields),
            ),
            self::ERROR_CODE,
            [
                'idempotency_key' => self::maskKey($idempotencyKey),
                'conflicting_fields' => implode(',', $conflictingFields),
                'existing_transaction_id' => $existingTransactionId,
            ],
            $previous,
        );
    }

    /**
     * Convenience constructor for the common single-field mismatch.
     */
    public static function forField(
        string $idempotencyKey,
        string $field,
        ?int $existingTransactionId = null,
    ): self {
        return new self($idempotencyKey, [$field], $existingTransactionId);
    }

    /**
     * The raw key, available to the service that already holds it. It is
     * deliberately not used in the message, which carries the masked form.
     */
    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /**
     * Names of the fields that did not match the original transaction.
     *
     * @return list<string>
     */
    public function conflictingFields(): array
    {
        return $this->conflictingFields;
    }

    /**
     * The transaction that already owns the key, when it is known.
     */
    public function existingTransactionId(): ?int
    {
        return $this->existingTransactionId;
    }

    /**
     * Keys can be client-chosen and may encode information, so only the head
     * and tail are shown: enough to correlate logs, not enough to reconstruct.
     */
    private static function maskKey(string $key): string
    {
        $length = strlen($key);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4).str_repeat('*', $length - 8).substr($key, -4);
    }
}
