<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\LedgerAdjustmentData;
use App\Enums\AuditAction;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Exceptions\LedgerAdjustmentException;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Operator-authorized, evidence-backed ledger corrections.
 *
 * THE LAW OF THIS LANE
 *   1. NEVER EDITS HISTORY — a correction is a NEW FinancialTransaction
 *      of type Adjustment with its own compensating ledger postings,
 *      produced by the wallet's OWN credit/debit flow. The historical
 *      row behind the mistake is never touched.
 *   2. AUTHORITY — only an admin/super-admin principal may correct
 *      money; the lane refuses everything else by name.
 *   3. EVIDENCE — the ask can't land without a canonical evidence
 *      token + a full-sentence reason; both ride the transaction's
 *      metadata forever.
 *   4. CONSERVATION — the wallet flow owns arithmetic; refuses land
 *      here mapped to its vocabulary, arithmetic never duplicated.
 *   5. IDENTITY — the deterministic idempotency key over (wallet,
 *      evidence, amount) is the replay; a DIFFERENT correction under
 *      the same key is a pronounced fork.
 */
final class LedgerAdjustmentService
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {
    }

    /* ------------------------------------------------------- post --- */

    /**
     * @return array{transaction: FinancialTransaction, replayed: bool}
     *
     * @throws LedgerAdjustmentException
     */
    public function post(LedgerAdjustmentData $data): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->postWithin($data);
        }

        return DB::transaction(fn (): array => $this->postWithin($data));
    }

    /**
     * @return array{transaction: FinancialTransaction, replayed: bool}
     *
     * @throws LedgerAdjustmentException
     */
    private function postWithin(LedgerAdjustmentData $data): array
    {
        // AUTHORITY first, before any paper moves.
        /** @var User|null $operator */
        $operator = User::query()->find($data->operatorUserId);

        if (! $operator instanceof User) {
            throw LedgerAdjustmentException::notFound('operator:'.$data->operatorUserId);
        }

        if (!$operator->isAdmin()) {
            throw LedgerAdjustmentException::unauthorized($data->operatorUserId);
        }

        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->lockForUpdate()->find($data->walletId);

        if (! $wallet instanceof Wallet) {
            throw LedgerAdjustmentException::notFound('wallet:'.$data->walletId);
        }

        $walletCurrency = $wallet->currency instanceof \BackedEnum
            ? strtoupper((string) $wallet->currency->value)
            : strtoupper((string) $wallet->currency);

        if ($walletCurrency !== $data->currency) {
            throw LedgerAdjustmentException::malformed(
                sprintf('the wallet speaks %s, the ask speaks %s', $walletCurrency, $data->currency),
            );
        }

        // IDENTITY: idempotency anchor for the transaction lane itself.
        $idempotencyKey = sprintf('ledger-adj:%s', substr($data->adjustmentKey(), 0, 84));

        $existing = FinancialTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof FinancialTransaction) {
            // Facts fork-check: the agreed amount of THIS key must equal
            // the ask (any different correction under the same key is
            // cried loudly).
            if (bccomp(self::moneyOf((string) $existing->amount), self::absOf($data->amount), 2) !== 0) {
                throw LedgerAdjustmentException::duplicate($data->adjustmentKey());
            }

            return ['transaction' => $existing, 'replayed' => true];
        }

        $abs = self::moneyOf(self::absOf($data->amount));

        $money = \App\Services\Finance\Money::of($abs, \App\Enums\Currency::from($walletCurrency));

        $options = [
            'description' => sprintf('Adjustment by operator #%d: %s', $data->operatorUserId, $data->reason),
            'metadata' => [
                'evidence' => $data->sourceEvidence,
                'operator_user_id' => $data->operatorUserId,
                'reason' => $data->reason,
                'lane' => 'ledger-adjustment',
            ],
        ];

        try {
            $isCredit = ! str_starts_with(ltrim($data->amount, '+'), '-');

            $transaction = $isCredit
                ? $this->wallets->credit($wallet, $money, FinancialTransactionType::Adjustment, $idempotencyKey, $options)
                : $this->wallets->debit($wallet, $money, FinancialTransactionType::Adjustment, $idempotencyKey, $options);
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            throw LedgerAdjustmentException::conservationViolation(
                $data->walletId,
                sprintf('asked %s with %s available', $abs, $e->availableAmount()),
            );
        } catch (\App\Exceptions\FinancialException $e) {
            throw LedgerAdjustmentException::conservationViolation($data->walletId, (string) $e->getMessage());
        }

        $this->recordAudit($data, $transaction, $isCredit ? 'credit' : 'debit');

        return ['transaction' => $transaction, 'replayed' => false];
    }

    /* ------------------------------------------------- internals ---- */

    private static function absOf(string $amount): string
    {
        return ltrim($amount, '+-');
    }

    public static function moneyOf(string $amount): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($amount, '0', 2);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function recordAudit(LedgerAdjustmentData $data, FinancialTransaction $transaction, string $direction): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => $data->operatorUserId,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Critical,
            'auditable_type' => FinancialTransaction::class,
            'auditable_id' => (int) $transaction->getKey(),
            'description' => sprintf(
                'Ledger adjustment (%s) on wallet #%d: %s — %s',
                $direction,
                $data->walletId,
                self::absOf($data->amount),
                $data->reason,
            ),
            'metadata' => [
                'adjustment_key' => $data->adjustmentKey(),
                'wallet_id' => $data->walletId,
                'evidence' => $data->sourceEvidence,
                'operator_user_id' => $data->operatorUserId,
                'direction' => $direction,
                'reference_number' => (string) $transaction->reference_number,
                'lane' => 'ledger-adjustment',
            ],
        ]);

        $log->save();
    }
}
