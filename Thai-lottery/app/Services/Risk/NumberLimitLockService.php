<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\BetType;
use App\Exceptions\HotNumberException;
use App\Exceptions\RiskConfigurationException;
use App\Exceptions\RiskException;
use App\Models\NumberLimit;
use Illuminate\Support\Facades\DB;

/**
 * Takes pessimistic row locks on number_limits rows.
 *
 * WHY A ROW LOCK IS THE ONLY CORRECT MECHANISM HERE
 * The migration for number_limits states it plainly: no concurrency logic lives in
 * the schema. The unique key (draw_id, bet_type, number) plus row locking is what
 * the risk service must build on, and it must re-check the ceiling inside its own
 * transaction. There is no CHECK constraint tying current_amount to max_amount, and
 * none tying current_payout_exposure to maximum_payout_exposure, so the database
 * will happily store 1100.00 against a ceiling of 1000.00 if the application asks
 * it to. The ceiling is enforced by re-reading the row under SELECT ... FOR UPDATE
 * and comparing exactly, which is what this class makes possible.
 *
 * A read taken before the lock is worthless for a decision: between the read and
 * the write another request can consume the same headroom. Every value used in a
 * ceiling comparison must therefore come from the row as returned by these methods,
 * not from a value read earlier.
 *
 * TRANSACTION OWNERSHIP
 * This service NEVER begins, commits or rolls back a transaction. It refuses to lock
 * when DB::transactionLevel() is 0, because a FOR UPDATE outside a transaction is
 * released the instant the statement returns and provides no protection whatsoever,
 * while looking exactly like protection. The caller owns the transaction, which is
 * what lets a bet reserve number capacity and debit a wallet atomically in a later
 * phase.
 *
 * DEADLOCK PREVENTION
 * When several rows must be locked, they are locked in ascending primary-key order,
 * never in the order the caller listed them and never in the order a request
 * happened to arrive. Two concurrent requests covering an overlapping set of numbers
 * therefore always take their locks in the same sequence, so they queue instead of
 * deadlocking. lockMany() resolves ids with a non-locking read first purely to
 * establish that order; the resolved values are discarded and every row is re-read
 * under its own lock.
 *
 * NO IDEMPOTENCY HERE
 * App\Services\Finance\IdempotencyService already owns request de-duplication, and a
 * second system would be a second source of truth. This class does not invent one.
 */
class NumberLimitLockService
{
    public function __construct(private readonly NumberLimitResolver $resolver)
    {
    }

    /**
     * Whether the caller has an open transaction.
     */
    public function insideTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /**
     * Lock the row governing one triple and return it, freshly read.
     *
     * The number must already be canonical. The returned model is the only safe
     * source of current_amount and current_payout_exposure for the rest of the
     * critical section.
     *
     * @throws RiskException when there is no open transaction
     * @throws RiskConfigurationException with reason MISSING_LIMIT when no row exists
     */
    public function lock(int $drawId, BetType $betType, string $number): NumberLimit
    {
        $this->assertInsideTransaction('lock a number limit');

        $limit = NumberLimit::query()
            ->where('draw_id', $drawId)
            ->where('bet_type', $betType->value)
            ->where('number', $number)
            ->lockForUpdate()
            ->first();

        if (! $limit instanceof NumberLimit) {
            throw RiskConfigurationException::missingLimit($number, $betType->value, $drawId);
        }

        return $limit;
    }

    /**
     * Lock a row by primary key.
     *
     * Used when the id is already known, which avoids a second lookup by unique key.
     *
     * @throws RiskException
     * @throws RiskConfigurationException
     */
    public function lockById(int $numberLimitId): NumberLimit
    {
        $this->assertInsideTransaction('lock a number limit');

        $limit = NumberLimit::query()
            ->whereKey($numberLimitId)
            ->lockForUpdate()
            ->first();

        if (! $limit instanceof NumberLimit) {
            throw RiskConfigurationException::invalidLimit(
                'the number limit no longer exists',
                ['number_limit_id' => $numberLimitId],
            );
        }

        return $limit;
    }

    /**
     * Lock a row and verify it is in a state that may accept new liability.
     *
     * The status check happens INSIDE the lock on purpose: an operator suspending a
     * number between an earlier read and this moment must win, and only a locked read
     * can guarantee that.
     *
     * @throws RiskException
     * @throws RiskConfigurationException when the row is missing or unusable
     * @throws HotNumberException when the row exists but its status forbids selling
     */
    public function lockActive(int $drawId, BetType $betType, string $number): NumberLimit
    {
        $limit = $this->lock($drawId, $betType, $number);

        $this->assertActive($limit, $number, $betType, $drawId);

        return $limit;
    }

    /**
     * Re-lock a model the caller already holds, returning a freshly read instance.
     *
     * Useful when a model was loaded before the transaction opened; the stale
     * instance must not be trusted for any amount.
     *
     * @throws RiskException
     * @throws RiskConfigurationException
     */
    public function relock(NumberLimit $limit): NumberLimit
    {
        return $this->lockById((int) $limit->getKey());
    }

    /**
     * Lock several rows in a deadlock-safe order.
     *
     * $triples is a list of ['draw_id' => int, 'bet_type' => BetType, 'number' =>
     * string] entries. Duplicates are collapsed, because locking the same row twice
     * in one transaction is pointless and would hide a caller bug.
     *
     * The returned map is keyed by the canonical composite key
     * "<draw_id>:<bet_type>:<number>" so the caller can find each row without
     * relying on ordering, while the locks themselves were taken in ascending id
     * order.
     *
     * @param  list<array{draw_id: int, bet_type: BetType, number: string}>  $triples
     * @return array<string, NumberLimit>
     *
     * @throws RiskException
     * @throws RiskConfigurationException when any row is missing
     */
    public function lockMany(array $triples): array
    {
        $this->assertInsideTransaction('lock several number limits');

        if ($triples === []) {
            return [];
        }

        $wanted = [];

        foreach ($triples as $triple) {
            $wanted[$this->compositeKey($triple['draw_id'], $triple['bet_type'], $triple['number'])] = $triple;
        }

        // Non-locking pre-read used ONLY to establish a deterministic lock order.
        // None of these values is used in a decision; every row is re-read below
        // under its own lock.
        $order = [];

        foreach ($wanted as $key => $triple) {
            $id = NumberLimit::query()
                ->where('draw_id', $triple['draw_id'])
                ->where('bet_type', $triple['bet_type']->value)
                ->where('number', $triple['number'])
                ->value('id');

            if ($id === null) {
                throw RiskConfigurationException::missingLimit(
                    $triple['number'],
                    $triple['bet_type']->value,
                    $triple['draw_id'],
                );
            }

            $order[$key] = (int) $id;
        }

        // Ascending primary key: the single ordering every process agrees on.
        asort($order, SORT_NUMERIC);

        $locked = [];

        foreach ($order as $key => $id) {
            $locked[$key] = $this->lockById($id);
        }

        return $locked;
    }

    /**
     * Lock every limit row belonging to one draw, in ascending id order.
     *
     * Intended for administrative recalculation, not for the bet path: locking a
     * whole draw would serialise all betting on it.
     *
     * @return list<NumberLimit>
     *
     * @throws RiskException
     */
    public function lockDraw(int $drawId, ?BetType $betType = null): array
    {
        $this->assertInsideTransaction('lock every number limit on a draw');

        $query = NumberLimit::query()
            ->where('draw_id', $drawId)
            ->orderBy('id')
            ->lockForUpdate();

        if ($betType instanceof BetType) {
            $query->where('bet_type', $betType->value);
        }

        return $query->get()->all();
    }

    /**
     * Verify a locked row may accept new liability.
     *
     * @throws HotNumberException when the status forbids selling
     * @throws RiskConfigurationException when the status cannot be read
     */
    public function assertActive(
        NumberLimit $limit,
        ?string $number = null,
        ?BetType $betType = null,
        ?int $drawId = null,
    ): void {
        $status = $this->resolver->statusOf($limit);

        if ($status->allowsBetting()) {
            return;
        }

        $betTypeValue = $betType instanceof BetType
            ? $betType->value
            : $this->betTypeValue($limit);

        throw HotNumberException::blockedByStatus(
            $number ?? (string) $limit->getAttribute('number'),
            $betTypeValue,
            $status,
            (int) $limit->getKey(),
            $drawId ?? (int) $limit->getAttribute('draw_id'),
        );
    }

    /**
     * A row lock is only meaningful inside a transaction, so refuse to hand out a
     * false sense of safety.
     *
     * @throws RiskException
     */
    public function assertInsideTransaction(string $operation): void
    {
        if (! $this->insideTransaction()) {
            throw RiskException::withCode(
                'number_limit_lock_outside_transaction',
                sprintf(
                    'Refusing to %s outside a database transaction: a row lock taken without a '
                    .'surrounding transaction is released immediately and provides no concurrency '
                    .'protection.',
                    $operation,
                ),
            );
        }
    }

    /**
     * Stable composite key for a triple.
     */
    public function compositeKey(int $drawId, BetType $betType, string $number): string
    {
        return $drawId.':'.$betType->value.':'.$number;
    }

    /**
     * The bet type of a limit row as a plain string.
     *
     * The model casts bet_type to App\Enums\BetType, so the raw attribute is an enum
     * instance and must be unwrapped.
     */
    private function betTypeValue(NumberLimit $limit): string
    {
        $betType = $limit->getAttribute('bet_type');

        return $betType instanceof BetType ? $betType->value : (string) $betType;
    }
}
