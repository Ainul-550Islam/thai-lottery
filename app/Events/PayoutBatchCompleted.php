<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PayoutBatch;
use App\Services\Finance\PayoutBatchService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired EXACTLY ONCE, at the moment a payout batch crosses Processing →
 * Completed: the run finished, every member has an accounted outcome, and
 * the final aggregate re-verification passed.
 *
 * THE AT-MOST-ONCE GUARANTEE, AND HOW IT IS KEPT
 * ----------------------------------------------
 * The event lives inside {@see PayoutBatchService::settle()}'s
 * flip commit: the write that makes the batch Completed and the event that
 * announces it are the same commit. A settle replay (queue redelivery,
 * operator rerunning the settlement report) finds the batch already
 * Completed and returns WITHOUT dispatching. Listeners may therefore trust
 * every instance they see as "this batch finished", but must still key
 * their own idempotency on the batch key if they write durable state — an
 * event name alone is not an idempotency anchor.
 *
 * WHAT THE EVENT CARRIES
 * ----------------------
 * The batch model (anchor), plus the run's outcome counters as immutable
 * scalar copies: listeners never need to re-read the row for the numbers
 * that made completion true, and audit rows written from here agree with
 * the row that fired.
 *
 * Completed ≠ "everyone was paid". failed_count and replayed_count travel
 * explicitly: a completion with failures is COMPLETE, not CLEAN.
 */
class PayoutBatchCompleted
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  PayoutBatch  $batch  The completed batch — identity anchor
     *                              (batch_key) and currency live here.
     */
    public function __construct(
        public readonly PayoutBatch $batch,
        public readonly int $memberCount,
        public readonly int $paidCount,
        public readonly int $failedCount,
        public readonly int $replayedCount,
        public readonly string $paidAmount,
    ) {}

    /**
     * The batch idempotency anchor for listener-side dedup (this is what
     * RecordPayoutBatchAudit keys its dedup probe on).
     */
    public function batchKey(): string
    {
        return (string) $this->batch->batch_key;
    }

    /**
     * The currency of the batch, e.g. THB.
     */
    public function currency(): string
    {
        return $this->batch->currency->value;
    }

    /**
     * Did every member pay? (Completion can be true with failures; this is
     * the strictly-better question.)
     */
    public function fullyPaid(): bool
    {
        return $this->paidCount === $this->memberCount;
    }

    /**
     * Listener-facing projection: no model objects survive it, and no
     * member-level personal data is ever part of the payload — references
     * alone would already over-share with analytics; nothing below is
     * further than the run counters.
     *
     * @return array<string, mixed>
     */
    public function toAuditPayload(): array
    {
        return [
            'batch_id' => (int) $this->batch->getKey(),
            'batch_key' => $this->batchKey(),
            'member_count' => $this->memberCount,
            'paid_count' => $this->paidCount,
            'failed_count' => $this->failedCount,
            'replayed_count' => $this->replayedCount,
            'paid_amount' => $this->paidAmount,
            'currency' => $this->currency(),
            'fully_paid' => $this->fullyPaid(),
            'action' => 'payout_batch_completed',
        ];
    }
}
