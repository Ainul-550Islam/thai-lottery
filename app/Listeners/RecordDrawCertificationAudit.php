<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\DrawResultCertified;
use App\Models\AuditLog;
use App\Models\DrawCertification;
use Illuminate\Support\Facades\Log;

/**
 * The certification court's audit scribe.
 *
 * CONTRACT
 * Exactly one immutable audit row per (certification_key, act) — never
 * more, even when the dispatcher retries: the anchor IS the idempotency.
 * The row records actor, source, fingerprint + lifecycle transition so
 * the board's history is provable to a court: who certified WHAT paper,
 * WHEN, from WHICH provenance — never personal facts about the certifier.
 */
final class RecordDrawCertificationAudit
{
    public function __construct() {}

    public function handle(DrawResultCertified $event): void
    {
        $certification = DrawCertification::query()
            ->where('certification_key', $event->certificationKey)
            ->first();

        if (! $certification instanceof DrawCertification) {
            Log::warning('RecordDrawCertificationAudit: event for missing certification', [
                'certification_key' => substr($event->certificationKey, 0, 12),
            ]);

            return;
        }

        $anchor = DrawResultCertified::anchorFor($event->certificationKey, 'certified');

        $already = AuditLog::query()
            ->where('auditable_type', DrawCertification::class)
            ->where('auditable_id', (int) $certification->getKey())
            ->where('metadata->anchor', $anchor)
            ->exists();

        if ($already) {
            return; // replay: never mint a duplicate row
        }

        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Create,
            'risk_level' => RiskLevel::Critical,
            'auditable_type' => DrawCertification::class,
            'auditable_id' => (int) $certification->getKey(),
            'description' => sprintf(
                'Result certified for draw [%s]: fingerprint %s... from %s by [%s]',
                $event->drawReference,
                substr($event->resultFingerprint, 0, 12),
                $event->source->value,
                $event->certifierReference,
            ),
            'metadata' => [
                'anchor' => $anchor,
                'certification_key' => substr($event->certificationKey, 0, 12),
                'draw_id' => $event->drawId,
                'draw_reference' => $event->drawReference,
                'result_fingerprint' => $event->resultFingerprint,
                'source_type' => $event->source->value,
                'certifier_reference' => $event->certifierReference,
                'certified_at' => $event->certifiedAt,
                'lifecycle_transition' => 'pending_review:certified',
                'lane' => 'draw-certification',
            ],
        ]);

        $log->save();
    }
}
