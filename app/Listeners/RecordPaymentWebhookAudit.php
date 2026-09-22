<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\PaymentWebhookStatus;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\PaymentWebhook;

/**
 * The scribe of the webhook evidence room.
 *
 * Webhook evidence is stamped SYNCHRONOUSLY inside the same
 * transaction as the evidence row itself: services call the static
 * `from()` directly (a webhook's paper trail must never queue behind
 * the fact it proves, and must never ride a fire-and-forget bus).
 *
 * DEDUPED by (webhook row, status): each lifecycle pronouncement
 * lands exactly one anchored audit row; repeated sightings bump the
 * row's sighting count and anchor a NEW pronouncement line for each
 * Duplicate sighting — visible noise on purpose, because a provider
 * hammering the same bytes is operational signal.
 */
final class RecordPaymentWebhookAudit
{
    public const LOOKBACK_ANCHOR_HOURS = 48;

    /**
     * Write (once per anchor) a scrubbed audit row for a webhook
     * lifecycle pronunciation. Idempotent: the same anchor within the
     * lookback window writes nothing twice.
     */
    public static function from(PaymentWebhook $webhook, PaymentWebhookStatus $status, string $note): void
    {
        $anchor = sprintf('pay-wh-audit:%s:%s', $webhook->webhook_key, $status->value);

        // Duplicate sightings re-anchor each sighting count so the
        // noise stays visible; terminal statuses anchor once.
        if ($status === PaymentWebhookStatus::Duplicate) {
            $anchor = sprintf('%s:%d', $anchor, (int) $webhook->sightings);
        }

        $already = AuditLog::query()
            ->lockForUpdate()
            ->where('auditable_type', PaymentWebhook::class)
            ->where('auditable_id', (int) $webhook->getKey())
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
            'risk_level' => $status === PaymentWebhookStatus::Rejected ? RiskLevel::High : RiskLevel::Medium,
            'auditable_type' => PaymentWebhook::class,
            'auditable_id' => (int) $webhook->getKey(),
            'description' => sprintf(
                'Provider webhook %s: [%s] event [%s] (%s)',
                $status->value,
                (string) $webhook->provider,
                (string) $webhook->event_id,
                $note,
            ),
            'metadata' => [
                'audit_anchor' => $anchor,
                'webhook_key' => (string) $webhook->webhook_key,
                'provider' => (string) $webhook->provider,
                'event_id' => (string) $webhook->event_id,
                'event_type' => (string) $webhook->event_type,
                'status' => $status->value,
                'payload_fingerprint' => (string) $webhook->payload_fingerprint,
                'sightings' => (int) $webhook->sightings,
                'lane' => 'payment-webhook',
            ],
        ]);

        $log->save();
    }
}
