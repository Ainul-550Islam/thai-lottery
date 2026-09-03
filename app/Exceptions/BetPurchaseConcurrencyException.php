<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * A purchase abandoned because another concurrent purchase won a contended
 * resource.
 *
 * WHY CONCURRENCY GETS ITS OWN EXCEPTION TYPE
 * Losing a race is categorically different from being wrong. A player whose
 * request lost the wallet row lock, or whose bet number collided with another
 * request's generated reference, submitted a perfectly valid purchase; the correct
 * reaction is usually "retry", while the correct reaction to a validation refusal
 * is "change the request" and to an exhausted number limit is "choose another
 * number". Encoding that difference in the type means a caller never has to guess.
 *
 * WHY IT IS NOT AN InsufficientBalanceException OR A NumberLimitExceededException
 * Those two exceptions describe a FACT about a locked row - the balance was too
 * small, the ceiling was reached - and they remain the exceptions thrown for those
 * facts even under concurrency, because that is what the row actually said. This
 * exception describes a failure of the ATTEMPT rather than a fact about the data:
 * a deadlock, a lost idempotency race, a reference collision.
 *
 * THE IDEMPOTENT RACE
 * The one case that is not an error at all is isIdempotentRace(). Two requests can
 * carry the same idempotency key and arrive at the same instant; exactly one of them
 * inserts the bet row and the other is rejected by the UNIQUE index on
 * bets.idempotency_key. The loser throws this exception with that flag set, which
 * rolls its own transaction back completely - releasing its number-limit
 * reservation and discarding its wallet debit - and the purchase service then
 * re-reads the winner's committed bet and returns it as a replay. The flag exists so
 * that this specific recovery is explicit and cannot be confused with a genuine
 * deadlock.
 *
 * ROLLBACK CONTRACT
 * Like every purchase exception, this class performs no compensating write. The
 * transaction rollback is the whole cleanup: it releases the row locks, discards the
 * number-limit counter increments, the bet, the bet item, the ticket, the financial
 * transaction and the ledger entries as one unit.
 */
final class BetPurchaseConcurrencyException extends BetPurchaseException
{
    /** MySQL / MariaDB: deadlock found when trying to get lock. */
    public const SQLSTATE_DEADLOCK = 1213;

    /** MySQL / MariaDB: lock wait timeout exceeded. */
    public const SQLSTATE_LOCK_TIMEOUT = 1205;

    /** MySQL / MariaDB: duplicate entry for a unique key. */
    public const SQLSTATE_DUPLICATE_ENTRY = 1062;

    /**
     * Two requests carrying the SAME idempotency key raced, and this one lost the
     * insert on the UNIQUE index over bets.idempotency_key.
     *
     * Not an error condition: the caller re-reads the winning bet and returns it.
     */
    public static function idempotentRaceLost(string $idempotencyKey, int $userId, int $drawId, ?Throwable $previous = null): self
    {
        return new self(
            'Another concurrent request already created the bet for this idempotency key. '
            .'This attempt has been rolled back in full and the existing bet will be returned.',
            'bet_purchase_idempotent_race_lost',
            [
                'idempotency_key' => $idempotencyKey,
                'user_id' => $userId,
                'draw_id' => $drawId,
                'idempotent_race' => true,
            ],
            $previous,
        );
    }

    /**
     * The database reported a deadlock or a lock wait timeout while the purchase
     * held or awaited a row lock.
     */
    public static function deadlock(string $operation, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'The database reported a lock conflict while trying to %s. The purchase was rolled '
                .'back without mutating the wallet, the ledger or any number limit.',
                $operation,
            ),
            'bet_purchase_deadlock',
            ['operation' => $operation],
            $previous,
        );
    }

    /**
     * A generated bet_number or ticket_number collided with an existing one more
     * times than the reference service is willing to retry.
     */
    public static function referenceCollision(string $kind, int $attempts, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Could not generate a unique %s after %d attempts. The purchase was rolled back '
                .'rather than reusing an existing reference.',
                $kind,
                $attempts,
            ),
            'bet_purchase_reference_collision',
            ['reference_kind' => $kind, 'attempts' => $attempts],
            $previous,
        );
    }

    /**
     * A unique-constraint violation that is not the idempotency key.
     *
     * Reported rather than repaired, because guessing which column collided and
     * retrying blindly is how duplicate financial rows get created.
     */
    public static function uniqueViolation(string $operation, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'A unique constraint was violated while trying to %s. The purchase was rolled back.',
                $operation,
            ),
            'bet_purchase_unique_violation',
            ['operation' => $operation],
            $previous,
        );
    }

    /**
     * True when the loss was an idempotency-key race, which the caller resolves by
     * returning the already committed bet.
     */
    public function isIdempotentRace(): bool
    {
        return ($this->context()['idempotent_race'] ?? false) === true;
    }

    /**
     * Whether a driver exception is a MySQL / MariaDB deadlock or lock timeout.
     *
     * Read from the driver error code, never from the message text, because
     * message text is localised and version dependent.
     */
    public static function isLockFailure(QueryException $exception): bool
    {
        $code = self::driverCode($exception);

        return $code === self::SQLSTATE_DEADLOCK || $code === self::SQLSTATE_LOCK_TIMEOUT;
    }

    /**
     * Whether a driver exception is a unique / primary key violation.
     *
     * SQLSTATE 23000 covers integrity constraint violations across drivers, which
     * is what makes this usable on both MariaDB and SQLite; the MySQL-specific
     * 1062 is checked as well so a MariaDB duplicate is recognised even when the
     * SQLSTATE is reported differently.
     */
    public static function isUniqueViolation(QueryException $exception): bool
    {
        if (self::driverCode($exception) === self::SQLSTATE_DUPLICATE_ENTRY) {
            return true;
        }

        return (string) $exception->getCode() === '23000';
    }

    /**
     * The numeric driver error code, or null when the driver reported none.
     */
    private static function driverCode(QueryException $exception): ?int
    {
        $code = $exception->errorInfo[1] ?? null;

        return is_int($code) ? $code : null;
    }
}
