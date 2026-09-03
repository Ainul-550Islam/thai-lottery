<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Exceptions\IdempotencyConflictException;
use App\Models\FinancialTransaction;
use Closure;
use Illuminate\Database\QueryException;

/**
 * Replay protection for financial operations.
 *
 * THE PROTECTION IS THE DATABASE, NOT THIS CLASS
 * ----------------------------------------------
 * A pure application-level "look it up, then insert it" is not safe: two
 * concurrent requests can both find nothing and both insert, creating two
 * transactions for one key and moving money twice. The real guarantee is the
 * UNIQUE index on financial_transactions.idempotency_key from the audited Phase
 * 1 schema. This service is built around that index:
 *
 *   1. optimistic lookup - cheap path for an obvious retry;
 *   2. attempt the insert - the database arbitrates the race;
 *   3. on a duplicate-key error, re-read the winner and treat the loser's
 *      request as a replay of it.
 *
 * REPLAY VERSUS CONFLICT
 * A key that comes back with a matching operation is a legitimate retry and the
 * original transaction is returned unchanged. A key that comes back with a
 * different operation is refused with IdempotencyConflictException and nothing
 * is written.
 *
 * TRANSACTION BOUNDARY
 * This service never opens or commits a transaction. claim() is designed to run
 * as the first write inside the caller's transaction: on MySQL/MariaDB a
 * duplicate-key error fails the statement but leaves the surrounding transaction
 * usable, so the re-read below is valid.
 *
 * @phpstan-type Expectation array{
 *     type?: FinancialTransactionType|TransactionType|string,
 *     amount?: string,
 *     currency?: Currency|string,
 *     wallet_id?: int|null,
 *     user_id?: int|null,
 * }
 */
final class IdempotencyService
{
    /**
     * SQLSTATE classes and driver codes that mean "unique constraint violated".
     */
    private const DUPLICATE_SQL_STATES = ['23000', '23505'];

    private const DUPLICATE_DRIVER_CODES = [1062, 1586, 19, 2601, 2627];

    /**
     * Characters permitted in a key: safe for logs, URLs and headers alike.
     */
    private const KEY_PATTERN = '/^[A-Za-z0-9_.:\-]+$/';

    private readonly int $minKeyLength;

    private readonly int $maxKeyLength;

    public function __construct(?int $minKeyLength = null, ?int $maxKeyLength = null)
    {
        $this->minKeyLength = $minKeyLength ?? (int) config('security.idempotency.min_key_length', 16);

        // 128 is the width of financial_transactions.idempotency_key; never
        // allow configuration to exceed the column or the insert would truncate.
        $configuredMax = $maxKeyLength ?? (int) config('security.idempotency.max_key_length', 128);
        $this->maxKeyLength = min($configuredMax, 128);
    }

    /**
     * Validate and canonicalise a client-supplied key.
     *
     * @throws FinancialException
     */
    public function normaliseKey(string $key): string
    {
        $key = trim($key);
        $length = strlen($key);

        if ($length < $this->minKeyLength || $length > $this->maxKeyLength) {
            throw FinancialException::withCode(
                'idempotency_key_invalid_length',
                sprintf(
                    'An idempotency key must be between %d and %d characters long.',
                    $this->minKeyLength,
                    $this->maxKeyLength,
                ),
                ['key_length' => $length],
            );
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw FinancialException::withCode(
                'idempotency_key_invalid_characters',
                'An idempotency key may only contain letters, digits, and the characters _ . : -',
                ['key_length' => $length],
            );
        }

        return $key;
    }

    /**
     * A stable key derived from a scope and an identifier.
     *
     * Used where the caller has no client key but the operation must still be
     * idempotent - a reversal of transaction 42 always yields the same key, so a
     * retried reversal collides with itself on the unique index instead of
     * reversing twice.
     */
    public function deterministicKey(string $scope, string $identifier): string
    {
        $scope = preg_replace('/[^A-Za-z0-9_.\-]/', '', $scope) ?? '';
        $key = $scope.':'.hash('sha256', $scope.'|'.$identifier);

        return $this->normaliseKey(substr($key, 0, $this->maxKeyLength));
    }

    /**
     * The transaction that already owns this key, if any.
     *
     * Soft-deleted rows are included because the unique index covers them too:
     * ignoring them would report "free" for a key the database will still reject.
     */
    public function existingFor(string $key): ?FinancialTransaction
    {
        return FinancialTransaction::withTrashed()
            ->where('idempotency_key', $this->normaliseKey($key))
            ->first();
    }

    /**
     * Resolve a key against an expected operation.
     *
     * @param  array<string, mixed>  $expectation
     * @return FinancialTransaction|null the original transaction for a
     *                                   legitimate replay, null when the key is
     *                                   unused
     *
     * @throws IdempotencyConflictException when the key was used for something else
     */
    public function resolve(string $key, array $expectation = []): ?FinancialTransaction
    {
        $key = $this->normaliseKey($key);
        $existing = $this->existingFor($key);

        if ($existing === null) {
            return null;
        }

        $this->assertMatches($key, $existing, $expectation);

        return $existing;
    }

    /**
     * Claim a key for a new operation, or return the operation that already
     * owns it.
     *
     * $create receives the normalised key and must persist exactly one
     * FinancialTransaction carrying it. It must not open its own transaction.
     *
     * @param  array<string, mixed>  $expectation
     * @param  Closure(string): FinancialTransaction  $create
     * @return array{transaction: FinancialTransaction, replayed: bool}
     *
     * @throws IdempotencyConflictException
     * @throws FinancialException
     */
    public function claim(string $key, array $expectation, Closure $create): array
    {
        $key = $this->normaliseKey($key);

        $existing = $this->resolve($key, $expectation);

        if ($existing !== null) {
            return ['transaction' => $existing, 'replayed' => true];
        }

        try {
            $transaction = $create($key);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKeyViolation($exception)) {
                throw $exception;
            }

            // Another request won the race and inserted first. Its row is the
            // single source of truth for this key.
            $winner = $this->existingFor($key);

            if ($winner === null) {
                throw FinancialException::withCode(
                    'idempotency_race_unresolved',
                    'A unique constraint rejected the transaction but no existing '
                    .'transaction could be found for the idempotency key.',
                    [],
                    $exception,
                );
            }

            $this->assertMatches($key, $winner, $expectation);

            return ['transaction' => $winner, 'replayed' => true];
        }

        if ($transaction->idempotency_key !== $key) {
            throw FinancialException::withCode(
                'idempotency_key_not_persisted',
                'The created financial transaction does not carry the claimed idempotency key.',
                ['transaction_id' => $transaction->getKey()],
            );
        }

        return ['transaction' => $transaction, 'replayed' => false];
    }

    /**
     * Whether the given operation may proceed without a key.
     */
    public function isKeyRequiredFor(FinancialTransactionType $type): bool
    {
        if (! (bool) config('finance.idempotency.enabled', true)) {
            return false;
        }

        return $type->requiresIdempotencyKey();
    }

    /**
     * Compare a stored transaction against the expected operation.
     *
     * Only stable, operation-defining fields are compared. Amounts are compared
     * with bcmath so '100' and '100.00' are correctly treated as equal rather
     * than as a conflict.
     *
     * @param  array<string, mixed>  $expectation
     *
     * @throws IdempotencyConflictException
     */
    public function assertMatches(string $key, FinancialTransaction $existing, array $expectation): void
    {
        $conflicts = $this->mismatchedFields($existing, $expectation);

        if ($conflicts !== []) {
            throw new IdempotencyConflictException($key, $conflicts, (int) $existing->getKey());
        }
    }

    /**
     * Names of the operation-defining fields that disagree.
     *
     * @param  array<string, mixed>  $expectation
     * @return list<string>
     */
    public function mismatchedFields(FinancialTransaction $existing, array $expectation): array
    {
        $conflicts = [];

        if (array_key_exists('type', $expectation)) {
            $expectedType = $this->normaliseTypeValue($expectation['type']);

            if ($expectedType !== null && $existing->type->value !== $expectedType) {
                $conflicts[] = 'type';
            }
        }

        if (array_key_exists('currency', $expectation)) {
            $expectedCurrency = $expectation['currency'] instanceof Currency
                ? $expectation['currency']->value
                : (is_string($expectation['currency']) ? strtoupper($expectation['currency']) : null);

            if ($expectedCurrency !== null && $existing->currency->value !== $expectedCurrency) {
                $conflicts[] = 'currency';
            }
        }

        if (array_key_exists('amount', $expectation) && is_string($expectation['amount'])) {
            Money::assertExactArithmeticIsAvailable();

            if (bccomp((string) $existing->amount, $expectation['amount'], 2) !== 0) {
                $conflicts[] = 'amount';
            }
        }

        if (array_key_exists('wallet_id', $expectation)) {
            $expectedWallet = $expectation['wallet_id'] === null ? null : (int) $expectation['wallet_id'];

            if ($existing->wallet_id !== $expectedWallet) {
                $conflicts[] = 'wallet_id';
            }
        }

        if (array_key_exists('user_id', $expectation)) {
            $expectedUser = $expectation['user_id'] === null ? null : (int) $expectation['user_id'];

            if ($existing->user_id !== $expectedUser) {
                $conflicts[] = 'user_id';
            }
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * Whether the driver rejected the write because of a unique constraint.
     */
    public function isDuplicateKeyViolation(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();

        if (in_array($sqlState, self::DUPLICATE_SQL_STATES, true)) {
            return true;
        }

        $driverCode = $exception->errorInfo[1] ?? null;

        return is_int($driverCode) && in_array($driverCode, self::DUPLICATE_DRIVER_CODES, true);
    }

    /**
     * Reduce any accepted type representation to the string actually stored in
     * financial_transactions.type.
     */
    private function normaliseTypeValue(mixed $type): ?string
    {
        if ($type instanceof FinancialTransactionType) {
            return $type->toTransactionType()->value;
        }

        if ($type instanceof TransactionType) {
            return $type->value;
        }

        if (is_string($type)) {
            $engineType = FinancialTransactionType::tryFrom($type);

            if ($engineType !== null) {
                return $engineType->toTransactionType()->value;
            }

            return TransactionType::tryFrom($type)?->value;
        }

        return null;
    }
}
