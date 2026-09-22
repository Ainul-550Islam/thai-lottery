<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\WalletReservationData;
use App\Enums\AuditAction;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryPurpose;
use App\Enums\RiskLevel;
use App\Enums\WalletHoldType;
use App\Enums\WalletReservationStatus;
use App\Exceptions\WalletReservationException;
use App\Models\AuditLog;
use App\Models\Wallet;
use App\Models\WalletReservation;
use Illuminate\Support\Facades\DB;

/**
 * Atomic reserve / consume / release / expire of wallet money.
 *
 * THE LAYERS, VERY DELIBERATELY
 * - Mechanical moves (balance↔locked) ALWAYS ride WalletHoldService and
 *   WalletService — the wallet's own lock lane owns the money grammar
 *   (available inspection, invariant assert, CHECK-constraint pair).
 *   This service NEVER invents arithmetic.
 * - This lane owns the CONVERSATION: deterministic identity per
 *   (wallet, reference, amount), one row per question asked, a closed
 *   lifecycle map, and the evidence of each act.
 * - Consumption posts the spend through WalletService::debit WITH an
 *   idempotency key derived from the reservation key, inside the SAME
 *   transaction as the hold-unlock — retry-safe end-to-end.
 *
 * CONSERVATION across retries is therefore structural: the same ask
 * arrives twice → the deterministic key answers with the same row; the
 * wallet'S money moved exactly once.
 */
final class WalletReservationService
{
    public function __construct(
        private readonly WalletHoldService $holds,
        private readonly WalletService $wallets,
    ) {
    }

    /* ---------------------------------------------------- reserve --- */

    /**
     * @return array{reservation: WalletReservation, replayed: bool}
     *
     * @throws WalletReservationException
     */
    public function reserve(WalletReservationData $data): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->reserveWithin($data);
        }

        return DB::transaction(fn (): array => $this->reserveWithin($data));
    }

    /**
     * @return array{reservation: WalletReservation, replayed: bool}
     *
     * @throws WalletReservationException
     */
    private function reserveWithin(WalletReservationData $data): array
    {
        // REPLAY first, facts agreed or fork.
        $existing = WalletReservation::query()
            ->lockForUpdate()
            ->where('reservation_key', $data->reservationKey())
            ->first();

        if ($existing instanceof WalletReservation) {
            $factsMatch = (int) $existing->wallet_id === $data->walletId
                && (string) $existing->reference === $data->reference
                && bccomp(self::moneyOf((string) $existing->amount), self::moneyOf($data->amount), 2) === 0;

            if (! $factsMatch) {
                throw WalletReservationException::duplicate($data->reservationKey());
            }

            return ['reservation' => $existing, 'replayed' => true];
        }

        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->lockForUpdate()->find($data->walletId);

        if (! $wallet instanceof Wallet) {
            throw WalletReservationException::notFound('wallet:'.$data->walletId);
        }

        $walletCurrency = $wallet->currency instanceof \BackedEnum
            ? strtoupper((string) $wallet->currency->value)
            : strtoupper((string) $wallet->currency);

        if ($walletCurrency !== $data->currency) {
            throw WalletReservationException::malformed(
                sprintf('the wallet speaks %s, the ask speaks %s', $walletCurrency, $data->currency),
            );
        }

        // INSUFFICIENT: ask the wallet's own available reading. Never
        // compute the answer twice here — the lock lane owns arithmetic.
        $amount = \App\Services\Finance\Money::of($data->amount, \App\Enums\Currency::from($data->currency));

        try {
            $this->holds->hold($wallet, $amount, WalletHoldType::OtherFinancialHold);
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            throw WalletReservationException::insufficient(
                $data->walletId,
                $data->amount,
                $e->availableAmount(),
            );
        } catch (\App\Exceptions\FinancialException $e) {
            throw WalletReservationException::insufficient(
                $data->walletId,
                $data->amount,
                'wallet-unavailable',
            );
        }

        $row = new WalletReservation();
        $row->fill([
            'reservation_key' => $data->reservationKey(),
            'wallet_id' => $data->walletId,
            'reference' => $data->reference,
            'amount' => self::moneyOf($data->amount),
            'currency' => $data->currency,
            'purpose' => $data->purpose,
            'reserved_at' => now(),
            'expires_at' => $data->expiresAt,
            'metadata' => [],
        ]);
        $row->status = WalletReservationStatus::Reserved;
        $row->save();

        $this->recordAudit($row, sprintf('Reserved %s for [%s] (purpose %s)', $data->amount, $data->reference, $data->purpose->value), RiskLevel::Medium);

        return ['reservation' => $row, 'replayed' => false];
    }

    /* ---------------------------------------------------- consume --- */

    /**
     * The reservation's money follows its purpose — unlock + one posted
     * debit, same transaction, idempotency-keyed.
     *
     * @throws WalletReservationException
     */
    public function consume(WalletReservation $reservation): WalletReservation
    {
        return DB::transaction(function () use ($reservation): WalletReservation {
            /** @var WalletReservation|null $locked */
            $locked = WalletReservation::query()->lockForUpdate()->find((int) $reservation->getKey());

            if (! $locked instanceof WalletReservation) {
                throw WalletReservationException::notFound((string) $reservation->reservation_key);
            }

            if ($locked->status === WalletReservationStatus::Consumed) {
                return $locked; // replay
            }

            if (!$locked->status->canTransitionTo(WalletReservationStatus::Consumed)) {
                throw WalletReservationException::invalidTransition(
                    (string) $locked->reservation_key,
                    $locked->status->value,
                    'consume',
                );
            }

            /** @var Wallet|null $wallet */
            $wallet = Wallet::query()->lockForUpdate()->find((int) $locked->wallet_id);

            if (! $wallet instanceof Wallet) {
                throw WalletReservationException::notFound('wallet:'.$locked->wallet_id);
            }

            /** @var LedgerEntryPurpose $purpose */
            $purpose = $locked->purpose instanceof LedgerEntryPurpose
                ? $locked->purpose
                : LedgerEntryPurpose::Reservation;

            $money = \App\Services\Finance\Money::of((string) $locked->amount, \App\Enums\Currency::from(strtoupper((string) ($walletCurrency ?? $locked->currency))));

            // Unlock (mechanics) then post the spend (truth) — one
            // transaction, idempotent key per reservation.
            $this->holds->consume($wallet, $money);

            $this->wallets->debit(
                $wallet,
                $money,
                self::transactionTypeFor($purpose),
                idempotencyKey: sprintf('wallet-resv-cons:%s', substr((string) $locked->reservation_key, 0, 48)),
                options: ['description' => sprintf('Consumed reservation %s', $locked->reference)],
            );

            $locked->status = WalletReservationStatus::Consumed;
            $locked->consumed_at = now();
            $locked->save();

            $this->recordAudit($locked, sprintf('Consumed: %s spent toward %s', (string) $locked->amount, $purpose->value), RiskLevel::High);

            return $locked;
        });
    }

    /* ---------------------------------------------------- release --- */

    /**
     * Give the money back — strict, exactly-once.
     *
     * @throws WalletReservationException
     */
    public function release(WalletReservation $reservation): WalletReservation
    {
        return DB::transaction(function () use ($reservation): WalletReservation {
            /** @var WalletReservation|null $locked */
            $locked = WalletReservation::query()->lockForUpdate()->find((int) $reservation->getKey());

            if (! $locked instanceof WalletReservation) {
                throw WalletReservationException::notFound((string) $reservation->reservation_key);
            }

            if ($locked->status === WalletReservationStatus::Released) {
                return $locked;
            }

            if (!$locked->status->canTransitionTo(WalletReservationStatus::Released)) {
                throw WalletReservationException::invalidTransition(
                    (string) $locked->reservation_key,
                    $locked->status->value,
                    'release',
                );
            }

            $wallet = Wallet::query()->lockForUpdate()->find((int) $locked->wallet_id);

            if (! $wallet instanceof Wallet) {
                throw WalletReservationException::notFound('wallet:'.$locked->wallet_id);
            }

            $this->holds->release(
                $wallet,
                \App\Services\Finance\Money::of((string) $locked->amount, \App\Enums\Currency::from(strtoupper((string) ($walletCurrency ?? $locked->currency)))),
            );

            $locked->status = WalletReservationStatus::Released;
            $locked->released_at = now();
            $locked->save();

            $this->recordAudit($locked, sprintf('Released %s back to available', (string) $locked->amount), RiskLevel::Medium);

            return $locked;
        });
    }

    /* ----------------------------------------------------- expire --- */

    /**
     * Wall-clock expiry: only Reserved rows whose horizon passed (and
     * never somebody's already-consumed/released partnership). The
     * mechanics are a strict release; the status is the pronouncement.
     *
     * @throws WalletReservationException
     */
    public function expire(WalletReservation $reservation): WalletReservation
    {
        return DB::transaction(function () use ($reservation): WalletReservation {
            /** @var WalletReservation|null $locked */
            $locked = WalletReservation::query()->lockForUpdate()->find((int) $reservation->getKey());

            if (! $locked instanceof WalletReservation) {
                throw WalletReservationException::notFound((string) $reservation->reservation_key);
            }

            if ($locked->status === WalletReservationStatus::Expired) {
                return $locked;
            }

            if (!$locked->status->canTransitionTo(WalletReservationStatus::Expired)) {
                throw WalletReservationException::invalidTransition(
                    (string) $locked->reservation_key,
                    $locked->status->value,
                    'expire',
                );
            }

            // Pending rows hold no money; expire is a pure status stamp.
            // Reserved rows ARE holding — release the mechanics first.
            if ($locked->status === WalletReservationStatus::Reserved) {
                $wallet = Wallet::query()->lockForUpdate()->find((int) $locked->wallet_id);

                if (! $wallet instanceof Wallet) {
                    throw WalletReservationException::notFound('wallet:'.$locked->wallet_id);
                }

                $this->holds->release(
                    $wallet,
                    \App\Services\Finance\Money::of((string) $locked->amount, \App\Enums\Currency::from(strtoupper((string) ($walletCurrency ?? $locked->currency)))),
                );
            }

            $locked->status = WalletReservationStatus::Expired;
            $locked->expired_at = now();
            $locked->save();

            $this->recordAudit($locked, sprintf('Horizon passed; %s expired back to the wallet', (string) $locked->amount), RiskLevel::Medium);

            return $locked;
        });
    }

    /* --------------------------------------------------- internals --- */

    /**
     * The spend the reservation was staged for, mapped to the wallet
     * lane's own transaction vocabulary (never invented paths).
     */
    private static function transactionTypeFor(LedgerEntryPurpose $purpose): FinancialTransactionType
    {
        return match ($purpose) {
            LedgerEntryPurpose::BetStake => FinancialTransactionType::BetDebit,
            LedgerEntryPurpose::PrizePayout => FinancialTransactionType::Payout,
            LedgerEntryPurpose::Fee, LedgerEntryPurpose::Tax => FinancialTransactionType::Fee,
            LedgerEntryPurpose::Reversal => FinancialTransactionType::Reversal,
            LedgerEntryPurpose::Release, LedgerEntryPurpose::Adjustment => FinancialTransactionType::Adjustment,
            default => FinancialTransactionType::Withdrawal,
        };
    }

    public static function moneyOf(string $amount): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($amount, '0', 2);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function recordAudit(WalletReservation $reservation, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => WalletReservation::class,
            'auditable_id' => (int) $reservation->getKey(),
            'description' => sprintf('%s (reservation %s...)', $description, substr((string) $reservation->reservation_key, 0, 12)),
            'metadata' => [
                'reservation_key' => (string) $reservation->reservation_key,
                'wallet_id' => (int) $reservation->wallet_id,
                'reference' => (string) $reservation->reference,
                'purpose' => (string) ($reservation->purpose instanceof \BackedEnum ? $reservation->purpose->value : (string) $reservation->purpose),
                'lane' => 'wallet-reservation',
            ],
        ]);

        $log->save();
    }
}
