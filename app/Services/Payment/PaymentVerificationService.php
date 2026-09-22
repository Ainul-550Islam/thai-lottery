<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\AuditAction;
use App\Enums\DepositStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PaymentVerificationException;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Services\Finance\DepositCompletionService;
use Illuminate\Support\Facades\DB;

/**
 * "I paid — here is my reference" → settle-proof verification and credit.
 *
 * The gateway-driven deposit path (Stripe intent succeeded, bKash webhook)
 * already settles itself through DepositCompletionService. This service is
 * for the OTHER source of truth: the player or support operator supplying a
 * manual payment proof — a bank transfer reference, a PromptPay transaction
 * id, a mobile-bank slip number — that must be tied to an existing deposit
 * row by reference, amount and currency, exactly once.
 *
 * WHAT VERIFICATION PROVES, IN ORDER
 * ----------------------------------
 * 1. A deposit row exists with the named provider reference (any method).
 * 2. Its recorded amount equals the claimed amount — bcmath to two decimals;
 *    the pair is reported on disagreement, never reconciled silently.
 * 3. Its currency equals the claimed currency.
 * 4. It has not yet been consumed by a previous settlement (a Confirmed
 *    deposit IS the consumption stamp: provider references sit on a unique
 *    index and completion is idempotent by construction).
 *
 * Only then does verify() hand the deposit to DepositCompletionService,
 * which claims idempotency (its own claim table), posts the balanced double
 * entry, credits the wallet and stamps Confirmed — all inside its OWN
 * transaction boundary (this service deliberately does NOT wrap it in a
 * second one).
 *
 * A proof that verifies NOTHING about any real deposit is refused as
 * reference-unknown; a proof on a payment that already settled IS answered
 * idempotently (already_consumed=true) rather than double-crediting.
 */
class PaymentVerificationService
{
    public function __construct(
        private readonly DepositCompletionService $completions,
    ) {
    }

    /**
     * Verify a manually supplied payment proof and, when everything agrees,
     * settle the underlying deposit into the player's wallet.
     *
     * @param  array{provider_reference?: string, deposit_reference?: string, amount: string, currency: string}  $claim
     *
     * @return array{
     *     deposit_id: int,
     *     deposit_reference: string,
     *     provider_reference: string|null,
     *     amount: string,
     *     currency: string,
     *     status: string,
     *     verified: bool,
     *     already_consumed: bool,
     *     verified_at: string
     * }
     *
     * @throws PaymentVerificationException
     * @throws \App\Exceptions\FinancialException
     */
    public function verify(array $claim, ?int $actorUserId = null): array
    {
        $providerReference = $this->stringClaim($claim, 'provider_reference');
        $depositReference = $this->stringClaim($claim, 'deposit_reference');
        $amount = $this->stringClaim($claim, 'amount');
        $currency = $this->stringClaim($claim, 'currency');

        if ($providerReference === null && $depositReference === null) {
            throw PaymentVerificationException::proofInvalid(
                'manual',
                'the proof supplies neither a provider reference nor a deposit reference to match against',
            );
        }

        if ($amount === null) {
            throw PaymentVerificationException::proofInvalid(
                'manual',
                'the proof supplies no amount to compare',
            );
        }

        $deposit = $this->findDeposit($providerReference, $depositReference);

        if (! $deposit instanceof Deposit) {
            throw PaymentVerificationException::referenceUnknown(
                $providerReference ?? $depositReference ?? '',
                'manual',
            );
        }

        // The proof's own reference is what binds the payment to this deposit.
        // A proof that did not even NAME the provider reference this deposit
        // carries is not a proof FOR the deposit, so continue only when the
        // caller's provider reference equals the deposit's recorded one (if
        // the deposit has one pinned at all).
        if ($providerReference !== null
            && $deposit->provider_reference !== null
            && (string) $deposit->provider_reference !== $providerReference
        ) {
            throw PaymentVerificationException::proofInvalid(
                'manual',
                sprintf(
                    'the deposit carries provider reference [%s] but the proof names [%s]; they must match',
                    (string) $deposit->provider_reference,
                    $providerReference,
                ),
                (array_filter(['deposit_id' => (int) $deposit->getKey()]) ?: []) + [],
            );
        }

        // 2 — amount agreement, exact.
        if (bccomp((string) $deposit->net_amount, $amount, 2) !== 0) {
            throw PaymentVerificationException::amountMismatch(
                (string) ($providerReference ?? $deposit->reference_number),
                $amount,
                (string) $deposit->net_amount,
            );
        }

        // 3 — currency agreement.
        if ($currency !== null && $deposit->currency->value !== strtoupper($currency)) {
            throw PaymentVerificationException::currencyMismatch(
                (string) ($providerReference ?? $deposit->reference_number),
                strtoupper($currency),
                $deposit->currency->value,
            );
        }

        // Consumed already? Replay the recorded outcome instead of crediting twice.
        if ($deposit->status === DepositStatus::Confirmed) {
            return $this->resultRow($deposit, alreadyConsumed: true);
        }

        // 4 — must be in a state from which settling is meaningful.
        if (! in_array($deposit->status, [DepositStatus::Approved, DepositStatus::Processing, DepositStatus::Pending], true)) {
            throw PaymentVerificationException::notSettleable(
                (string) ($providerReference ?? $deposit->reference_number),
                $deposit->status->value,
            );
        }

        // Settle through the one true completion path. This claims its own
        // idempotency key (deposit-scoped), posts the ledger and stamps
        // Confirmed — inside the completion service's own transaction.
        $this->completions->complete($deposit, null, [
            'provider_reference' => $providerReference,
            'actor_user_id' => $actorUserId,
        ]);

        $deposit->refresh();

        $this->recordAudit($deposit, $actorUserId, $providerReference);

        return $this->resultRow($deposit, alreadyConsumed: false);
    }

    /**
     * Look at a proof WITHOUT settling: the report a support operator wants
     * before pressing the button.
     *
     * @return array{deposit_id: int, matches_amount: bool, matches_currency: bool, settleable: bool, status: string}
     *
     * @throws PaymentVerificationException
     */
    public function probe(array $claim): array
    {
        $providerReference = $this->stringClaim($claim, 'provider_reference');
        $depositReference = $this->stringClaim($claim, 'deposit_reference');

        $deposit = $this->findDeposit($providerReference, $depositReference);

        if (! $deposit instanceof Deposit) {
            throw PaymentVerificationException::referenceUnknown(
                $providerReference ?? $depositReference ?? '',
                'manual',
            );
        }

        $amount = $this->stringClaim($claim, 'amount');
        $currency = $this->stringClaim($claim, 'currency');

        return [
            'deposit_id' => (int) $deposit->getKey(),
            'matches_amount' => $amount !== null && bccomp((string) $deposit->net_amount, $amount, 2) === 0,
            'matches_currency' => $currency !== null && $deposit->currency->value === strtoupper($currency),
            'settleable' => in_array($deposit->status, [DepositStatus::Approved, DepositStatus::Processing, DepositStatus::Pending], true),
            'status' => $deposit->status->value,
        ];
    }

    /**
     * The deposit a proof points at, either by the provider-side reference
     * (unique) or by our own deposit reference.
     */
    private function findDeposit(?string $providerReference, ?string $depositReference): ?Deposit
    {
        if ($providerReference !== null) {
            $deposit = Deposit::query()
                ->where('provider_reference', $providerReference)
                ->orderBy('id')
                ->first();

            if ($deposit instanceof Deposit) {
                return $deposit;
            }
        }

        if ($depositReference !== null) {
            return Deposit::query()
                ->where('reference_number', $depositReference)
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function stringClaim(array $claim, string $key): ?string
    {
        $value = $claim[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @return array{
     *     deposit_id: int,
     *     deposit_reference: string,
     *     provider_reference: string|null,
     *     amount: string,
     *     currency: string,
     *     status: string,
     *     verified: bool,
     *     already_consumed: bool,
     *     verified_at: string
     * }
     */
    private function resultRow(Deposit $deposit, bool $alreadyConsumed): array
    {
        return [
            'deposit_id' => (int) $deposit->getKey(),
            'deposit_reference' => (string) $deposit->reference_number,
            'provider_reference' => $deposit->provider_reference !== null ? (string) $deposit->provider_reference : null,
            'amount' => (string) $deposit->net_amount,
            'currency' => $deposit->currency->value,
            'status' => $deposit->status->value,
            'verified' => true,
            'already_consumed' => $alreadyConsumed,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Manual settlement is High risk even when it succeeds: money landed
     * through a human proof rather than a gateway signature, so the audit
     * names both the deposit and whoever asked.
     */
    private function recordAudit(Deposit $deposit, ?int $actorUserId, ?string $providerReference): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => $actorUserId,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::High,
            'auditable_type' => Deposit::class,
            'auditable_id' => $deposit->getKey(),
            'description' => sprintf(
                'Manual payment verification settled deposit %s for %s %s %s.',
                (string) $deposit->reference_number,
                (string) $deposit->net_amount,
                $deposit->currency->value,
                $providerReference !== null ? sprintf('via proof [%s]', $providerReference) : 'via proof',
            ),
            'metadata' => [
                'deposit_id' => (int) $deposit->getKey(),
                'deposit_reference' => (string) $deposit->reference_number,
                'provider_reference' => $providerReference,
                'amount' => (string) $deposit->net_amount,
                'currency' => $deposit->currency->value,
            ],
        ]);

        $log->save();
    }
}
