<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Pessimistic row locking for wallets.
 *
 * WHY A LOCK IS MANDATORY
 * -----------------------
 * A balance check and the balance update that follows it must be one indivisible
 * step. Without a row lock, two concurrent debits of 80.00 against a balance of
 * 100.00 can both read 100.00, both conclude they are affordable, and both
 * write - leaving -60.00. Holding SELECT ... FOR UPDATE on the wallet row makes
 * the second request wait until the first has committed, so it re-reads the real
 * balance and is correctly refused.
 *
 * TRANSACTION OWNERSHIP - THE CRITICAL RULE
 * This service NEVER calls DB::beginTransaction(), DB::commit() or
 * DB::transaction(). A row lock only lives until the end of the transaction that
 * took it, so if this service opened and committed its own transaction the lock
 * would be released before the caller updated the balance, and the protection
 * would be worthless. Every method therefore refuses to run unless the caller
 * has already opened a transaction; the caller owns the boundary.
 *
 * DRIVER NOTE
 * lockForUpdate() emits FOR UPDATE on MySQL/MariaDB and PostgreSQL. SQLite has
 * no row-level locking and ignores it, relying on its whole-database write lock
 * instead, so concurrency behaviour must be verified on MySQL/MariaDB.
 */
final class WalletLockService
{
    /**
     * Lock a single wallet row for the remainder of the caller's transaction.
     *
     * @throws FinancialException when not inside a transaction, or the wallet
     *                            does not exist
     */
    public function lock(int $walletId): Wallet
    {
        $this->assertInsideTransaction('lock wallet');

        $wallet = Wallet::query()
            ->whereKey($walletId)
            ->lockForUpdate()
            ->first();

        if (! $wallet instanceof Wallet) {
            throw FinancialException::withCode(
                'wallet_not_found',
                sprintf('Wallet %d does not exist or has been deleted.', $walletId),
                ['wallet_id' => $walletId],
            );
        }

        return $wallet;
    }

    /**
     * Lock a wallet and verify it may receive money.
     *
     * @throws FinancialException
     */
    public function lockForCredit(int $walletId, ?Currency $currency = null): Wallet
    {
        $wallet = $this->lock($walletId);

        if (! $wallet->canCredit()) {
            throw FinancialException::withCode(
                'wallet_cannot_be_credited',
                sprintf(
                    'Wallet %d is %s and cannot be credited.',
                    $walletId,
                    $wallet->status->label(),
                ),
                ['wallet_id' => $walletId, 'wallet_status' => $wallet->status->value],
            );
        }

        $this->assertCurrencyMatches($wallet, $currency);

        return $wallet;
    }

    /**
     * Lock a wallet and verify money may be taken out of it.
     *
     * @throws FinancialException
     */
    public function lockForDebit(int $walletId, ?Currency $currency = null): Wallet
    {
        $wallet = $this->lock($walletId);

        if (! $wallet->canDebit()) {
            throw FinancialException::withCode(
                'wallet_cannot_be_debited',
                sprintf(
                    'Wallet %d is %s and cannot be debited.',
                    $walletId,
                    $wallet->status->label(),
                ),
                ['wallet_id' => $walletId, 'wallet_status' => $wallet->status->value],
            );
        }

        $this->assertCurrencyMatches($wallet, $currency);

        return $wallet;
    }

    /**
     * Lock several wallets in one deterministic pass.
     *
     * Identifiers are de-duplicated and sorted ascending before locking, so any
     * two concurrent operations touching the same set of wallets acquire them in
     * the same order and cannot deadlock against each other.
     *
     * @param  list<int>  $walletIds
     * @return array<int, Wallet> keyed by wallet id
     *
     * @throws FinancialException
     */
    public function lockMany(array $walletIds): array
    {
        $this->assertInsideTransaction('lock wallets');

        $ids = array_values(array_unique(array_map('intval', $walletIds)));

        if ($ids === []) {
            throw FinancialException::withCode(
                'wallet_lock_empty_set',
                'At least one wallet identifier is required in order to lock.',
            );
        }

        sort($ids, SORT_NUMERIC);

        /** @var array<int, Wallet> $wallets */
        $wallets = Wallet::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($ids as $id) {
            if (! isset($wallets[$id])) {
                throw FinancialException::withCode(
                    'wallet_not_found',
                    sprintf('Wallet %d does not exist or has been deleted.', $id),
                    ['wallet_id' => $id],
                );
            }
        }

        return $wallets;
    }

    /**
     * Re-read an already loaded wallet under a lock.
     *
     * Use this when a wallet instance was fetched before the transaction began:
     * the in-memory copy may be stale, and only the locked re-read is safe to
     * base a balance decision on.
     *
     * @throws FinancialException
     */
    public function relock(Wallet $wallet): Wallet
    {
        $key = $wallet->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw FinancialException::withCode(
                'wallet_not_persisted',
                'Cannot lock a wallet that has not been persisted.',
            );
        }

        return $this->lock((int) $key);
    }

    /**
     * Whether the caller has an open database transaction.
     */
    public function insideTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /**
     * A row lock is only meaningful inside a transaction, so refuse to hand out
     * a false sense of safety.
     *
     * @throws FinancialException
     */
    private function assertInsideTransaction(string $operation): void
    {
        if (! $this->insideTransaction()) {
            throw FinancialException::withCode(
                'wallet_lock_outside_transaction',
                sprintf(
                    'Refusing to %s outside a database transaction: a row lock taken '
                    .'without a surrounding transaction is released immediately and '
                    .'provides no concurrency protection.',
                    $operation,
                ),
            );
        }
    }

    /**
     * @throws FinancialException
     */
    private function assertCurrencyMatches(Wallet $wallet, ?Currency $currency): void
    {
        if ($currency === null || $wallet->currency === $currency) {
            return;
        }

        throw FinancialException::withCode(
            'wallet_currency_mismatch',
            sprintf(
                'Wallet %d is denominated in %s and cannot process a %s operation.',
                (int) $wallet->getKey(),
                $wallet->currency->value,
                $currency->value,
            ),
            [
                'wallet_id' => (int) $wallet->getKey(),
                'wallet_currency' => $wallet->currency->value,
                'requested_currency' => $currency->value,
            ],
        );
    }
}
