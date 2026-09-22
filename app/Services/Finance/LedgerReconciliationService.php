<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\LedgerReconciliationData;
use App\Enums\AuditAction;
use App\Enums\FinancialHoldStatus;
use App\Enums\LedgerReconciliationStatus;
use App\Enums\RiskLevel;
use App\Enums\WalletReservationStatus;
use App\Events\FinancialLedgerReconciled;
use App\Exceptions\LedgerReconciliationException;
use App\Models\AuditLog;
use App\Models\FinancialHold;
use App\Models\LedgerEntry;
use App\Models\LedgerReconciliation;
use App\Models\Wallet;
use App\Models\WalletReservation;
use Illuminate\Support\Facades\DB;

/**
 * Wallet-liability reconciliation.
 *
 * THE LANE
 *   expected liability     = wallet.balance (this schema keeps the
 *                            locked total INSIDE balance — available
 *                            spending is balance minus locked_balance —
 *                            so liability is the balance column alone)
 *   ledger lane aggregate  = SUM(credit) - SUM(debit) over entries
 *                            against the PLAYER LIABILITY account,
 *                            scoped to this wallet, filtered to the
 *                            wallet's own currency
 *   reservation effect     = sums of wallet reservations/financial
 *                            holds still occupying the wallet
 *                            (Reserved reservations + active/reviewed
 *                            holds); the wallet's locked balance must
 *                            cover it — if it does not, money is
 *                            locked by something the paper cannot see.
 *
 * THE LAWS
 *   1. EXACTNESS — bcmath, scale 2, everywhere. Never floats.
 *   2. CONVERSATION — one LIVE row per wallet (deterministic key): an
 *      unresolved row is refreshed (the fingerprint rotates with the
 *      facts). Resolving seals it; the next run opens a new row.
 *   3. FAIL-CLOSED EVIDENCE — drift is pronounced on the row and
 *      shipped as evidence lines; callers that need THE TRUTH NOW use
 *      assertBalanced() which refuses by name.
 *   4. FINGERPRINT — sha-256 over the exact ledger/pocket facts;
 *      replay (same fingerprint, same wallet) serves the recorded row;
 *      same conversation space with different facts forges ahead —
 *      the paper must reflect the truth NOW.
 */
final class LedgerReconciliationService
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {
    }

    /* --------------------------------------------------- run one --- */

    /**
     * Reconcile a single wallet. Returns the (refreshed or newly
     * pronounced) conversation row; never throws for drift — drift is
     * EVIDENCE, not an exception. Callers that need a refusal use
     * assertBalanced() on the returned row.
     */
    public function reconcileWallet(int $walletId): LedgerReconciliation
    {
        return DB::transaction(function () use ($walletId): LedgerReconciliation {
            /** @var Wallet|null $wallet */
            $wallet = Wallet::query()->lockForUpdate()->find($walletId);

            if (! $wallet instanceof Wallet) {
                throw LedgerReconciliationException::notFound('wallet:'.$walletId);
            }

            return $this->pronounce($wallet);
        });
    }

    /* ---------------------------------------------------- run all --- */

    /**
     * Sweep one page of wallets (the job pages by cursor so a hiccup
     * mid-sweep resumes without doubling work).
     *
     * @return array{reconciled: int, drift: int, next_after_id: int}
     */
    public function sweep(int $afterWalletId = 0, int $limit = 100): array
    {
        $reconciled = 0;
        $drift = 0;
        $lastId = $afterWalletId;

        Wallet::query()
            ->where('id', '>', $afterWalletId)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get(['id'])
            ->each(function (Wallet $wallet) use (&$reconciled, &$drift, &$lastId): void {
                $row = $this->reconcileWallet((int) $wallet->id);
                $reconciled++;
                $lastId = (int) $wallet->id;

                if ($row->status === LedgerReconciliationStatus::DriftDetected) {
                    $drift++;
                }
            });

        return ['reconciled' => $reconciled, 'drift' => $drift, 'next_after_id' => $lastId];
    }

    /* ----------------------------------------------- fail-closed ---- */

    /**
     * THE TRUTH NOW: refuse (by name) when the wallet's live paper
     * doesn't balance. For batch-9-pattern consumers (settlement gates,
     * disbursement windows) that must not proceed on drifted paper.
     *
     * @throws LedgerReconciliationException
     */
    public function assertBalanced(int $walletId): LedgerReconciliation
    {
        $row = $this->reconcileWallet($walletId);

        if ($row->status === LedgerReconciliationStatus::DriftDetected) {
            /** @var Wallet|null $wallet */
            $wallet = Wallet::query()->find($walletId);

            $lines = is_array($row->drift_lines) ? $row->drift_lines : [];

            if (in_array('missing-ledger', $lines, true)) {
                throw LedgerReconciliationException::missingLedger($walletId);
            }

            // balanceMismatch(walletId, expected, actual) — pocket
            // truth first, paper second (the lane documented it so).
            throw LedgerReconciliationException::balanceMismatch(
                $walletId,
                (string) $row->expected_balance,
                (string) $row->ledger_aggregate,
            );
        }

        return $row;
    }

    /* -------------------------------------------------- resolve ----- */

    /**
     * Pronounce a drifted conversation row resolved — with a full-
     * sentence note, evidence of a pair of eyes.
     *
     * @throws LedgerReconciliationException
     */
    public function resolve(LedgerReconciliation $row, int $operatorUserId, string $note): LedgerReconciliation
    {
        return DB::transaction(function () use ($row, $operatorUserId, $note): LedgerReconciliation {
            /** @var LedgerReconciliation|null $locked */
            $locked = LedgerReconciliation::query()->lockForUpdate()->find((int) $row->getKey());

            if (! $locked instanceof LedgerReconciliation) {
                throw LedgerReconciliationException::notFound((string) $row->reconciliation_key);
            }

            if ($locked->status === LedgerReconciliationStatus::Resolved) {
                return $locked; // replay
            }

            if (!$locked->status->canTransitionTo(LedgerReconciliationStatus::Resolved)) {
                throw LedgerReconciliationException::malformed(
                    sprintf('a %s reconciliation can not be resolved', $locked->status->value),
                );
            }

            $note = trim($note);

            if (mb_strlen($note) < 8) {
                throw LedgerReconciliationException::malformed('a resolution note must be a full sentence (8+ characters)');
            }

            $locked->status = LedgerReconciliationStatus::Resolved;
            $locked->resolved_note = \Illuminate\Support\Str::limit($note, 255, '');
            $locked->resolved_at = now();
            $locked->save();

            $this->recordAudit($locked, sprintf('Resolved by #%d (%s)', $operatorUserId, $locked->resolved_note));

            return $locked;
        });
    }

    /* ------------------------------------------------ internals ---- */

    /**
     * Pronounce the wallet's reconciliation row — creating or
     * refreshing the conversation.
     *
     * @throws LedgerReconciliationException
     */
    private function pronounce(Wallet $wallet): LedgerReconciliation
    {
        $walletId = (int) $wallet->id;

        $currency = $wallet->currency instanceof \BackedEnum
            ? strtoupper((string) $wallet->currency->value)
            : strtoupper((string) $wallet->currency);

        // TRUTH AT THE ROWS: expected liability comes from the wallet
        // as we read it under the row lock (never the caller's memory).
        $balance = \App\Services\Finance\WalletReservationService::moneyOf((string) $wallet->balance);
        $locked = \App\Services\Finance\WalletReservationService::moneyOf((string) $wallet->locked_balance);

        // Liability is the balance column ALONE: locked money is a
        // subset stored inside it (available spending subtracts it).
        $expected = $balance;

        // The LIABILITY LANE: entries against the PLAYER LIABILITY
        // account scoped to this wallet. SUM(credit) - SUM(debit),
        // exact bcmath arithmetic row-by-row (24,2 columns guarantee
        // exact decimal strings).
        $liabilityAccountId = \App\Models\LedgerAccount::query()
            ->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)
            ->value('id');

        $hasLanePostings = false;
        $aggregate = '0.00';

        if ($liabilityAccountId !== null) {
            $entries = LedgerEntry::query()
                ->where('ledger_account_id', (int) $liabilityAccountId)
                ->where('wallet_id', $walletId)
                ->where('currency', $currency)
                ->get(['type', 'amount']);

            foreach ($entries as $entry) {
                $hasLanePostings = true;
                $amount = \App\Services\Finance\WalletReservationService::moneyOf((string) $entry->amount);
                $type = $entry->type instanceof \BackedEnum ? (string) $entry->type->value : (string) $entry->type;

                $aggregate = $type === 'credit'
                    ? bcadd($aggregate, $amount, 2)
                    : bcsub($aggregate, $amount, 2);
            }
        }

        // RESERVATION EFFECT: money NAMED against the wallet which the
        // pocket must still hold.
        $reservationEffect = bcadd(
            $this->occupyingReservationSum($walletId, $currency),
            $this->occupyingHoldSum($walletId, $currency),
            2,
        );

        $data = LedgerReconciliationData::fromInput(
            walletId: $walletId,
            currency: $currency,
            expectedBalance: $expected,
            ledgerAggregate: $aggregate,
            reservationEffect: $reservationEffect,
            fingerprint: LedgerReconciliationData::fingerprintOf(
                $walletId,
                $currency,
                $expected,
                $aggregate,
                $reservationEffect,
            ),
        );

        // --- DRIFT LINES: readable, permanent, evidence-grade -------
        $driftLines = [];

        if (bccomp($expected, $aggregate, 2) !== 0) {
            $driftLines[] = sprintf(
                'expected-vs-lane: pocket owes %s, paper says %s (divergence %s)',
                $expected,
                $aggregate,
                bcsub($expected, $aggregate, 2),
            );
        }

        if (bccomp($locked, $reservationEffect, 2) < 0) {
            $driftLines[] = sprintf(
                'reservation-underflow: %s named against the wallet but only %s locked',
                $reservationEffect,
                $locked,
            );
        }

        if (! $hasLanePostings && bccomp($expected, '0', 2) > 0) {
            $driftLines[] = 'missing-ledger';
        }

        $status = $driftLines === []
            ? LedgerReconciliationStatus::Matched
            : LedgerReconciliationStatus::DriftDetected;

        // CONVERSATION: one LIVE row per wallet. The key family is
        // [base, base:2, base:3, ...] — the plain base for generation 1,
        // suffixed for every generation after a Resolved sealing (the
        // column is UNIQUE, so a sealed conversation can never be
        // reopened and a new generation gets its own suffix).
        $baseKey = $data->reconciliationKey();

        /** @var LedgerReconciliation|null $row */
        $row = LedgerReconciliation::query()
            ->lockForUpdate()
            ->where('reconciliation_key', 'LIKE', $baseKey.'%')
            ->where('status', '!=', LedgerReconciliationStatus::Resolved->value)
            ->orderByDesc('id')
            ->first();

        if ($row instanceof LedgerReconciliation) {
            // Replay (identical fingerprint + identity, both) serves the
            // recorded row as-is; otherwise refresh the paper (the
            // fingerprint rotates with the facts — batch-10 rule).
            if ((string) $row->fingerprint === $data->fingerprint && (int) $row->wallet_id === $walletId) {
                return $row;
            }

            $row->fill([
                'wallet_id' => $walletId,
                'currency' => $currency,
                'expected_balance' => $expected,
                'ledger_aggregate' => $aggregate,
                'reservation_effect' => $reservationEffect,
                'fingerprint' => $data->fingerprint,
                'drift_lines' => $driftLines,
            ]);
            $row->status = $status;
            $row->save();

            $this->recordAudit($row, sprintf('Refreshed (%s, %d drift line(s))', $status->value, count($driftLines)));
            event(new FinancialLedgerReconciled($row, $status, $driftLines));

            return $row;
        }

        $generations = (int) LedgerReconciliation::query()
            ->where('reconciliation_key', 'LIKE', $baseKey.'%')
            ->count();

        $row = new LedgerReconciliation();
        $row->fill([
            'reconciliation_key' => $generations === 0 ? $baseKey : sprintf('%s:%d', $baseKey, $generations + 1),
            'wallet_id' => $walletId,
            'currency' => $currency,
            'scope' => 'wallet-liability',
            'expected_balance' => $expected,
            'ledger_aggregate' => $aggregate,
            'reservation_effect' => $reservationEffect,
            'fingerprint' => $data->fingerprint,
            'drift_lines' => $driftLines,
        ]);
        $row->status = $status;
        $row->save();

        $this->recordAudit($row, sprintf('Pronounced (%s, %d drift line(s))', $status->value, count($driftLines)));
        event(new FinancialLedgerReconciled($row, $status, $driftLines));

        return $row;
    }

    private function occupyingReservationSum(int $walletId, string $currency): string
    {
        $sum = '0.00';

        WalletReservation::query()
            ->where('wallet_id', $walletId)
            ->where('currency', $currency)
            ->where('status', WalletReservationStatus::Reserved->value)
            ->get(['amount'])
            ->each(function (WalletReservation $reservation) use (&$sum): void {
                $sum = bcadd($sum, \App\Services\Finance\WalletReservationService::moneyOf((string) $reservation->amount), 2);
            });

        return $sum;
    }

    private function occupyingHoldSum(int $walletId, string $currency): string
    {
        $sum = '0.00';

        FinancialHold::query()
            ->where('wallet_id', $walletId)
            ->where('currency', $currency)
            ->whereIn('status', [FinancialHoldStatus::Active->value, FinancialHoldStatus::Reviewed->value])
            ->get(['amount'])
            ->each(function (FinancialHold $hold) use (&$sum): void {
                $sum = bcadd($sum, \App\Services\Finance\WalletReservationService::moneyOf((string) $hold->amount), 2);
            });

        return $sum;
    }

    private function recordAudit(LedgerReconciliation $row, string $description): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $row->status === LedgerReconciliationStatus::DriftDetected ? RiskLevel::High : RiskLevel::Medium,
            'auditable_type' => LedgerReconciliation::class,
            'auditable_id' => (int) $row->getKey(),
            'description' => sprintf('%s (reconciliation wallet #%d)', $description, (int) $row->wallet_id),
            'metadata' => [
                'reconciliation_key' => (string) $row->reconciliation_key,
                'wallet_id' => (int) $row->wallet_id,
                'expected_balance' => (string) $row->expected_balance,
                'ledger_aggregate' => (string) $row->ledger_aggregate,
                'reservation_effect' => (string) $row->reservation_effect,
                'fingerprint' => (string) $row->fingerprint,
                'lane' => 'ledger-reconciliation',
            ],
        ]);

        $log->save();
    }
}
