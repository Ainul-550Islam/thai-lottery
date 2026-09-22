<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\FinancialHoldData;
use App\Enums\AuditAction;
use App\Enums\FinancialHoldStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Enums\WalletHoldType;
use App\Exceptions\FinancialHoldException;
use App\Models\AuditLog;
use App\Models\FinancialHold;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Compliance/settlement financial holds.
 *
 * THE INVARIANTS
 *   1. TRUTH AT THE ROW — amount/currency must equal what the wallet
 *      row and the hold row themselves say (never the caller's memory).
 *   2. MECHANICS THROUGH THE WALLET'S LANE — holding gives back money
 *      through WalletHoldService ONLY; conversion debits the wallet
 *      through WalletService (its own ledger posting), never raw.
 *   3. HORIZON — an Active hold past its horizon is routed to review
 *      first; only a reviewed hold may EXPIRE (with evidence, releasing
 *      its funds cleanly). Nobody's money is silently held forever,
 *      and nobody's money silently moves.
 *   4. EVIDENCE — release/convert/expiry stamp the act + reason on the
 *      row (the evidence column).
 */
final class FinancialHoldService
{
    /**
     * Failed-placement horizon when none is given on the ask: reviewed
     * within hours, always.
     */
    public const DEFAULT_HORIZON_HOURS = 72;

    public function __construct(
        private readonly WalletHoldService $holds,
        private readonly WalletService $wallets,
    ) {
    }

    /* ------------------------------------------------------ place --- */

    /**
     * @return array{hold: FinancialHold, replayed: bool}
     *
     * @throws FinancialHoldException
     */
    public function place(FinancialHoldData $data): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->placeWithin($data);
        }

        return DB::transaction(fn (): array => $this->placeWithin($data));
    }

    /**
     * @return array{hold: FinancialHold, replayed: bool}
     *
     * @throws FinancialHoldException
     */
    private function placeWithin(FinancialHoldData $data): array
    {
        $existing = FinancialHold::query()
            ->lockForUpdate()
            ->where('hold_key', $data->holdKey())
            ->first();

        if ($existing instanceof FinancialHold) {
            $factsMatch = (int) $existing->wallet_id === $data->walletId
                && (string) $existing->source_reference === $data->sourceReference
                && bccomp(self::moneyOf((string) $existing->amount), self::moneyOf($data->amount), 2) === 0;

            if (! $factsMatch) {
                throw FinancialHoldException::duplicate($data->holdKey());
            }

            return ['hold' => $existing, 'replayed' => true];
        }

        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->lockForUpdate()->find($data->walletId);

        if (! $wallet instanceof Wallet) {
            throw FinancialHoldException::notFound('wallet:'.$data->walletId);
        }

        $money = \App\Services\Finance\Money::of($data->amount, \App\Enums\Currency::from($data->currency));

        try {
            $this->holds->hold($wallet, $money, WalletHoldType::OtherFinancialHold);
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            throw FinancialHoldException::amountMismatch($data->holdKey(), $e->availableAmount(), $data->amount);
        } catch (\App\Exceptions\FinancialException $e) {
            throw FinancialHoldException::amountMismatch($data->holdKey(), 'wallet-unavailable', $data->amount);
        }

        $row = new FinancialHold();
        $row->fill([
            'hold_key' => $data->holdKey(),
            'wallet_id' => $data->walletId,
            'amount' => self::moneyOf($data->amount),
            'currency' => $data->currency,
            'reason' => $data->reason,
            'source_reference' => $data->sourceReference,
            'expires_at' => $data->expiresAt ?? now()->addHours(self::DEFAULT_HORIZON_HOURS)->toIso8601String(),
            'evidence' => ['placed' => now()->toIso8601String(), 'reason' => $data->reason],
            'metadata' => [],
        ]);
        $row->status = FinancialHoldStatus::Active;
        $row->save();

        $this->recordAudit($row, sprintf('Placed hold of %s for [%s]', $data->amount, $data->sourceReference), RiskLevel::High);

        return ['hold' => $row, 'replayed' => false];
    }

    /* ----------------------------------------------------- review --- */

    /**
     * A human inspected the source evidence — pronounce.
     *
     * @throws FinancialHoldException
     */
    public function review(FinancialHold $hold, int $operatorUserId, string $note): FinancialHold
    {
        return DB::transaction(function () use ($hold, $operatorUserId, $note): FinancialHold {
            /** @var FinancialHold|null $locked */
            $locked = FinancialHold::query()->lockForUpdate()->find((int) $hold->getKey());

            if (! $locked instanceof FinancialHold) {
                throw FinancialHoldException::notFound((string) $hold->hold_key);
            }

            if ($locked->status === FinancialHoldStatus::Reviewed) {
                return $locked; // replay
            }

            if ($locked->status === FinancialHoldStatus::Expired) {
                throw FinancialHoldException::expired((string) $locked->hold_key);
            }

            if (!$locked->status->canTransitionTo(FinancialHoldStatus::Reviewed)) {
                throw FinancialHoldException::invalidRelease((string) $locked->hold_key, $locked->status->value);
            }

            $locked->status = FinancialHoldStatus::Reviewed;
            $locked->reviewed_at = now();

            $evidence = is_array($locked->evidence) ? $locked->evidence : [];
            $evidence['reviewed'] = ['at' => now()->toIso8601String(), 'by' => $operatorUserId, 'note' => \Illuminate\Support\Str::limit(trim($note), 255, '')];
            $locked->evidence = $evidence;
            $locked->save();

            $this->recordAudit($locked, sprintf('Reviewed by #%d (%s)', $operatorUserId, $evidence['reviewed']['note']), RiskLevel::High);

            return $locked;
        });
    }

    /* ---------------------------------------------------- release --- */

    /**
     * Give the money back, innocent lane — strict, exactly-once,
     * forever pronounced.
     *
     * @throws FinancialHoldException
     */
    public function release(FinancialHold $hold, string $reason): FinancialHold
    {
        return DB::transaction(function () use ($hold, $reason): FinancialHold {
            /** @var FinancialHold|null $locked */
            $locked = FinancialHold::query()->lockForUpdate()->find((int) $hold->getKey());

            if (! $locked instanceof FinancialHold) {
                throw FinancialHoldException::notFound((string) $hold->hold_key);
            }

            if ($locked->status === FinancialHoldStatus::Released) {
                return $locked;
            }

            if ($locked->status === FinancialHoldStatus::Expired) {
                throw FinancialHoldException::invalidRelease((string) $locked->hold_key, FinancialHoldStatus::Expired->value);
            }

            if (!$locked->status->canTransitionTo(FinancialHoldStatus::Released)) {
                throw FinancialHoldException::invalidRelease((string) $locked->hold_key, $locked->status->value);
            }

            /** @var Wallet|null $wallet */
            $wallet = Wallet::query()->lockForUpdate()->find((int) $locked->wallet_id);

            if (! $wallet instanceof Wallet) {
                throw FinancialHoldException::notFound('wallet:'.$locked->wallet_id);
            }

            $this->holds->release(
                $wallet,
                \App\Services\Finance\Money::of((string) $locked->amount, \App\Enums\Currency::from(strtoupper((string) $locked->currency))),
            );

            $evidence = is_array($locked->evidence) ? $locked->evidence : [];
            $evidence['released'] = ['at' => now()->toIso8601String(), 'reason' => \Illuminate\Support\Str::limit(trim($reason), 255, '')];

            $locked->status = FinancialHoldStatus::Released;
            $locked->released_at = now();
            $locked->evidence = $evidence;
            $locked->save();

            $this->recordAudit($locked, sprintf('Released (%s)', $evidence['released']['reason']), RiskLevel::High);

            return $locked;
        });
    }

    /* ---------------------------------------------------- convert --- */

    /**
     * Convert the hold into the FORMAL flow: unlock the hold, debit the
     * wallet through the wallet's own adjustment lane (its own ledger
     * posting and evidence), stamp Converted. Reviewed holds ONLY — a
     * conversion of an unreviewed hold is a crime of vocabulary.
     *
     * @return array{hold: FinancialHold, conversion_reference: string}
     *
     * @throws FinancialHoldException
     */
    public function convert(FinancialHold $hold, int $operatorUserId, string $evidence): array
    {
        return DB::transaction(function () use ($hold, $operatorUserId, $evidence): array {
            /** @var FinancialHold|null $locked */
            $locked = FinancialHold::query()->lockForUpdate()->find((int) $hold->getKey());

            if (! $locked instanceof FinancialHold) {
                throw FinancialHoldException::notFound((string) $hold->hold_key);
            }

            if ($locked->status === FinancialHoldStatus::Converted) {
                return [
                    'hold' => $locked,
                    'conversion_reference' => (string) (data_get($locked->evidence, 'converted.reference', '(lost)')),
                ];
            }

            if (!$locked->status->canTransitionTo(FinancialHoldStatus::Converted)) {
                throw FinancialHoldException::invalidRelease((string) $locked->hold_key, $locked->status->value);
            }

            /** @var Wallet|null $wallet */
            $wallet = Wallet::query()->lockForUpdate()->find((int) $locked->wallet_id);

            if (! $wallet instanceof Wallet) {
                throw FinancialHoldException::notFound('wallet:'.$locked->wallet_id);
            }

            // Unlock the hold; then the FORMAL flow: debit through the
            // wallet service itself, so the ledger records the movement
            // with the hold's own deterministic idempotency key.
            $this->holds->consume(
                $wallet,
                \App\Services\Finance\Money::of((string) $locked->amount, \App\Enums\Currency::from(strtoupper((string) $locked->currency))),
            );

            $transaction = $this->wallets->debit(
                $wallet,
                \App\Services\Finance\Money::of((string) $locked->amount, \App\Enums\Currency::from(strtoupper((string) $locked->currency))),
                FinancialTransactionType::Adjustment,
                idempotencyKey: sprintf('fin-hold-conv:%s', substr((string) $locked->hold_key, 0, 44)),
                options: [
                    'description' => sprintf('Hold converted: [%s] (by #%d)', $locked->source_reference, $operatorUserId),
                    'metadata' => ['evidence' => $evidence, 'source' => $locked->source_reference],
                ],
            );

            $ev = is_array($locked->evidence) ? $locked->evidence : [];
            $ev['converted'] = [
                'at' => now()->toIso8601String(),
                'by' => $operatorUserId,
                'evidence' => \Illuminate\Support\Str::limit(trim($evidence), 255, ''),
                'reference' => (string) $transaction->reference_number,
            ];

            $locked->status = FinancialHoldStatus::Converted;
            $locked->converted_at = now();
            $locked->evidence = $ev;
            $locked->save();

            $this->recordAudit($locked, sprintf('Converted → %s', $ev['converted']['reference']), RiskLevel::Critical);

            return ['hold' => $locked, 'conversion_reference' => $ev['converted']['reference']];
        });
    }

    /* ----------------------------------------------------- expire --- */

    /**
     * Pronounce a hold expired — the dynamite that prevents a desk from
     * becoming a bank of the last resort. Funds release mechanically;
     * the status is the evidence.
     *
     * @throws FinancialHoldException
     */
    public function expire(FinancialHold $hold): FinancialHold
    {
        return DB::transaction(function () use ($hold): FinancialHold {
            /** @var FinancialHold|null $locked */
            $locked = FinancialHold::query()->lockForUpdate()->find((int) $hold->getKey());

            if (! $locked instanceof FinancialHold) {
                throw FinancialHoldException::notFound((string) $hold->hold_key);
            }

            if ($locked->status === FinancialHoldStatus::Expired) {
                return $locked;
            }

            if (!$locked->status->canTransitionTo(FinancialHoldStatus::Expired)) {
                throw FinancialHoldException::invalidRelease((string) $locked->hold_key, $locked->status->value);
            }

            // Holds that actually OCCUPY money on the wallet must give
            // it back on expiry (an Active or Reviewed hold occupies).
            if ($locked->status->occupiesWallet()) {
                /** @var Wallet|null $wallet */
                $wallet = Wallet::query()->lockForUpdate()->find((int) $locked->wallet_id);

                if (! $wallet instanceof Wallet) {
                    throw FinancialHoldException::notFound('wallet:'.$locked->wallet_id);
                }

                $this->holds->release(
                    $wallet,
                    \App\Services\Finance\Money::of((string) $locked->amount, \App\Enums\Currency::from(strtoupper((string) $locked->currency))),
                );
            }

            $ev = is_array($locked->evidence) ? $locked->evidence : [];
            $ev['expired'] = ['at' => now()->toIso8601String(), 'horizon' => $locked->expires_at?->toIso8601String()];

            $locked->status = FinancialHoldStatus::Expired;
            $locked->expired_at = now();
            $locked->evidence = $ev;
            $locked->save();

            $this->recordAudit($locked, sprintf('Horizon passed (horizon %s)', (string) $ev['expired']['horizon']), RiskLevel::High);

            return $locked;
        });
    }

    /* ------------------------------------------------ internals ---- */

    public static function moneyOf(string $amount): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($amount, '0', 2);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function recordAudit(FinancialHold $hold, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => FinancialHold::class,
            'auditable_id' => (int) $hold->getKey(),
            'description' => sprintf('%s (hold %s...)', $description, substr((string) $hold->hold_key, 0, 12)),
            'metadata' => [
                'hold_key' => (string) $hold->hold_key,
                'wallet_id' => (int) $hold->wallet_id,
                'source_reference' => (string) $hold->source_reference,
                'lane' => 'financial-hold',
            ],
        ]);

        $log->save();
    }
}
