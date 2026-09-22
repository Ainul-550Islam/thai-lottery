<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\LedgerReconciliationStatus;
use App\Enums\RiskLevel;
use App\Events\FinancialLedgerReconciled;
use App\Models\AuditLog;
use App\Models\LedgerReconciliation;

/**
 * The paper trail for the paper-trail machine: every reconciliation
 * pronouncement (Matched or drift) lands one verbatim audit line —
 * actor, evidence (drift lines), fingerprint, and the before/after
 * facts the lane could read under its lock.
 *
 * DEDUPLICATED by (reconciliation row, fingerprint): the reconciliation
 * sweep may pronounce the same exact fact-set many times, but the audit
 * line is anchored once per distinct fingerprint. Called synchronously
 * from the service (inside the same transaction) as well as via the
 * event bus.
 */
final class RecordFinancialReconciliationAudit
{
    public const LOOKBACK_ANCHOR_HOURS = 48;

    public function handle(FinancialLedgerReconciled $event): void
    {
        $row = $event->reconciliation;

        $anchor = $event->auditAnchor();

        $alreadyAudited = AuditLog::query()
            ->lockForUpdate()
            ->where('auditable_type', LedgerReconciliation::class)
            ->where('auditable_id', (int) $row->getKey())
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_ANCHOR_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($alreadyAudited) {
            return;
        }

        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $event->status === LedgerReconciliationStatus::DriftDetected
                ? RiskLevel::High
                : RiskLevel::Medium,
            'auditable_type' => LedgerReconciliation::class,
            'auditable_id' => (int) $row->getKey(),
            'description' => sprintf(
                '%s (expected %s, ledger %s, reservation effect %s)',
                $event->status === LedgerReconciliationStatus::DriftDetected
                    ? 'Wallet reconciliation found divergence'
                    : 'Wallet reconciliation matched',
                (string) $row->expected_balance,
                (string) $row->ledger_aggregate,
                (string) $row->reservation_effect,
            ),
            'metadata' => [
                'audit_anchor' => $anchor,
                'reconciliation_key' => (string) $row->reconciliation_key,
                'wallet_id' => (int) $row->wallet_id,
                'status' => $event->status->value,
                'expected_balance' => (string) $row->expected_balance,
                'ledger_aggregate' => (string) $row->ledger_aggregate,
                'reservation_effect' => (string) $row->reservation_effect,
                'fingerprint' => (string) $row->fingerprint,
                'drift_lines' => $event->driftLines,
                'lane' => 'ledger-reconciliation',
            ],
        ]);

        $log->save();
    }
}
