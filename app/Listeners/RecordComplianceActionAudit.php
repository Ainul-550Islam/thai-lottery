<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\ComplianceActionType;
use App\Enums\RiskLevel;
use App\Events\ComplianceCaseEscalated;
use App\Models\AuditLog;
use App\Models\ComplianceAction;
use App\Models\ComplianceCase;

/**
 * The compliance scribe. Two duties:
 *
 *   1. STATIC `from()` — synchronously record an applied compliance
 *      ACTION inside the SAME transaction as the act itself (the
 *      law's own paper never rides a fire-and-forget queue). Dedup
 *      anchor: (action row, applied act) — replay of a deterministic
 *      act never writes a second audit line.
 *   2. EVENT `handle()` — for ComplianceCaseEscalated envelopes:
 *      anchored by the envelope's escalation fingerprint, exactly
 *      once per distinct escalation pronouncement.
 *
 * RAW IDENTITY-DOCUMENT CONTENTS ARE NEVER STORED: audit anchors are
 * fingerprints + tokens only, forever.
 */
final class RecordComplianceActionAudit
{
    public const LOOKBACK_ANCHOR_HOURS = 48;

    /* ------------------------------------------- action scribe ---- */

    public static function from(ComplianceAction $action, string $description): void
    {
        $anchor = sprintf('comp-act-audit:%s', $action->action_key);

        $already = AuditLog::query()
            ->lockForUpdate()
            ->where('auditable_type', ComplianceAction::class)
            ->where('auditable_id', (int) $action->getKey())
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_ANCHOR_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($already) {
            return;
        }

        $type = $action->action_type instanceof ComplianceActionType ? $action->action_type : ComplianceActionType::tryFrom((string) $action->action_type);

        $log = new AuditLog;

        $log->fill([
            'user_id' => (int) $action->actor_user_id,
            'action' => AuditAction::Update,
            'risk_level' => $type instanceof ComplianceActionType && $type->touchesWallet() ? RiskLevel::High : RiskLevel::Medium,
            'auditable_type' => ComplianceAction::class,
            'auditable_id' => (int) $action->getKey(),
            'description' => $description,
            'metadata' => [
                'audit_anchor' => $anchor,
                'action_key_prefix' => substr((string) $action->action_key, 0, 16),
                'action_type' => $type instanceof ComplianceActionType ? $type->value : (string) $action->action_type,
                'reason_code' => (string) $action->reason_code,
                'evidence_reference' => (string) $action->evidence_reference,
                // FINGERPRINTS ONLY — the wallet fact names its own
                // seal, never personal contents.
                'wallet_fact' => $action->wallet_fact === null ? null : (string) $action->wallet_fact,
                'lane' => 'compliance-action',
            ],
        ]);

        $log->save();
    }

    /* ---------------------------------------- escalation scribe --- */

    public function handle(ComplianceCaseEscalated $event): void
    {
        $anchor = sprintf('comp-esc-audit:%s', $event->escalationFingerprint());
        $case = $event->case;

        $already = AuditLog::query()
            ->lockForUpdate()
            ->where('auditable_type', ComplianceCase::class)
            ->where('auditable_id', (int) $case->getKey())
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_ANCHOR_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($already) {
            return;
        }

        $log = new AuditLog;

        $log->fill([
            'user_id' => (int) $case->subject_user_id,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::High,
            'auditable_type' => ComplianceCase::class,
            'auditable_id' => (int) $case->getKey(),
            'description' => sprintf(
                'Case escalated (%s, source %s): %s',
                $event->riskLevel(),
                $event->source,
                $event->reason,
            ),
            'metadata' => [
                'audit_anchor' => $anchor,
                'case_key_prefix' => substr((string) $case->case_key, 0, 16),
                'trigger_reference' => $case->trigger_reference === null ? null : (string) $case->trigger_reference,
                'trigger_fingerprint' => substr((string) $case->evidence_fingerprint, 0, 16),
                'escalation_fingerprint' => $event->escalationFingerprint(),
                'escalated_at' => $event->escalatedAtIso,
                'lane' => 'compliance-case',
            ],
        ]);

        $log->save();
    }
}
