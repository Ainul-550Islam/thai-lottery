<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused or invalid PAYOUT-BATCH step.
 *
 * COVERAGE
 * --------
 * Everything the batch lane may refuse: a batch identity that already
 * exists (duplicate batch), a member set that does not match the claimed
 * aggregate, a state transition the lifecycle forbids, a batch that was
 * never created, or a concurrency race where two executors claimed the
 * same scheduled run.
 *
 * WHAT IT IS NOT
 * --------------
 * PayoutException is about the MONEY of one payout; PayoutTransferException
 * is one transfer leg. A batch exception stops the manufacturing or
 * lifecycle of the GROUP only — it never implies something went wrong at
 * the member money level. Catch sites differ: the generator surfaces batch
 * creation refusals to operations; the executor surfaces lifecycle
 * refusals; member-level money failures flow up as per-payout records,
 * never as this exception.
 *
 * Context: batch keys, statuses, reference numbers, decimal amounts. Never
 * a credential, stack, SQL, or personal datum.
 */
class PayoutBatchException extends RuntimeException
{
    public const CODE_NOT_FOUND = 'PAYOUT_BATCH_NOT_FOUND';

    public const CODE_DUPLICATE = 'PAYOUT_BATCH_DUPLICATE';

    public const CODE_AGGREGATE_MISMATCH = 'PAYOUT_BATCH_AGGREGATE_MISMATCH';

    public const CODE_EMPTY_MEMBER_LIST = 'PAYOUT_BATCH_EMPTY_MEMBER_LIST';

    public const CODE_STATE_FORBIDS = 'PAYOUT_BATCH_STATE_FORBIDS';

    public const CODE_ALREADY_RUNNING = 'PAYOUT_BATCH_ALREADY_RUNNING';

    public const CODE_TRANSITION_RACE = 'PAYOUT_BATCH_TRANSITION_RACE';

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
     * The batch the operation names does not exist.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notFound(string $batchKey, array $context = []): self
    {
        return new self(
            sprintf('Payout batch [%s] does not exist.', $batchKey),
            self::CODE_NOT_FOUND,
            $context + ['batch_key' => $batchKey],
        );
    }

    /**
     * A batch with this identity already exists. Because the identity is a
     * DERIVATION of its member references, a "duplicate" here means one of
     * two things: a replay (join it silently via the service, don't throw)
     * or an attempt to overwrite an existing batch under a generated new
     * row (always refuse; thrown).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function duplicateBatch(string $batchKey, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout batch [%s] already exists; manufacturing a second row off the same identity would split the group across two ledgers.',
                $batchKey,
            ),
            self::CODE_DUPLICATE,
            $context + ['batch_key' => $batchKey],
        );
    }

    /**
     * The sum of the member rows does not equal the creation's claimed
     * aggregate. Creating anyway would make the batch claim money it does
     * not hold — or lose money it does.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function aggregateMismatch(
        string $batchKey,
        string $claimed,
        string $actual,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Payout batch [%s]: claimed aggregate %s does not equal the sum of member payouts %s; the batch cannot be trusted to settle what it names.',
                $batchKey,
                $claimed,
                $actual,
            ),
            self::CODE_AGGREGATE_MISMATCH,
            $context + ['batch_key' => $batchKey, 'claimed' => $claimed, 'actual' => $actual],
        );
    }

    /**
     * A batch without members is not a batch.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function emptyMemberList(string $batchKey, array $context = []): self
    {
        return new self(
            sprintf('Payout batch [%s] names no member payouts; manufacturing an empty batch is refused.', $batchKey),
            self::CODE_EMPTY_MEMBER_LIST,
            $context + ['batch_key' => $batchKey],
        );
    }

    /**
     * The lifecycle forbids the requested transition from the batch's
     * current state.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function stateForbids(
        string $batchKey,
        string $currentStatus,
        string $attemptedStatus,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Payout batch [%s] is %s; it cannot become %s.',
                $batchKey,
                $currentStatus,
                $attemptedStatus,
            ),
            self::CODE_STATE_FORBIDS,
            $context + ['batch_key' => $batchKey, 'current_status' => $currentStatus, 'attempted_status' => $attemptedStatus],
        );
    }

    /**
     * The batch lane owns its transaction boundary.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyRunning(int $transactionLevel, array $context = []): self
    {
        return new self(
            sprintf('Payout batch operations own their transaction boundary; caller is at transaction level %d.', $transactionLevel),
            self::CODE_ALREADY_RUNNING,
            $context + ['transaction_level' => $transactionLevel],
        );
    }

    /**
     * Two executors raced to claim the same batch run: the loser realizes
     * here. The claim flip is atomic, so this code fires for the one who
     * checked the state BEFORE the winner committed.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function transitionRace(string $batchKey, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout batch [%s]: the run-claim raced a concurrent executor and lost; this executor must yield.',
                $batchKey,
            ),
            self::CODE_TRANSITION_RACE,
            $context + ['batch_key' => $batchKey],
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
