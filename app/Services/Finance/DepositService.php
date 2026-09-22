<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\FinancialReferenceType;
use App\Enums\PaymentMethod;
use App\Exceptions\DepositException;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating and cancelling a deposit REQUEST.
 *
 * THE ONE RULE THAT MATTERS HERE
 * ------------------------------
 * Creating a deposit request MUST NOT credit the wallet. This class contains no
 * call to WalletService::credit(), no call to FinancialTransactionService and no
 * balance arithmetic at all. What it produces is an intent row in `deposits` with
 * status `pending` and `financial_transaction_id` still null. Money appears only
 * in DepositCompletionService, after an approval.
 *
 * NO PAYMENT GATEWAY. This phase integrates nothing: no Stripe, no crypto, no
 * bKash, no Nagad, no provider SDK, no webhook and no credential. `provider` and
 * `provider_reference` are accepted as plain non-secret strings so a later phase
 * can reconcile against a provider statement, and nothing here calls out to a
 * network.
 *
 * IDEMPOTENCY
 * `deposits.idempotency_key` is UNIQUE in the audited schema. A retried request
 * carrying the same key returns the row that already exists instead of creating a
 * second one, and a key that exists with different terms is refused as a conflict
 * rather than silently reused. Amounts are compared as exact decimal strings.
 *
 * LOCK ORDER
 * WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS, the same order as everywhere
 * else in the engine. Creation takes the wallet lock only to read a consistent
 * currency and status; the deposit row does not exist yet, so there is nothing
 * after it to lock.
 *
 * EXACT ARITHMETIC ONLY. Every amount is a Money value object over bcmath. No
 * float is constructed in this file.
 */
final class DepositService
{
    /** Prefix for the human-facing deposit reference. */
    private const REFERENCE_PREFIX = 'DP';

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly WalletLockService $locks,
        private readonly FinancialStateTransitionService $transitions,
    ) {}

    /**
     * Record the intention to deposit money. Credits nothing.
     *
     * @param  array<string, mixed>  $options  method, provider, provider_reference, fee, metadata
     * @return Deposit the pending request
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function request(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method = PaymentMethod::Manual,
        ?string $idempotencyKey = null,
        array $options = [],
    ): Deposit {
        $amount->assertPositive('deposit amount');
        Money::assertExactArithmeticIsAvailable();

        $normalisedKey = $idempotencyKey === null ? null : $this->idempotency->normaliseKey($idempotencyKey);

        $fee = $this->resolveFee($amount, $options);
        $netAmount = $this->resolveNetAmount($amount, $fee);

        // RESPONSIBLE GAMING (batch-14): deposit ceilings + the
        // fail-closed exclusion gate, pronounced before any row is
        // recorded. Refusals carry the desk's named codes.
        /** @var User|null $rgUser */
        $rgUser = User::query()->find($wallet->user_id);

        if ($rgUser instanceof User) {
            app(ResponsibleGamingEnforcementService::class)
                ->assertDepositAllowed($rgUser, $amount->toString());
        }

        return $this->withinTransaction(function () use ($wallet, $amount, $fee, $netAmount, $method, $normalisedKey, $options): Deposit {
            $lockedWallet = $this->locks->lock((int) $wallet->getKey());

            $this->assertWalletAcceptsDeposit($lockedWallet, $amount->currency());
            $this->assertAmountWithinLimits($amount);

            if ($normalisedKey !== null) {
                $existing = $this->findByIdempotencyKey($normalisedKey);

                if ($existing instanceof Deposit) {
                    $this->assertReplayMatches($existing, $lockedWallet, $amount, $fee, $method);

                    return $existing;
                }
            }

            $deposit = new Deposit;

            $deposit->fill([
                'reference_number' => $this->generateReferenceNumber(),
                'user_id' => (int) $lockedWallet->user_id,
                'wallet_id' => (int) $lockedWallet->getKey(),
                'method' => $method,
                'provider' => $this->stringOption($options, 'provider'),
                'provider_reference' => $this->stringOption($options, 'provider_reference'),
                'idempotency_key' => $normalisedKey,
                'currency' => $amount->currency(),
                'amount' => $amount->toString(),
                'fee' => $fee->toString(),
                'net_amount' => $netAmount->toString(),
                'metadata' => $this->buildMetadata($options),
            ]);

            // System-assigned, therefore never mass assignable.
            $deposit->uuid = (string) Str::uuid();

            // Explicitly pending: a new request has not been reviewed and has
            // certainly not been credited.
            $deposit->status = DepositStatus::Pending;
            $deposit->financial_transaction_id = null;
            $deposit->confirmed_at = null;
            $deposit->failed_at = null;

            try {
                $deposit->save();
            } catch (QueryException $exception) {
                // Lost a race on the unique idempotency key: return the winner.
                if ($normalisedKey !== null && $this->idempotency->isDuplicateKeyViolation($exception)) {
                    $winner = $this->findByIdempotencyKey($normalisedKey);

                    if ($winner instanceof Deposit) {
                        $this->assertReplayMatches($winner, $lockedWallet, $amount, $fee, $method);

                        return $winner;
                    }
                }

                throw $exception;
            }

            return $deposit;
        });
    }

    /**
     * Readable alias of request().
     *
     * @param  array<string, mixed>  $options
     *
     * @throws FinancialException
     */
    public function create(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method = PaymentMethod::Manual,
        ?string $idempotencyKey = null,
        array $options = [],
    ): Deposit {
        return $this->request($wallet, $amount, $method, $idempotencyKey, $options);
    }

    /**
     * Withdraw the request before it was ever credited.
     *
     * Only `pending` and `approved` qualify, and neither of those has moved money,
     * so no balance change and no ledger entry is involved. A confirmed deposit is
     * NOT cancellable: reversing money that already arrived requires a new
     * compensating transaction, which is FinancialReversalService's job.
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function cancel(Deposit $deposit, ?string $reason = null, ?int $actorUserId = null): Deposit
    {
        return $this->withinTransaction(function () use ($deposit, $reason, $actorUserId): Deposit {
            $lockedWallet = $this->locks->lock((int) $deposit->wallet_id);
            $current = $this->transitions->lockDeposit($deposit);

            $this->assertWalletMatches($current, $lockedWallet);

            if ($current->isCredited() || $current->status === DepositStatus::Confirmed) {
                throw DepositException::alreadyCredited($current->id, $current->financial_transaction_id);
            }

            if (! $current->status->canCancel()) {
                throw DepositException::notRejectable($current->id, $current->status);
            }

            return $this->transitions->transitionDeposit($current, DepositStatus::Cancelled, [
                'failed_at' => Carbon::now(),
                'failure_reason' => $reason ?? 'Cancelled before completion.',
                'metadata' => $this->mergeMetadata($current, array_filter([
                    'cancelled_at' => Carbon::now()->toIso8601String(),
                    'cancelled_by' => $actorUserId,
                    'cancellation_reason' => $reason,
                ], static fn ($value): bool => $value !== null)),
            ]);
        });
    }

    /**
     * Look up a request by its idempotency key.
     *
     * Soft-deleted rows are included because the unique index covers them: a key
     * belonging to a trashed row is still taken by the database.
     */
    public function findByIdempotencyKey(string $key): ?Deposit
    {
        return Deposit::withTrashed()
            ->where('idempotency_key', $this->idempotency->normaliseKey($key))
            ->first();
    }

    public function findByReferenceNumber(string $referenceNumber): ?Deposit
    {
        return Deposit::query()->byReferenceNumber($referenceNumber)->first();
    }

    /**
     * A stable idempotency key for a request, derived from its own terms.
     *
     * Offered so a caller with no key of its own still gets replay protection
     * instead of no protection at all.
     */
    public function deterministicKeyFor(Wallet $wallet, Money $amount, PaymentMethod $method, string $discriminator): string
    {
        return $this->idempotency->deterministicKey('deposit-request', implode('|', [
            (string) $wallet->getKey(),
            $amount->toString(),
            $amount->currency()->value,
            $method->value,
            $discriminator,
        ]));
    }

    /**
     * The fee that applies, as an exact amount.
     *
     * An explicit fee in $options wins; otherwise the configured percentage is
     * applied with bcmath. The result is stored, never recomputed later, so the
     * fee that applied at request time stays auditable.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws FinancialException
     */
    public function resolveFee(Money $amount, array $options = []): Money
    {
        if (isset($options['fee'])) {
            $fee = $options['fee'] instanceof Money
                ? $options['fee']
                : Money::of((string) $options['fee'], $amount->currency());

            $fee->assertNotNegative('deposit fee');
            $amount->assertSameCurrency($fee);

            return $fee;
        }

        $percentage = (string) config('finance.deposit.fee_percentage', '0.00');

        if (bccomp($percentage, '0', 6) === 0) {
            return Money::zero($amount->currency());
        }

        $raw = bcdiv(bcmul($amount->amount(), $percentage, 6), '100', 6);

        return Money::of($this->roundHalfUp($raw, $amount->scale()), $amount->currency());
    }

    /**
     * amount - fee, refused if it is not a usable positive amount.
     *
     * @throws DepositException
     */
    public function resolveNetAmount(Money $amount, Money $fee): Money
    {
        $amount->assertSameCurrency($fee);

        if ($fee->isGreaterThan($amount)) {
            throw DepositException::netAmountInvalid($amount->toString(), $fee->toString(), 'negative');
        }

        $net = $amount->minus($fee);

        if (! $net->isPositive()) {
            throw DepositException::netAmountInvalid($amount->toString(), $fee->toString(), $net->toString());
        }

        return $net;
    }

    /**
     * Guard the configured minimum and maximum.
     *
     * @throws DepositException
     */
    public function assertAmountWithinLimits(Money $amount): void
    {
        $minimum = (string) config('finance.deposit.min', '0.00');
        $maximum = (string) config('finance.deposit.max', '0.00');

        $min = Money::of($minimum, $amount->currency());
        $max = Money::of($maximum, $amount->currency());

        if ($amount->isLessThan($min) || $amount->isGreaterThan($max)) {
            throw DepositException::amountOutOfRange(
                $amount->toString(),
                $min->toString(),
                $max->toString(),
                $amount->currency(),
            );
        }
    }

    /**
     * The wallet must exist, be able to receive money, and share the currency.
     *
     * A currency difference is a hard refusal. Converting silently would require
     * inventing an exchange rate, and `finance.currency.conversion_enabled` is
     * false in the audited configuration.
     *
     * @throws DepositException
     */
    public function assertWalletAcceptsDeposit(Wallet $wallet, Currency $currency): void
    {
        if ($wallet->currency !== $currency) {
            throw DepositException::currencyMismatch((int) $wallet->getKey(), $wallet->currency, $currency);
        }

        if (! $wallet->canCredit()) {
            throw DepositException::walletNotCreditable((int) $wallet->getKey(), $wallet->status->value);
        }
    }

    /**
     * The polymorphic reference type used for a deposit's ledger entries.
     */
    public function referenceType(): FinancialReferenceType
    {
        return FinancialReferenceType::Deposit;
    }

    /**
     * A unique, human-quotable reference number.
     *
     * @throws FinancialException
     */
    public function generateReferenceNumber(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = substr(sprintf(
                '%s-%s-%s',
                self::REFERENCE_PREFIX,
                Carbon::now()->format('Ymd'),
                strtoupper(bin2hex(random_bytes(6))),
            ), 0, 64);

            if (! Deposit::withTrashed()->where('reference_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw FinancialException::withCode(
            'deposit_reference_generation_failed',
            'Could not generate a unique deposit reference number after several attempts.',
        );
    }

    /**
     * A replay must describe the same operation, or it is a conflict.
     *
     * @throws DepositException
     */
    private function assertReplayMatches(Deposit $existing, Wallet $wallet, Money $amount, Money $fee, PaymentMethod $method): void
    {
        $mismatches = [];

        if ((int) $existing->wallet_id !== (int) $wallet->getKey()) {
            $mismatches['wallet_id'] = (int) $existing->wallet_id;
        }

        if ($existing->currency !== $amount->currency()) {
            $mismatches['currency'] = $existing->currency->value;
        }

        if (bccomp((string) $existing->amount, $amount->amount(), $amount->scale()) !== 0) {
            $mismatches['amount'] = (string) $existing->amount;
        }

        if (bccomp((string) $existing->fee, $fee->amount(), $amount->scale()) !== 0) {
            $mismatches['fee'] = (string) $existing->fee;
        }

        if ($existing->method !== $method) {
            $mismatches['method'] = $existing->method->value;
        }

        if ($mismatches === []) {
            return;
        }

        throw DepositException::withReason(
            sprintf(
                'Idempotency key already belongs to deposit %d, which describes a different operation.',
                (int) $existing->getKey(),
            ),
            'deposit_idempotency_conflict',
            array_merge(
                ['deposit_id' => (int) $existing->getKey()],
                array_combine(
                    array_map(static fn (string $field): string => 'existing_'.$field, array_keys($mismatches)),
                    array_values($mismatches),
                ) ?: [],
            ),
        );
    }

    /**
     * @throws DepositException
     */
    private function assertWalletMatches(Deposit $deposit, Wallet $wallet): void
    {
        if ((int) $deposit->wallet_id === (int) $wallet->getKey()) {
            return;
        }

        throw DepositException::walletMismatch(
            (int) $deposit->getKey(),
            (int) $deposit->wallet_id,
            (int) $wallet->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function buildMetadata(array $options): ?array
    {
        $metadata = isset($options['metadata']) && is_array($options['metadata'])
            ? $options['metadata']
            : [];

        $metadata['requested_at'] = Carbon::now()->toIso8601String();

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $additions
     * @return array<string, mixed>
     */
    private function mergeMetadata(Deposit $deposit, array $additions): array
    {
        $existing = is_array($deposit->metadata) ? $deposit->metadata : [];

        return array_merge($existing, $additions);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        return isset($options[$key]) && is_string($options[$key]) && $options[$key] !== ''
            ? $options[$key]
            : null;
    }

    /**
     * Half-up rounding on a decimal string. bcmath truncates, so the carry is
     * added explicitly rather than relying on a float.
     */
    private function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;

        $increment = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($absolute, $increment, $scale);

        return $negative ? '-'.$rounded : $rounded;
    }

    private function withinTransaction(\Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback);
    }
}
