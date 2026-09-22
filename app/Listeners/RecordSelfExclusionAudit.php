<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\SelfExclusionActivated;
use App\Models\AuditLog;
use App\Models\SelfExclusion;

/**
 * RecordSelfExclusionAudit — exactly-once audit for self-exclusion
 * activation / expiry / cancellation attempts, WITHOUT exposing the
 * sensitive request reason: only fingerprints, statuses and clocks
 * are recorded, never the reason_code itself.
 */
final class RecordSelfExclusionAudit
{
    /** Only look back this far when deduplicating anchors (hours). */
    private const LOOKBACK_HOURS = 48;

    public function handle(SelfExclusionActivated $event): void
    {
        $this->stamp(
            exclusion: $event->exclusion,
            note: 'self-exclusion activated (envelope)',
            anchor: 'se-evt-audit:'.$event->activationFingerprint(),
        );
    }

    /**
     * THE STATIC SCRIBE: called synchronously inside the seating
     * transaction; anchor-deduplicated so replays add no row.
     */
    public function from(SelfExclusion $exclusion, string $note): void
    {
        $this->stamp(
            exclusion: $exclusion,
            note: $note,
            anchor: 'se-audit:'.(string) $exclusion->request_fingerprint.':'.$exclusion->status->value.':'.$note,
        );
    }

    private function stamp(SelfExclusion $exclusion, string $note, string $anchor): void
    {
        $exists = AuditLog::query()
            ->where('auditable_type', SelfExclusion::class)
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => $exclusion->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => SelfExclusion::class,
            'auditable_id' => $exclusion->id,
            'metadata' => [
                'lane' => 'self-exclusion',
                'risk_rating' => RiskLevel::High->value,
                'status' => $exclusion->status->value,
                'request_fingerprint' => $exclusion->request_fingerprint,
                'ends_at' => $exclusion->ends_at->toIso8601String(),
                'audit_anchor' => $anchor,
                'note' => $note,
            ],
        ]);
    }
}
