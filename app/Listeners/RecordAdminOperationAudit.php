<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\AdminOperationCompleted;
use App\Models\AdminOperation;
use App\Models\AuditLog;

/**
 * RecordAdminOperationAudit — immutable exactly-once audit over the
 * admin-operation lifecycle. Evidence rides as fingerprints;
 * secrets, credentials and raw payloads NEVER appear.
 */
final class RecordAdminOperationAudit
{
    private const LOOKBACK_HOURS = 72;

    public function handle(AdminOperationCompleted $event): void
    {
        $this->stamp(
            $event->operation,
            'operation outcome seated: '.$event->operation->status->value,
            'adminop-evt-audit:'.$event->completionFingerprint(),
        );
    }

    /**
     * THE STATIC SCRIBE — called in-transaction by the service.
     */
    public function from(AdminOperation $operation, string $note): void
    {
        $this->stamp($operation, $note, 'adminop-audit:'.$operation->operation_fingerprint.':'.$operation->status->value.':'.$note);
    }

    private function stamp(AdminOperation $operation, string $note, string $anchor): void
    {
        $exists = AuditLog::query()
            ->where('auditable_type', AdminOperation::class)
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => $operation->actor_user_id,
            'action' => AuditAction::Update,
            'auditable_type' => AdminOperation::class,
            'auditable_id' => $operation->id,
            'metadata' => [
                'lane' => 'admin-operation',
                'risk_rating' => RiskLevel::High->value,
                'operation_fingerprint' => $operation->operation_fingerprint,
                'type' => $operation->type->value,
                'status' => $operation->status->value,
                'target_lane' => $operation->target_lane,
                'evidence_fingerprint' => $operation->evidence_fingerprint,
                'audit_anchor' => $anchor,
                'note' => $note,
            ],
        ]);
    }
}
