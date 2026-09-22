<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\SuspiciousAuthenticationDetected;
use App\Models\AuditLog;
use App\Models\SecurityEvent;

/**
 * RecordSecurityEventAudit — exactly-once immutable security audit
 * row per detection envelope, fingerprints only.
 */
final class RecordSecurityEventAudit
{
    private const LOOKBACK_HOURS = 72;

    public function handle(SuspiciousAuthenticationDetected $event): void
    {
        $this->stamp($event->securityEvent, sprintf('suspicious authentication detected (%s)', $event->ipAddress ?? 'ip unknown'), 'sec-susp-audit:'.$event->detectionFingerprint());
    }

    /**
     * THE STATIC SCRIBE — direct callers get the same anchor law.
     */
    public function from(SecurityEvent $event, string $note): void
    {
        $this->stamp($event, $note, 'sec-audit:'.(string) $event->event_fingerprint.':'.$note);
    }

    private function stamp(SecurityEvent $event, string $note, string $anchor): void
    {
        $exists = AuditLog::query()
            ->where('auditable_type', SecurityEvent::class)
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => $event->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => SecurityEvent::class,
            'auditable_id' => $event->id,
            'metadata' => [
                'lane' => 'security-event',
                'risk_rating' => $event->risk_level->severity() >= 70 ? RiskLevel::High->value : RiskLevel::Medium->value,
                'event_type' => $event->event_type->value,
                'event_fingerprint' => $event->event_fingerprint,
                'audit_anchor' => $anchor,
                'note' => $note,
            ],
        ]);
    }
}
