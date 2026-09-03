<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The orchestrator of the financial core. Owns the atomic boundary.
 *
 * ONE OPERATION, ONE TRANSACTION
 * execute() is the single entry point through which money moves. Inside one
 * database transaction it:
 *
 *   1. claims the idempotency key (the unique index arbitrates concurrent retries)
 *   2. takes a row lock on the wallet
 *   3. creates the FinancialTransaction in `pending`
 *   4. moves it to `processing`
 *   5. mutates the wallet balance under the lock
 *   6. posts the balanced double-entry set
 *   7. moves it to `completed` and stamps processed_at
 *
 * Any failure at any step throws, the transaction rolls back, and the wallet, the
 * transaction record and the ledger are all left exactly as they were. There is
 * no code path that writes a balance change without its ledger entries, and none
 * that marks a transaction completed without a balanced posting.
 *
 * STATUS DISCIPLINE
 * Transitions go through TransactionStatus::canTransitionTo(), the existing
 * audited state machine. No second status vocabulary is introduced.
 *
 * DEADLOCK RETRY
 * MySQL/MariaDB may pick a transaction as a deadlock victim (1213) or time out
 * waiting for a lock (1205). Both roll the whole transaction back, so nothing was
 * applied and retrying is safe. It is also safe with respect to duplication:
 * the retry reuses the same idempotency key, so even if the first attempt had
 * partially committed - which it cannot - the unique index would refuse a second
 * transaction for that key.
 */
final class FinancialTransactionService
{
    /** MySQL/MariaDB: deadlock found while trying to get lock. */
    private const ERROR_DEADLOCK = 1213;

    /** MySQL/MariaDB: lock wait timeout exceeded. */
    private const ERROR_LOCK_WAIT_TIMEOUT = 1205;

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly WalletLockService $locks,
        private readonly LedgerPostingService $ledger,
        private readonly WalletService $wallets,
        private readonly LedgerBalanceValidator $validator,
    ) {
    }

    /**
     * Execute a complete, atomic, idempotent wallet movement.
     *
     * @param  LedgerEntryType  $walletSide  Credit = money into the wallet,
     *                                       Debit = money out of the wallet
     * @param  array<string, mixed>  $options  description, metadata, fee,
     *                                         reference_type, reference_id
     *
     * @throws FinancialException
     */
    public function execute(
        Wallet $wallet,
        Money $amount,
        FinancialTransactionType $type,
        LedgerEntryType $walletSide,
        ?string $idempotencyKey = null,
        array $options = [],
    ): FinancialTransaction {
        Money::assertExactArithmeticIsAvailable();
        $amount->assertPositive('transaction amount');

        $walletId = $wallet->getKey();

        if (! is_int($walletId) && ! is_numeric($walletId)) {
            throw FinancialException::withCode(
                'wallet_not_persisted',
                'Cannot execute a financial transaction against an unpersisted wallet.',
            );
        }

        $walletId = (int) $walletId;

        if ($type === FinancialTransactionType::Reversal) {
            throw FinancialException::withCode(
                'transaction_reversal_requires_reversal_service',
                'A reversal must be created through FinancialReversalService so that the '
                .'original transaction and its entries are mirrored exactly.',
            );
        }

        if ($idempotencyKey === null && $this->idempotency->isKeyRequiredFor($type)) {
            throw FinancialException::withCode(
                'idempotency_key_required',
                sprintf('An idempotency key is required for %s transactions.', $type->value),
                ['transaction_type' => $type->value],
            );
        }

        $key = $idempotencyKey ?? $this->idempotency->deterministicKey(
            'auto',
            implode('|', [
                $type->value,
                $walletId,
                $amount->toString(),
                $amount->currency()->value,
                (string) Str::uuid(),
            ]),
        );

        return $this->executeWithDeadlockRetry(
            fn (): FinancialTransaction => DB::transaction(
                fn (): FinancialTransaction => $this->performInsideTransaction(
                    $walletId,
                    $amount,
                    $type,
                    $walletSide,
                    $key,
                    $options,
                ),
            ),
        );
    }

    /**
     * Run a unit of work, retrying transient lock failures.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $work
     * @return TReturn
     *
     * @throws Throwable
     */
    public function executeWithDeadlockRetry(\Closure $work): mixed
    {
        $maxAttempts = max(1, (int) config('finance.locking.max_retries', 3));
        $delayMilliseconds = max(0, (int) config('finance.locking.retry_delay_milliseconds', 150));

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $work();
            } catch (QueryException $exception) {
                if (! $this->isRetryableLockFailure($exception) || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                // The whole transaction was rolled back by the database, so no
                // partial effect survives and retrying cannot duplicate money.
                if ($delayMilliseconds > 0) {
                    usleep($delayMilliseconds * 1000 * $attempt);
                }
            }
        }
    }

    /**
     * Mark a transaction as failed. Used by callers that must record a refusal.
     *
     * Only lifecycle fields are touched; monetary fields are never rewritten.
     *
     * @throws FinancialException
     */
    public function markFailed(FinancialTransaction $transaction, ?string $reason = null): FinancialTransaction
    {
        $this->transitionTo($transaction, TransactionStatus::Failed);

        if ($reason !== null) {
            $metadata = $transaction->metadata ?? [];
            $metadata['failure_reason'] = $reason;
            $transaction->metadata = $metadata;
        }

        $transaction->save();

        return $transaction;
    }

    /**
     * Generate a unique, human-readable reference number.
     *
     * Shape: TX-20260826-9F2C4A1B7E3D. The prefix is configurable, the date makes
     * support conversations easier, and the random tail plus the unique index
     * guarantee no collision. The whole value stays inside the 64-character
     * column.
     */
    public function generateReferenceNumber(): string
    {
        $prefix = (string) config('finance.transaction.reference_prefix', 'TX');
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'TX');
        $prefix = substr($prefix, 0, 8);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = sprintf(
                '%s-%s-%s',
                $prefix,
                Carbon::now()->format('Ymd'),
                strtoupper(bin2hex(random_bytes(6))),
            );

            $candidate = substr($candidate, 0, 64);

            if (! FinancialTransaction::withTrashed()->where('reference_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw FinancialException::withCode(
            'transaction_reference_generation_failed',
            'Could not generate a unique transaction reference number after several attempts.',
        );
    }

    /**
     * Move a transaction through the audited state machine.
     *
     * @throws FinancialException
     */
    public function transitionTo(FinancialTransaction $transaction, TransactionStatus $target): FinancialTransaction
    {
        if (! $transaction->status->canTransitionTo($target)) {
            throw FinancialException::withCode(
                'transaction_invalid_status_transition',
                sprintf(
                    'A %s transaction cannot transition to %s.',
                    $transaction->status->value,
                    $target->value,
                ),
                [
                    'transaction_id' => (int) $transaction->getKey(),
                    'from_status' => $transaction->status->value,
                    'to_status' => $target->value,
                ],
            );
        }

        // status is deliberately not mass assignable on the model.
        $transaction->status = $target;

        return $transaction;
    }

    /**
     * The full body of one money movement. Always runs inside a transaction.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws FinancialException
     */
    private function performInsideTransaction(
        int $walletId,
        Money $amount,
        FinancialTransactionType $type,
        LedgerEntryType $walletSide,
        string $key,
        array $options,
    ): FinancialTransaction {
        // Lock first, then claim the key. Taking the wallet lock before the
        // idempotency insert keeps the lock acquisition order identical for every
        // caller, which is what prevents deadlocks between competing requests.
        $wallet = $walletSide === LedgerEntryType::Credit
            ? $this->locks->lockForCredit($walletId, $amount->currency())
            : $this->locks->lockForDebit($walletId, $amount->currency());

        $expectation = [
            'type' => $type,
            'amount' => $amount->toString(),
            'currency' => $amount->currency()->value,
            'wallet_id' => $walletId,
            'user_id' => $wallet->user_id,
        ];

        $claim = $this->idempotency->claim(
            $key,
            $expectation,
            fn (string $claimedKey): FinancialTransaction => $this->createTransaction(
                $wallet,
                $amount,
                $type,
                $claimedKey,
                $options,
            ),
        );

        /** @var FinancialTransaction $transaction */
        $transaction = $claim['transaction'];

        if ($claim['replayed'] === true) {
            // A legitimate retry: the money already moved exactly once. Return
            // the original transaction untouched - do not post again, do not
            // mutate the balance again.
            return $transaction;
        }

        $this->transitionTo($transaction, TransactionStatus::Processing)->save();

        $balanceAfter = $walletSide === LedgerEntryType::Credit
            ? $this->wallets->applyCredit($wallet, $amount, $type)
            : $this->wallets->applyDebit($wallet, $amount, $type);

        $this->ledger->post(
            $transaction,
            $this->buildEntries($transaction, $wallet, $amount, $type, $walletSide),
            [0 => $balanceAfter->toString()],
        );

        $this->validator->assertTransactionBalanced($transaction);

        $this->transitionTo($transaction, TransactionStatus::Completed);
        $transaction->processed_at = Carbon::now();
        $transaction->save();

        return $transaction->refresh();
    }

    /**
     * Create the pending transaction record that owns the idempotency key.
     *
     * @param  array<string, mixed>  $options
     */
    private function createTransaction(
        Wallet $wallet,
        Money $amount,
        FinancialTransactionType $type,
        string $key,
        array $options,
    ): FinancialTransaction {
        $fee = $options['fee'] ?? null;

        if ($fee instanceof Money) {
            $fee->assertNotNegative('fee');
            $feeAmount = $fee->toString();
        } elseif (is_string($fee) || is_int($fee)) {
            $feeAmount = Money::of($fee, $amount->currency())->assertNotNegative('fee')->toString();
        } else {
            $feeAmount = Money::zero($amount->currency())->toString();
        }

        $transaction = new FinancialTransaction();

        $transaction->fill([
            'reference_number' => $this->generateReferenceNumber(),
            'user_id' => $wallet->user_id,
            'wallet_id' => (int) $wallet->getKey(),
            'type' => $type->toTransactionType(),
            'currency' => $amount->currency(),
            'amount' => $amount->toString(),
            'fee' => $feeAmount,
            'description' => isset($options['description']) && is_string($options['description'])
                ? $options['description']
                : $type->label(),
            'metadata' => $this->buildMetadata($type, $options),
            'idempotency_key' => $key,
            'reference_type' => isset($options['reference_type']) && is_string($options['reference_type'])
                ? $options['reference_type']
                : null,
            'reference_id' => isset($options['reference_id']) && is_int($options['reference_id'])
                ? $options['reference_id']
                : null,
        ]);

        // uuid is not mass assignable; it is a system identifier.
        $transaction->uuid = (string) Str::uuid();
        $transaction->status = TransactionStatus::Pending;

        $transaction->save();

        return $transaction;
    }

    /**
     * Metadata that keeps the engine's own vocabulary recoverable.
     *
     * FinancialTransactionType is finer grained than the persisted
     * TransactionType (bet_debit and fee both collapse on the way in), so the
     * engine type is recorded here. This is documentation of a schema limitation,
     * not a second status system: the authoritative type column is unchanged.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildMetadata(FinancialTransactionType $type, array $options): array
    {
        $metadata = isset($options['metadata']) && is_array($options['metadata'])
            ? $options['metadata']
            : [];

        $metadata['engine_type'] = $type->value;

        return $metadata;
    }

    /**
     * Build the two-sided posting for a wallet movement.
     *
     * A player wallet is a liability of the operator, so:
     *   money in  -> CREDIT player liability, DEBIT the counterpart
     *   money out -> DEBIT player liability, CREDIT the counterpart
     *
     * The wallet-facing entry is always first, so the caller's balance_after
     * snapshot lines up with index 0.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws FinancialException
     */
    private function buildEntries(
        FinancialTransaction $transaction,
        Wallet $wallet,
        Money $amount,
        FinancialTransactionType $type,
        LedgerEntryType $walletSide,
    ): array {
        $liabilityAccount = $this->ledger->resolveAccount($this->wallets->walletAccountCode());
        $counterpartAccount = $this->ledger->resolveAccount(
            $this->wallets->counterpartAccountCode($type, $walletSide),
        );

        $this->assertAccountCurrency($liabilityAccount->currency, $amount->currency());
        $this->assertAccountCurrency($counterpartAccount->currency, $amount->currency());

        return [
            [
                'ledger_account_id' => (int) $liabilityAccount->getKey(),
                'type' => $walletSide,
                'amount' => $amount,
                'wallet_id' => (int) $wallet->getKey(),
                'description' => $transaction->description,
            ],
            [
                'ledger_account_id' => (int) $counterpartAccount->getKey(),
                'type' => $walletSide->opposite(),
                'amount' => $amount,
                'wallet_id' => null,
                'description' => $transaction->description,
            ],
        ];
    }

    /**
     * @throws FinancialException
     */
    private function assertAccountCurrency(Currency $accountCurrency, Currency $amountCurrency): void
    {
        if ($accountCurrency !== $amountCurrency) {
            throw FinancialException::withCode(
                'ledger_account_currency_mismatch',
                sprintf(
                    'A %s ledger account cannot receive a %s posting.',
                    $accountCurrency->value,
                    $amountCurrency->value,
                ),
                [
                    'account_currency' => $accountCurrency->value,
                    'amount_currency' => $amountCurrency->value,
                ],
            );
        }
    }

    /**
     * Deadlocks and lock-wait timeouts are transient; everything else is not.
     */
    private function isRetryableLockFailure(QueryException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        if (is_int($driverCode)) {
            return in_array($driverCode, [self::ERROR_DEADLOCK, self::ERROR_LOCK_WAIT_TIMEOUT], true);
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'deadlock found')
            || str_contains($message, 'lock wait timeout exceeded');
    }
}
