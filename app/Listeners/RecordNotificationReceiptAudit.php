<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\NotificationReceipt;

/**
 * RecordNotificationReceiptAudit — receipt audit deduplicated by
 * provider/event fingerprint, fingerprints only.
 */
final class RecordNotificationReceiptAudit
{
    private const LOOKBACK_HOURS = 72;

    /**
     * THE STATIC SCRIBE — called inside the receive transaction.
     */
    public function from(NotificationReceipt $receipt, string $note): void
    {
        $anchor = 'notif-rcpt-audit:'.(string) $receipt->receipt_fingerprint;

        $exists = AuditLog::query()
            ->where('auditable_type', NotificationReceipt::class)
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => \App\Models\Notification::query()->whereKey($receipt->notification_id)->value('user_id'),
            'action' => AuditAction::Update,
            'auditable_type' => NotificationReceipt::class,
            'auditable_id' => $receipt->id,
            'metadata' => [
                'lane' => 'notification-receipt',
                'risk_rating' => RiskLevel::Medium->value,
                'receipt_fingerprint' => $receipt->receipt_fingerprint,
                'delivery_state' => $receipt->delivery_state,
                'audit_anchor' => $anchor,
                'note' => $note,
            ],
        ]);
    }
}
