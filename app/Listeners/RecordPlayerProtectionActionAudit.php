<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\PlayerProtectionActionApplied;
use App\Models\AuditLog;
use App\Models\PlayerProtectionAct;
use App\Models\PlayerProtectionCase;

/**
 * RecordPlayerProtectionActionAudit — fingerprint-deduplicated audit
 * for protection actions: actor, scope, evidence and the stamped
 * state transition. The action service calls the static scribe
 * synchronously inside its own transaction.
 */
final class RecordPlayerProtectionActionAudit
{
    /** Only look back this far when deduplicating anchors (hours). */
    private const LOOKBACK_HOURS = 48;

    public function handle(PlayerProtectionActionApplied $event): void
    {
        $this->stamp(
            act: $event->action,
            note: 'protection act applied (envelope)',
            anchor: 'ppa-evt-audit:'.$event->actionFingerprint(),
        );
    }

    /**
     * THE STATIC SCRIBE: exactly-once per act, free of duplicates
     * across any number of callers within the anchor's lookback.
     */
    public function from(PlayerProtectionAct $act, string $note): void
    {
        $this->stamp(
            act: $act,
            note: $note,
            anchor: 'ppa-audit:'.(string) $act->action_key.':'.$note,
        );
    }

    private function stamp(PlayerProtectionAct $act, string $note, string $anchor): void
    {
        $exists = AuditLog::query()
            ->where('auditable_type', PlayerProtectionAct::class)
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => PlayerProtectionCase::query()->where('case_key', $act->case_key)->value('user_id'),
            'action' => AuditAction::Update,
            'auditable_type' => PlayerProtectionAct::class,
            'auditable_id' => $act->id,
            'metadata' => [
                'lane' => 'player-protection-action',
                'risk_rating' => $act->action_type->touchesWallet() ? RiskLevel::High->value : RiskLevel::Medium->value,
                'action_key' => $act->action_key,
                'action_type' => $act->action_type->value,
                'case_key' => $act->case_key,
                'is_released' => $act->is_released,
                'audit_anchor' => $anchor,
                'note' => $note,
            ],
        ]);
    }
}
