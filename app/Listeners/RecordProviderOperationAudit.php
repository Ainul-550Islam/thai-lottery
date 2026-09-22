<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\ProviderOperationalStateChanged;
use App\Models\AuditLog;
use App\Models\ProviderOperation;

/**
 * RecordProviderOperationAudit — provider operation/state audit
 * with NO SECRETS or credentials: fingerprints and statuses only.
 */
final class RecordProviderOperationAudit
{
    private const LOOKBACK_HOURS = 72;

    public function handle(ProviderOperationalStateChanged $event): void
    {
        $this->stamp(
            $event->change,
            'provider seat changed: '.(string) $event->fromStatus.' → '.$event->change->status->value,
            'provop-evt-audit:'.$event->changeFingerprint(),
        );
    }

    /**
     * THE STATIC SCRIBE — called in-transaction by the service.
     */
    public function from(ProviderOperation $change, string $note): void
    {
        $this->stamp($change, $note, 'provop-audit:'.$change->change_fingerprint.':'.$note);
    }

    private function stamp(ProviderOperation $change, string $note, string $anchor): void
    {
        $exists = AuditLog::query()
            ->where('auditable_type', ProviderOperation::class)
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => $change->changed_by_user_id,
            'action' => AuditAction::Update,
            'auditable_type' => ProviderOperation::class,
            'auditable_id' => $change->id,
            'metadata' => [
                'lane' => 'provider-operation',
                'risk_rating' => RiskLevel::High->value,
                'provider' => $change->provider,
                'status' => $change->status->value,
                'change_fingerprint' => $change->change_fingerprint,
                'evidence_fingerprint' => $change->evidence_fingerprint,
                'audit_anchor' => $anchor,
                'note' => $note,
            ],
        ]);
    }
}
