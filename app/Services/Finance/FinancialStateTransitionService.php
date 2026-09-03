<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\DepositStatus;
use App\Enums\TransactionStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\InvalidFinancialStateTransitionException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only place a deposit or withdrawal status is allowed to change.
 *
 * WHY A DEDICATED SERVICE
 * -----------------------
 * A status is not decoration: on these two tables it is the record of whether
 * real money moved. If any service could assign a status freely, a mistake or an
 * admin screen could "fix" an accounting problem by writing
 * completed -> pending, and the wallet balance would then disagree with the
 * ledger with no audit trail of the change. So every status write goes through
 * transitionDeposit() / transitionWithdrawal(), which consult one explicit map.
 *
 * TERMINAL STATES ARE FINAL
 * Deposits: confirmed, rejected, failed, cancelled, refunded.
 * Withdrawals: completed, rejected, failed, cancelled.
 * Nothing moves out of these, with exactly one intentional exception: a confirmed
 * deposit may move to `refunded`, which is a forward step describing a NEW
 * compensating financial transaction, not a rollback of the old one.
 *
 * A REVERSAL IS NOT A STATUS RESET
 * There is deliberately no method here that reverses money. Undoing a settled
 * amount is the job of FinancialReversalService, which writes a brand new
 * financial transaction with its own balanced ledger entries and leaves the
 * original row intact. This service refuses any backwards move and says so in
 * the exception message.
 *
 * WHAT THIS SERVICE MAY WRITE
 * The status column plus a small allow-list of lifecycle columns (timestamps,
 * review trail, failure reason, transaction link, metadata). It can never write
 * amount, fee, net_amount, currency, wallet_id or user_id: an attempt to pass one
 * of those is rejected. So a status change can never quietly become a money
 * change.
 *
 * TRANSACTION AND LOCK DISCIPLINE
 * Every mutating method requires an already-open database transaction, and the
 * row is re-read under `FOR UPDATE` before the status is compared, so two
 * concurrent callers cannot both see the same "from" state. This service never
 * opens a transaction of its own and never locks a wallet: the caller has already
 * taken the wallet lock, and the project-wide order is
 * WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS.
 */
final class FinancialStateTransitionService
{
    /** Short, non-sensitive entity labels used in exceptions. */
    public const ENTITY_DEPOSIT = 'deposit';

    public const ENTITY_WITHDRAWAL = 'withdrawal';

    public const ENTITY_FINANCIAL_TRANSACTION = 'financial_transaction';

    /**
     * Lifecycle columns a deposit transition may also write.
     *
     * @var list<string>
     */
    private const DEPOSIT_WRITABLE_COLUMNS = [
        'financial_transaction_id',
        'confirmed_at',
        'failed_at',
        'failure_reason',
        'provider',
        'provider_reference',
        'metadata',
    ];

    /**
     * Lifecycle columns a withdrawal transition may also write.
     *
     * @var list<string>
     */
    private const WITHDRAWAL_WRITABLE_COLUMNS = [
        'financial_transaction_id',
        'reviewed_by',
        'requested_at',
        'reviewed_at',
        'approved_at',
        'completed_at',
        'rejected_at',
        'rejection_reason',
        'provider',
        'provider_reference',
        'metadata',
    ];

    /**
     * Columns that may never be written by a status transition, whatever the
     * caller passes. Money is moved by the finance engine, never by a status.
     *
     * @var list<string>
     */
    private const FORBIDDEN_COLUMNS = [
        'amount',
        'fee',
        'net_amount',
        'currency',
        'wallet_id',
        'user_id',
        'id',
        'uuid',
        'reference_number',
        'idempotency_key',
        'deleted_at',
    ];

    /**
     * The full deposit transition map, in one readable place.
     *
     * Derived from DepositStatus::allowedTransitions() so the enum and the service
     * can never drift apart, and returned as a plain array for reporting.
     *
     * @return array<string, list<string>>
     */
    public function depositTransitionMap(): array
    {
        $map = [];

        foreach (DepositStatus::cases() as $case) {
            $map[$case->value] = array_map(
                static fn (DepositStatus $target): string => $target->value,
                $case->allowedTransitions(),
            );
        }

        return $map;
    }

    /**
     * The full withdrawal transition map.
     *
     * @return array<string, list<string>>
     */
    public function withdrawalTransitionMap(): array
    {
        $map = [];

        foreach (WithdrawalStatus::cases() as $case) {
            $map[$case->value] = array_map(
                static fn (WithdrawalStatus $target): string => $target->value,
                $case->allowedTransitions(),
            );
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function terminalDepositStatuses(): array
    {
        return array_values(array_map(
            static fn (DepositStatus $case): string => $case->value,
            array_filter(DepositStatus::cases(), static fn (DepositStatus $case): bool => $case->isTerminal()),
        ));
    }

    /**
     * @return list<string>
     */
    public function terminalWithdrawalStatuses(): array
    {
        return array_values(array_map(
            static fn (WithdrawalStatus $case): string => $case->value,
            array_filter(WithdrawalStatus::cases(), static fn (WithdrawalStatus $case): bool => $case->isTerminal()),
        ));
    }

    public function canTransitionDeposit(DepositStatus $current, DepositStatus $target): bool
    {
        return $current->canTransitionTo($target);
    }

    public function canTransitionWithdrawal(WithdrawalStatus $current, WithdrawalStatus $target): bool
    {
        return $current->canTransitionTo($target);
    }

    /**
     * Refuse an illegal deposit transition without writing anything.
     *
     * @throws InvalidFinancialStateTransitionException
     */
    public function assertDepositTransition(Deposit $deposit, DepositStatus $target): void
    {
        $current = $deposit->status;

        if ($current === $target) {
            throw InvalidFinancialStateTransitionException::forEntity(
                self::ENTITY_DEPOSIT,
                $this->keyOf($deposit),
                $current->value,
                $target->value,
                ['reason' => 'redundant_transition'],
            );
        }

        if ($current->isTerminal() && ! $current->canTransitionTo($target)) {
            throw InvalidFinancialStateTransitionException::terminalState(
                self::ENTITY_DEPOSIT,
                $this->keyOf($deposit),
                $current->value,
                $target->value,
            );
        }

        if (! $current->canTransitionTo($target)) {
            throw InvalidFinancialStateTransitionException::forEntity(
                self::ENTITY_DEPOSIT,
                $this->keyOf($deposit),
                $current->value,
                $target->value,
                ['allowed' => implode(',', $this->depositTransitionMap()[$current->value] ?? [])],
            );
        }
    }

    /**
     * Refuse an illegal withdrawal transition without writing anything.
     *
     * @throws InvalidFinancialStateTransitionException
     */
    public function assertWithdrawalTransition(Withdrawal $withdrawal, WithdrawalStatus $target): void
    {
        $current = $withdrawal->status;

        if ($current === $target) {
            throw InvalidFinancialStateTransitionException::forEntity(
                self::ENTITY_WITHDRAWAL,
                $this->keyOf($withdrawal),
                $current->value,
                $target->value,
                ['reason' => 'redundant_transition'],
            );
        }

        if ($current->isTerminal() && ! $current->canTransitionTo($target)) {
            throw InvalidFinancialStateTransitionException::terminalState(
                self::ENTITY_WITHDRAWAL,
                $this->keyOf($withdrawal),
                $current->value,
                $target->value,
            );
        }

        if (! $current->canTransitionTo($target)) {
            throw InvalidFinancialStateTransitionException::forEntity(
                self::ENTITY_WITHDRAWAL,
                $this->keyOf($withdrawal),
                $current->value,
                $target->value,
                ['allowed' => implode(',', $this->withdrawalTransitionMap()[$current->value] ?? [])],
            );
        }
    }

    /**
     * Validate a financial transaction status change without writing.
     *
     * The write itself stays in FinancialTransactionService::transitionTo(), which
     * already owns that column; this method exists so the workflow services can
     * check the move before they start doing work.
     *
     * @throws InvalidFinancialStateTransitionException
     */
    public function assertTransactionTransition(FinancialTransaction $transaction, TransactionStatus $target): void
    {
        $current = $transaction->status;

        if (! $current instanceof TransactionStatus) {
            $current = TransactionStatus::from((string) $current);
        }

        if ($current === $target || ! $transaction->canTransitionTo($target)) {
            throw InvalidFinancialStateTransitionException::forEntity(
                self::ENTITY_FINANCIAL_TRANSACTION,
                $this->keyOf($transaction),
                $current->value,
                $target->value,
            );
        }
    }

    /**
     * Move a deposit to a new status, atomically and under a row lock.
     *
     * @param  array<string, mixed>  $attributes  lifecycle columns to write with the status
     *
     * @throws InvalidFinancialStateTransitionException
     * @throws FinancialException
     */
    public function transitionDeposit(Deposit $deposit, DepositStatus $target, array $attributes = []): Deposit
    {
        $this->assertInsideTransaction('deposit status transition');
        $this->assertAttributesAllowed($attributes, self::DEPOSIT_WRITABLE_COLUMNS, self::ENTITY_DEPOSIT);

        $fresh = $this->lockDeposit($deposit);

        $this->assertDepositTransition($fresh, $target);

        $fresh->status = $target;

        foreach ($attributes as $column => $value) {
            $fresh->{$column} = $value;
        }

        $fresh->save();

        return $fresh;
    }

    /**
     * Move a withdrawal to a new status, atomically and under a row lock.
     *
     * @param  array<string, mixed>  $attributes  lifecycle columns to write with the status
     *
     * @throws InvalidFinancialStateTransitionException
     * @throws FinancialException
     */
    public function transitionWithdrawal(Withdrawal $withdrawal, WithdrawalStatus $target, array $attributes = []): Withdrawal
    {
        $this->assertInsideTransaction('withdrawal status transition');
        $this->assertAttributesAllowed($attributes, self::WITHDRAWAL_WRITABLE_COLUMNS, self::ENTITY_WITHDRAWAL);

        $fresh = $this->lockWithdrawal($withdrawal);

        $this->assertWithdrawalTransition($fresh, $target);

        $fresh->status = $target;

        foreach ($attributes as $column => $value) {
            $fresh->{$column} = $value;
        }

        $fresh->save();

        return $fresh;
    }

    /**
     * Re-read a deposit with `FOR UPDATE`.
     *
     * Called by the deposit services so the row is pinned before its status is
     * inspected. Requires an open transaction, because a row lock outside one is
     * released immediately and therefore proves nothing.
     *
     * @throws FinancialException
     */
    public function lockDeposit(Deposit $deposit): Deposit
    {
        $this->assertInsideTransaction('deposit row lock');

        $key = $this->keyOf($deposit);

        if ($key === null) {
            throw FinancialException::withCode(
                'financial_entity_not_persisted',
                'An unsaved deposit cannot be locked.',
            );
        }

        $locked = Deposit::query()->whereKey($key)->lockForUpdate()->first();

        if (! $locked instanceof Deposit) {
            throw FinancialException::withCode(
                'financial_entity_missing',
                sprintf('Deposit %d no longer exists.', $key),
                ['deposit_id' => $key],
            );
        }

        return $locked;
    }

    /**
     * Re-read a withdrawal with `FOR UPDATE`.
     *
     * This is what makes two simultaneous approvals impossible: the second caller
     * blocks here, and when it proceeds it sees the status the first one wrote.
     *
     * @throws FinancialException
     */
    public function lockWithdrawal(Withdrawal $withdrawal): Withdrawal
    {
        $this->assertInsideTransaction('withdrawal row lock');

        $key = $this->keyOf($withdrawal);

        if ($key === null) {
            throw FinancialException::withCode(
                'financial_entity_not_persisted',
                'An unsaved withdrawal cannot be locked.',
            );
        }

        $locked = Withdrawal::query()->whereKey($key)->lockForUpdate()->first();

        if (! $locked instanceof Withdrawal) {
            throw FinancialException::withCode(
                'financial_entity_missing',
                sprintf('Withdrawal %d no longer exists.', $key),
                ['withdrawal_id' => $key],
            );
        }

        return $locked;
    }

    /**
     * Whether a database transaction is currently open.
     */
    public function insideTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /**
     * Reject any attempt to write a column a status transition must not touch.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $allowed
     *
     * @throws FinancialException
     */
    private function assertAttributesAllowed(array $attributes, array $allowed, string $entityType): void
    {
        foreach (array_keys($attributes) as $column) {
            $name = (string) $column;

            if (in_array($name, self::FORBIDDEN_COLUMNS, true)) {
                throw FinancialException::withCode(
                    'financial_state_transition_forbidden_column',
                    sprintf(
                        'Column "%s" can never be written by a %s status transition. Money is moved by the finance engine, not by a status change.',
                        $name,
                        $entityType,
                    ),
                    ['entity_type' => $entityType, 'column' => $name],
                );
            }

            if (! in_array($name, $allowed, true)) {
                throw FinancialException::withCode(
                    'financial_state_transition_unknown_column',
                    sprintf('Column "%s" is not a recognised %s lifecycle column.', $name, $entityType),
                    ['entity_type' => $entityType, 'column' => $name],
                );
            }
        }
    }

    /**
     * @throws FinancialException
     */
    private function assertInsideTransaction(string $operation): void
    {
        if ($this->insideTransaction()) {
            return;
        }

        throw FinancialException::withCode(
            'financial_state_transition_outside_transaction',
            sprintf('A %s must run inside a database transaction.', $operation),
            ['operation' => $operation],
        );
    }

    private function keyOf(Model $model): ?int
    {
        $key = $model->getKey();

        return $key === null ? null : (int) $key;
    }
}
