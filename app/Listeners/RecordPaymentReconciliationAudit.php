<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\LedgerReconciliationStatus;
use App\Enums\RiskLevel;
use App\Events\PaymentTransactionReconciled;
use App\Models\AuditLog;
use App\Models\PaymentReconciliation;

/**
 * The scribe of provider-vs-internal reconciliation.
 *
 * Registered on the event bus ($listen) AND behaved so direct
 * re-delivery of the same fingerprint is a no-op: one audit row per
 * distinct (row, fingerprint). A fingerprint ROTATION means the facts
 * moved, and that must audit again — drift lines carry the reason.
 */
final class RecordPaymentReconciliationAudit
{
    public const LOOKBACK_ANCHOR_HOURS = 48;

    public function handle(PaymentTransactionReconciled $event): void
    {
        $row = $event->reconciliation;
        $anchor = $event->auditAnchor();

        $already = AuditLog::query()
            ->lockForUpdate()
            ->where('auditable_type', PaymentReconciliation::class)
            ->where('auditable_id', (int) $row->getKey())
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_ANCHOR_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($already) {
            return;
        }

        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $event->status === LedgerReconciliationStatus::DriftDetected ? RiskLevel::High : RiskLevel::Medium,
            'auditable_type' => PaymentReconciliation::class,
            'auditable_id' => (int) $row->getKey(),
            'description' => sprintf(
                '%s (payment #%d, expected %s %s, observed %s)',
                $event->status === LedgerReconciliationStatus::DriftDetected
                    ? 'Provider reconciliation found divergence'
                    : 'Provider reconciliation matched',
                (int) $row->payment_id,
                (string) $row->expected_amount,
                (string) $row->currency,
                $row->observed_amount === null ? '(unobserved)' : (string) $row->observed_amount,
            ),
            'metadata' => [
                'audit_anchor' => $anchor,
                'reconciliation_key' => (string) $row->reconciliation_key,
                'payment_id' => (int) $row->payment_id,
                'provider' => (string) $row->provider,
                'external_reference' => (string) $row->external_reference,
                'status' => $event->status->value,
                'internal_status' => (string) $row->internal_status,
                'observed_status' => $row->observed_status === null ? null : (string) $row->observed_status,
                'fingerprint' => (string) $row->fingerprint,
                'drift_lines' => $event->driftLines,
                'lane' => 'payment-reconciliation',
            ],
        ]);

        $log->save();
    }
}
