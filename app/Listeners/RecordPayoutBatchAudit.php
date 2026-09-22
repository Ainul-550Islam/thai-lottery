<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\PayoutBatchCompleted;
use App\Models\AuditLog;
use App\Models\PayoutBatch;

/**
 * Turns the one-time PayoutBatchCompleted event into one permanent,
 * scrubbed audit row.
 *
 * WHY THIS LISTENER EXISTS
 * ------------------------
 * The batch service itself writes a lifecycle audit on the flip — but the
 * flip-line belongs to the ledger narrative (what the executor did). THIS
 * row belongs to the REPORTING narrative (what the run accomplished):
 * counters and money, on a scrubbed projection, so analytics can consume
 * audit rows directly without ever touching member-level personal data.
 * The two narrative lanes coexist deliberately; they are answered with
 * different retention policies.
 *
 * ANCHOR-DEDUPLICATED
 * -------------------
 * The event already promises at-most-once delivery, but idempotency here
 * does not ride on trust: before writing, the listener probes for an
 * existing audit row (auditable PayoutBatch id + action marker
 * 'payout_batch_completed'). Queue redeliveries, Event::dispatch in tests
 * and operator reruns all collapse onto one row.
 *
 * SCRUBBED METADATA
 * -----------------
 * The audit projection carries ONLY run counters and money (member ids,
 * references, notes never cross this boundary). What the event itself
 * projected in toAuditPayload() is already that shape — this listener
 * asserts it and trims to a fixed allowlist so a future richer event
 * payload cannot silently leak into the audit.
 */
class RecordPayoutBatchAudit
{
    /**
     * The metadata.action marker on every row this listener writes.
     */
    public const ACTION_MARKER = 'payout_batch_completed';

    /**
     * Write the completion audit row, scrubbed and deduplicated by anchor.
     */
    public function handle(PayoutBatchCompleted $event): void
    {
        if ($this->alreadyRecorded($event)) {
            return;
        }

        // Trim to the fixed allowlist: the scrub happening in the projection
        // is the guarantee; the trim here is the seatbelt on it.
        $payload = $event->toAuditPayload();

        $scrubbed = [
            'batch_id' => $payload['batch_id'],
            'batch_key' => $payload['batch_key'],
            'member_count' => $payload['member_count'],
            'paid_count' => $payload['paid_count'],
            'failed_count' => $payload['failed_count'],
            'replayed_count' => $payload['replayed_count'],
            'paid_amount' => $payload['paid_amount'],
            'currency' => $payload['currency'],
            'fully_paid' => $payload['fully_paid'],
            'action' => self::ACTION_MARKER,
        ];

        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            // A batch whose members all paid is routine evidence; a batch
            // that completed WITH failures is where auditors look first.
            'risk_level' => $event->fullyPaid() ? RiskLevel::Medium : RiskLevel::High,
            'auditable_type' => PayoutBatch::class,
            'auditable_id' => $event->batch->getKey(),
            'description' => sprintf(
                'Payout batch [%s] completed: %d/%d paid, %d failed, %d replayed; paid %s %s.',
                $event->batchKey(),
                $event->paidCount,
                $event->memberCount,
                $event->failedCount,
                $event->replayedCount,
                $event->paidAmount,
                $event->currency(),
            ),
            'metadata' => $scrubbed,
        ]);

        $log->save();
    }

    /**
     * The anchor dedup probe: batch id + action marker, via the
     * sqlite-compatible json_extract the other metadata lanes use.
     */
    private function alreadyRecorded(PayoutBatchCompleted $event): bool
    {
        return AuditLog::query()
            ->where('auditable_type', PayoutBatch::class)
            ->where('auditable_id', (int) $event->batch->getKey())
            ->whereRaw("json_extract(metadata, '$.action') = ?", [self::ACTION_MARKER])
            ->exists();
    }
}
