<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentReconciliationData;
use App\Enums\AuditAction;
use App\Enums\LedgerReconciliationStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentWebhookStatus;
use App\Enums\RiskLevel;
use App\Events\PaymentTransactionReconciled;
use App\Exceptions\PaymentReconciliationException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\PaymentWebhook;
use Illuminate\Support\Facades\DB;

/**
 * Provider-vs-internal payment reconciliation, exact bcmath.
 *
 * WHAT IS COMPARED
 *   internal side — the payments row's amount/currency/status as read
 *                   under its row lock.
 *   provider side — the LATEST APPLIED webhook envelope naming this
 *                   payment's (gateway, reference), whose normalized
 *                   amount/status the doorman re-proves on entry; if
 *                   no applied envelope exists, observation is absent
 *                   and an internal claim of finality is DRIFT.
 *
 * THE LAWS
 *   1. EXACTNESS — bcmath scale 2, never floats.
 *   2. NEVER SILENTLY MUTATES MONEY — reconciliation compares and
 *      pronounces; it never "fixes" a payment. Drift lives as readable
 *      evidence lines; the fail-closed gate is assertConsistent().
 *   3. CONVERSATION — one live row per payment (fingerprint replay:
 *      identical facts serve the recorded row; rotations refresh).
 *      Resolving seals; a sealed conversation never reopens.
 */
final class PaymentReconciliationService
{
    public function __construct(
        private readonly \App\Listeners\RecordPaymentReconciliationAudit $audit,
    ) {
    }

    /* ------------------------------------------------ run one ------ */

    /**
     * Reconcile one payment against the provider's applied evidence.
     * Drift returns a row (evidence) and never throws.
     *
     * @throws PaymentReconciliationException
     */
    public function reconcilePayment(int $paymentId): PaymentReconciliation
    {
        return DB::transaction(function () use ($paymentId): PaymentReconciliation {
            /** @var Payment|null $payment */
            $payment = Payment::query()->lockForUpdate()->find($paymentId);

            if (! $payment instanceof Payment) {
                throw PaymentReconciliationException::notFound('payment:'.$paymentId);
            }

            if ($payment->gateway === null || $payment->gateway_reference === null) {
                throw PaymentReconciliationException::notFound('payment:'.$paymentId.'/external-reference');
            }

            return $this->pronounce($payment);
        });
    }

    /* ----------------------------------------------- run many ------ */

    /**
     * Page of unresolved provider-carrying payments by id cursor.
     *
     * @return array{reconciled: int, drift: int, next_after_id: int}
     */
    public function sweep(int $afterPaymentId = 0, int $limit = 100): array
    {
        $reconciled = 0;
        $drift = 0;
        $lastId = $afterPaymentId;

        Payment::query()
            ->where('id', '>', $afterPaymentId)
            ->whereNotNull('gateway_reference')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get(['id'])
            ->each(function (Payment $payment) use (&$reconciled, &$drift, &$lastId): void {
                try {
                    $row = $this->reconcilePayment((int) $payment->id);
                    $reconciled++;
                    $lastId = (int) $payment->id;

                    if ($row->status === LedgerReconciliationStatus::DriftDetected) {
                        $drift++;
                    }
                } catch (PaymentReconciliationException) {
                    // A torn page never halts the sweep.
                }
            });

        return ['reconciled' => $reconciled, 'drift' => $drift, 'next_after_id' => $lastId];
    }

    /* ---------------------------------------------- fail-closed ---- */

    /**
     * THE TRUTH NOW: refuse by name when internal and provider paper
     * disagree. For disbursement-window grade consumers.
     *
     * @throws PaymentReconciliationException
     */
    public function assertConsistent(int $paymentId): PaymentReconciliation
    {
        $row = $this->reconcilePayment($paymentId);

        if ($row->status === LedgerReconciliationStatus::DriftDetected) {
            $lines = is_array($row->drift_lines) ? $row->drift_lines : [];

            if ($row->observed_amount !== null && bccomp((string) $row->expected_amount, (string) $row->observed_amount, 2) !== 0) {
                throw PaymentReconciliationException::amountMismatch(
                    (string) $row->external_reference,
                    (string) $row->expected_amount,
                    (string) $row->observed_amount,
                    (string) $row->currency,
                    $lines,
                );
            }

            if ($row->observed_status === null) {
                throw PaymentReconciliationException::missingTransaction((string) $row->provider, (string) $row->external_reference, $lines);
            }

            throw PaymentReconciliationException::statusMismatch(
                (string) $row->external_reference,
                (string) $row->internal_status,
                (string) $row->observed_status,
                $lines,
            );
        }

        return $row;
    }

    /* -------------------------------------------------- resolve ---- */

    /**
     * Seal a drifted conversation row with a full-sentence note.
     *
     * @throws PaymentReconciliationException
     */
    public function resolve(PaymentReconciliation $row, string $note): PaymentReconciliation
    {
        return DB::transaction(function () use ($row, $note): PaymentReconciliation {
            /** @var PaymentReconciliation|null $locked */
            $locked = PaymentReconciliation::query()->lockForUpdate()->find((int) $row->getKey());

            if (! $locked instanceof PaymentReconciliation) {
                throw PaymentReconciliationException::notFound((string) $row->reconciliation_key);
            }

            if ($locked->status === LedgerReconciliationStatus::Resolved) {
                return $locked;
            }

            $note = trim($note);

            if (mb_strlen($note) < 8) {
                throw PaymentReconciliationException::malformed('a resolution note must be a full sentence (8+ characters)');
            }

            $locked->status = LedgerReconciliationStatus::Resolved;
            $locked->resolved_note = \Illuminate\Support\Str::limit($note, 255, '');
            $locked->resolved_at = now();
            $locked->save();

            $this->recordAudit($locked, sprintf('Resolved (%s)', $locked->resolved_note));

            return $locked;
        });
    }

    /* ------------------------------------------------ internals ---- */

    /**
     * @throws PaymentReconciliationException
     */
    private function pronounce(Payment $payment): PaymentReconciliation
    {
        $provider = strtolower((string) $payment->gateway);
        $reference = (string) $payment->gateway_reference;

        // INTERNAL FACTS AT THE ROW.
        $expected = \App\Services\Payment\PaymentCallbackService::moneyOf((string) $payment->amount);
        $currency = strtoupper(($payment->currency instanceof \BackedEnum ? (string) $payment->currency->value : (string) $payment->currency));
        $internalStatusEnum = $payment->status instanceof PaymentStatus ? $payment->status : PaymentStatus::tryFrom((string) $payment->status);
        $internalStatus = PaymentTransactionStatus::fromInternalPaymentStatus($internalStatusEnum ?? PaymentStatus::Pending);

        // PROVIDER OBSERVATION: latest APPLIED webhook for this
        // (provider, event) which names this reference in its payload.
        $observedAmount = null;
        $observedStatus = null;

        /** @var PaymentWebhook|null $applied */
        $applied = PaymentWebhook::query()
            ->where('provider', $provider)
            ->where('status', PaymentWebhookStatus::Applied->value)
            ->orderByDesc('id')
            ->get(['payload'])
            ->first(function (PaymentWebhook $webhook) use ($reference): bool {
                $payload = is_array($webhook->payload) ? $webhook->payload : [];
                $facts = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : $payload;

                return in_array($reference, [
                    is_string($facts['reference'] ?? null) ? $facts['reference'] : null,
                    is_string($facts['gateway_reference'] ?? null) ? $facts['gateway_reference'] : null,
                    is_string($facts['payment_reference'] ?? null) ? $facts['payment_reference'] : null,
                    is_string($facts['id'] ?? null) ? $facts['id'] : null,
                ], true);
            });

        if ($applied instanceof PaymentWebhook) {
            $payload = is_array($applied->payload) ? $applied->payload : [];
            $facts = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : $payload;

            if (isset($facts['amount'])) {
                $raw = is_string($facts['amount']) ? trim($facts['amount']) : number_format((float) $facts['amount'], 2, '.', '');

                if (preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
                    $observedAmount = \App\Services\Payment\PaymentCallbackService::moneyOf($raw);
                }
            }

            $word = $facts['status'] ?? $payload['status'] ?? null;

            if (is_string($word)) {
                $observedStatus = PaymentTransactionStatus::fromProviderWord($word);
            }
        }

        $data = PaymentReconciliationData::fromInput(
            paymentId: (int) $payment->id,
            providerCode: $provider,
            externalReference: $reference,
            expectedAmount: $expected,
            observedAmount: $observedAmount,
            currency: $currency,
            internalStatus: $internalStatus,
            observedStatus: $observedStatus,
        );

        // --- DRIFT LINES ------------------------------------------------
        $driftLines = [];

        if ($observedAmount !== null && bccomp($expected, $observedAmount, 2) !== 0) {
            $driftLines[] = sprintf('amount: internal paper says %s %s, provider evidence says %s', $expected, $currency, $observedAmount);
        }

        if ($observedStatus !== null && $internalStatus !== $observedStatus) {
            $driftLines[] = sprintf('status: internal paper says [%s], provider evidence says [%s]', $internalStatus->value, $observedStatus->value);
        }

        if ($observedStatus === null && $internalStatus->isFinal()) {
            $driftLines[] = 'missing-transaction: internal paper claims finality but the provider evidence room is silent';
        }

        $status = $driftLines === [] ? LedgerReconciliationStatus::Matched : LedgerReconciliationStatus::DriftDetected;

        // --- CONVERSATION, generation-suffixed -----------------------------
        $baseKey = PaymentReconciliationData::baseKeyOf((int) $payment->id);

        /** @var PaymentReconciliation|null $row */
        $row = PaymentReconciliation::query()
            ->lockForUpdate()
            ->where('reconciliation_key', 'LIKE', $baseKey.'%')
            ->where('status', '!=', LedgerReconciliationStatus::Resolved->value)
            ->orderByDesc('id')
            ->first();

        if ($row instanceof PaymentReconciliation) {
            if ((string) $row->fingerprint === $data->fingerprint && (int) $row->payment_id === (int) $payment->id) {
                return $row;
            }

            $row->fill([
                'provider' => $provider,
                'external_reference' => $reference,
                'expected_amount' => $expected,
                'observed_amount' => $observedAmount,
                'currency' => $currency,
                'internal_status' => $internalStatus->value,
                'observed_status' => $observedStatus?->value,
                'fingerprint' => $data->fingerprint,
                'drift_lines' => $driftLines,
            ]);
            $row->status = $status;
            $row->save();

            $this->recordAudit($row, sprintf('Refreshed (%s, %d drift line(s))', $status->value, count($driftLines)));
            event(new PaymentTransactionReconciled($row, $status, $driftLines));

            return $row;
        }

        $generations = (int) PaymentReconciliation::query()
            ->where('reconciliation_key', 'LIKE', $baseKey.'%')
            ->count();

        $row = new PaymentReconciliation();
        $row->fill([
            'reconciliation_key' => $generations === 0 ? $baseKey : sprintf('%s:%d', $baseKey, $generations + 1),
            'payment_id' => (int) $payment->id,
            'provider' => $provider,
            'external_reference' => $reference,
            'expected_amount' => $expected,
            'observed_amount' => $observedAmount,
            'currency' => $currency,
            'internal_status' => $internalStatus->value,
            'observed_status' => $observedStatus?->value,
            'fingerprint' => $data->fingerprint,
            'drift_lines' => $driftLines,
        ]);
        $row->status = $status;
        $row->save();

        $this->recordAudit($row, sprintf('Pronounced (%s, %d drift line(s))', $status->value, count($driftLines)));
        event(new PaymentTransactionReconciled($row, $status, $driftLines));

        return $row;
    }

    private function recordAudit(PaymentReconciliation $row, string $description): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $row->status === LedgerReconciliationStatus::DriftDetected ? RiskLevel::High : RiskLevel::Medium,
            'auditable_type' => PaymentReconciliation::class,
            'auditable_id' => (int) $row->getKey(),
            'description' => sprintf('%s (payment #%d)', $description, (int) $row->payment_id),
            'metadata' => [
                'reconciliation_key' => (string) $row->reconciliation_key,
                'payment_id' => (int) $row->payment_id,
                'provider' => (string) $row->provider,
                'expected_amount' => (string) $row->expected_amount,
                'observed_amount' => $row->observed_amount === null ? null : (string) $row->observed_amount,
                'internal_status' => (string) $row->internal_status,
                'observed_status' => $row->observed_status === null ? null : (string) $row->observed_status,
                'fingerprint' => (string) $row->fingerprint,
                'lane' => 'payment-reconciliation',
            ],
        ]);

        $log->save();
    }
}
