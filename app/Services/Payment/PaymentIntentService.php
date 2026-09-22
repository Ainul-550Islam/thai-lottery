<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentIntentData;
use App\Enums\AuditAction;
use App\Enums\PaymentDirection;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PaymentIntentException;
use App\Exceptions\PaymentProviderException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Create/retrieve/cancel payment intents with deterministic
 * idempotency and SERVER-DERIVED wallet binding.
 *
 *   1. IDENTITY SERVER-SIDE — the controller passes the authenticated
 *      user; the service locks the wallet row and refuses any ask whose
 *      wallet isn't held by the claimed user. Client claims never bind
 *      money.
 *   2. REPLAY IS FREE, FORKS ARE CRIED — the same idempotency key with
 *      the same facts returns the recorded intent; with different
 *      facts, a loud DUPLICATE.
 *   3. BOUNDS — the method offer (registry) is the only authority on
 *      what money may move, and it is consulted on every create.
 *   4. ONE WALLET, FOREVER — the wallet bound at creation may never be
 *      rebound; status transitions move strictly through the enum map
 *      with the act timestamps stamped.
 */
final class PaymentIntentService
{
    /**
     * Default intent horizon: an intent that isn't paid within this
     * window lapses (unless provider evidence says otherwise — the
     * expiry job only collapses ACTUALLY-evidence-free intents).
     */
    public const DEFAULT_HORIZON_MINUTES = 15;

    public function __construct(
        private readonly PaymentProviderRegistry $registry,
    ) {
    }

    /* ------------------------------------------------------ create --- */

    /**
     * @return array{intent: PaymentIntent, replayed: bool}
     *
     * @throws PaymentIntentException|PaymentProviderException
     */
    public function create(PaymentIntentData $data): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->createWithin($data);
        }

        return DB::transaction(fn (): array => $this->createWithin($data));
    }

    /**
     * @return array{intent: PaymentIntent, replayed: bool}
     *
     * @throws PaymentIntentException|PaymentProviderException
     */
    private function createWithin(PaymentIntentData $data): array
    {
        $existing = PaymentIntent::query()
            ->lockForUpdate()
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();

        if ($existing instanceof PaymentIntent) {
            $factsMatch = (int) $existing->user_id === $data->userId
                && (int) $existing->wallet_id === $data->walletId
                && $existing->direction === $data->direction
                && bccomp(self::moneyOf((string) $existing->amount), $data->amount, 2) === 0
                && (string) $existing->currency === $data->currency
                && (string) $existing->method_code === $data->methodCode;

            if (! $factsMatch) {
                throw PaymentIntentException::duplicate($data->idempotencyKey);
            }

            return ['intent' => $existing, 'replayed' => true];
        }

        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->lockForUpdate()->find($data->walletId);

        if (! $wallet instanceof Wallet) {
            throw PaymentIntentException::notFound('wallet:'.$data->walletId);
        }

        if ((int) $wallet->user_id !== $data->userId) {
            throw PaymentIntentException::walletMismatch($data->userId, $data->walletId);
        }

        $walletCurrency = $wallet->currency instanceof \BackedEnum
            ? strtoupper((string) $wallet->currency->value)
            : strtoupper((string) $wallet->currency);

        if ($walletCurrency !== $data->currency) {
            throw PaymentIntentException::currencyMismatch($data->intentKey(), $walletCurrency, $data->currency);
        }

        // The registry speaks truth on offers and bounds.
        $offer = $this->registry->resolveOffer($this->providerFor($data->methodCode), $data->methodCode, $data->currency);

        if (! PaymentProviderRegistry::amountWithin($offer, $data->amount)) {
            throw PaymentIntentException::amountMismatch(
                $data->intentKey(),
                sprintf('bounds [%s, %s]', (string) $offer->min_amount, (string) $offer->max_amount),
                $data->amount,
            );
        }

        $row = new PaymentIntent();
        $row->fill([
            'intent_key' => $data->intentKey(),
            'user_id' => $data->userId,
            'wallet_id' => $data->walletId,
            'direction' => $data->direction,
            'method_code' => $data->methodCode,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'idempotency_key' => $data->idempotencyKey,
            'expires_at' => $data->expiresAt ?? now()->addMinutes(self::DEFAULT_HORIZON_MINUTES),
            'metadata' => [],
        ]);
        $row->status = PaymentTransactionStatus::Initiated;
        $row->save();

        $this->recordAudit($row, sprintf('Created %s intent of %s %s via [%s]', $data->direction->value, $data->amount, $data->currency, $data->methodCode), RiskLevel::Medium);

        return ['intent' => $row, 'replayed' => false];
    }

    /* ---------------------------------------------------- retrieve --- */

    /**
     * One intent, scoped to the caller — a user never reads another
     * user's pay paper.
     *
     * @throws PaymentIntentException
     */
    public function retrieve(string $intentKey, int $userId): PaymentIntent
    {
        /** @var PaymentIntent|null $intent */
        $intent = PaymentIntent::query()->where('intent_key', trim($intentKey))->first();

        if (! $intent instanceof PaymentIntent) {
            throw PaymentIntentException::notFound($intentKey);
        }

        if ((int) $intent->user_id !== $userId) {
            throw PaymentIntentException::walletMismatch($userId, (int) $intent->wallet_id);
        }

        return $intent;
    }

    /* ------------------------------------------------------ cancel --- */

    /**
     * Cancel an intent. Only intents that never got provider evidence
     * may lapse by hand; anything else is a fact that happened and can
     * not be un-happened by vocabulary.
     *
     * @throws PaymentIntentException
     */
    public function cancel(string $intentKey, int $userId): PaymentIntent
    {
        return DB::transaction(function () use ($intentKey, $userId): PaymentIntent {
            /** @var PaymentIntent|null $locked */
            $locked = PaymentIntent::query()
                ->lockForUpdate()
                ->where('intent_key', trim($intentKey))
                ->first();

            if (! $locked instanceof PaymentIntent) {
                throw PaymentIntentException::notFound($intentKey);
            }

            if ((int) $locked->user_id !== $userId) {
                throw PaymentIntentException::walletMismatch($userId, (int) $locked->wallet_id);
            }

            if ($locked->status === PaymentTransactionStatus::Failed && $locked->payment_id === null) {
                return $locked; // replay of an elapsed cancellation
            }

            if (! in_array($locked->status, [PaymentTransactionStatus::Initiated, PaymentTransactionStatus::Pending], true) || $locked->payment_id !== null) {
                throw PaymentIntentException::invalidTransition($intentKey, $locked->status->value, PaymentTransactionStatus::Failed->value);
            }

            $locked->status = PaymentTransactionStatus::Failed;
            $locked->failure_reason = PaymentFailureReason::Expired;
            $locked->failed_at = now();
            $locked->save();

            $this->recordAudit($locked, 'Cancelled by its holder (no provider evidence received)', RiskLevel::Medium);

            return $locked;
        });
    }

    /* ---------------------------------------- provider-lane verbs ---- */

    /**
     * Attach the legacy Payment aggregate and pronounce the intent's
     * provider-visible position. Verbs move strictly through the
     * transition map; already-final rows replay. Called by the
     * orchestration lane (driver initiation) and webhook application.
     *
     * @throws PaymentIntentException
     */
    public function pronounce(PaymentIntent $intent, PaymentTransactionStatus $target, ?Payment $payment = null, ?PaymentFailureReason $reason = null): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $target, $payment, $reason): PaymentIntent {
            /** @var PaymentIntent|null $locked */
            $locked = PaymentIntent::query()->lockForUpdate()->find((int) $intent->getKey());

            if (! $locked instanceof PaymentIntent) {
                throw PaymentIntentException::notFound((string) $intent->intent_key);
            }

            if ($locked->status === $target) {
                if ($payment instanceof Payment && $locked->payment_id === null) {
                    $locked->payment_id = (int) $payment->id;
                    $locked->save();
                }

                return $locked; // replay
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw PaymentIntentException::invalidTransition((string) $locked->intent_key, $locked->status->value, $target->value);
            }

            $locked->status = $target;
            $locked->payment_id = $payment instanceof Payment ? (int) $payment->id : $locked->payment_id;
            $locked->failure_reason = $reason ?? $locked->failure_reason;
            $locked->succeeded_at = $target === PaymentTransactionStatus::Succeeded ? now() : $locked->succeeded_at;
            $locked->failed_at = $target === PaymentTransactionStatus::Failed ? now() : $locked->failed_at;
            $locked->reversed_at = $target === PaymentTransactionStatus::Reversed ? now() : $locked->reversed_at;
            $locked->save();

            $this->recordAudit($locked, sprintf('Pronouncement: %s → %s%s', $intent->status instanceof PaymentTransactionStatus ? $intent->status->value : '?', $target->value, $reason instanceof PaymentFailureReason ? ' ('.$reason->value.')' : ''), RiskLevel::Medium);

            return $locked;
        });
    }

    /* -------------------------------------------------- internals ---- */

    /**
     * The provider code a method code resolves to in this deployment.
     * The registry's own tables own offers; this map only names the
     * provider each method BELONGS to (wallet top-up rail mapping).
     */
    public static function providerFor(string $methodCode): string
    {
        return match (strtolower(trim($methodCode))) {
            'promptpay' => 'promptpay',
            'bkash' => 'bkash',
            'nagad' => 'nagad',
            'crypto' => 'crypto',
            'bank_transfer', 'manual' => 'bank_transfer',
            'stripe', 'card' => 'stripe',
            default => strtolower(trim($methodCode)),
        };
    }

    public static function moneyOf(string $amount): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($amount, '0', 2);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function recordAudit(PaymentIntent $intent, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => (int) $intent->user_id,
            'action' => $intent->wasRecentlyCreated ? AuditAction::Create : AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => PaymentIntent::class,
            'auditable_id' => (int) $intent->getKey(),
            'description' => sprintf('%s (intent %s...)', $description, substr((string) $intent->intent_key, 0, 12)),
            'metadata' => [
                'intent_key' => (string) $intent->intent_key,
                'wallet_id' => (int) $intent->wallet_id,
                'direction' => $intent->direction instanceof PaymentDirection ? $intent->direction->value : (string) $intent->direction,
                'lane' => 'payment-intent',
            ],
        ]);

        $log->save();
    }
}
