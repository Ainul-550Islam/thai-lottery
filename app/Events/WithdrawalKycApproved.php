<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Withdrawal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired exactly once per evidence identity when the withdrawal KYC gate
 * PASSES a money-out request.
 *
 * WHAT "PASSES" MEANS
 * -------------------
 * Two distinct births, both legitimate:
 *
 *   direct pass    The withdrawal arrived in Pending/UnderReview, and
 *                  the gate's mandatory verification found a Verified
 *                  identity standing. wasDetained = false.
 *   lifted pass    The withdrawal had been detained into KycRequired by
 *                  an earlier gate refusal; new valid evidence landed and
 *                  the gate's re-run passed it. wasDetained = true — and
 *                  the gate simultaneously lifted the detention back to
 *                  Pending so the human review resumes.
 *
 * THE PER-EVIDENCE-IDENTITY GUARANTEE
 * -----------------------------------
 * anchor = sha256(withdrawal reference + amount + currency + verified
 * document id + its verified_at stamp). A pass already stamped on this
 * anchor is a REPLAY and re-serves WITHOUT re-firing: queue redeliveries
 * and gate retries do not produce audit duplicates. A NEW verification
 * document ('verified' state re-proven) mints a freshman anchor and a
 * freshman audit — as the law would want: every evidence generation gets
 * its own pass paper.
 *
 * SCRUBBED BY CONSTRUCTION
 * ------------------------
 * The event carries ONLY: the withdrawal model (anchor), the requester's
 * user id, the evidence document's id, the anchor, and the detention
 * birth. Document numbers, file paths, personal fields of the evidence:
 * never present — any listener's serialization is the scrub.
 */
class WithdrawalKycApproved
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Withdrawal $withdrawal,
        public readonly int $userId,
        public readonly int $evidenceDocumentId,
        public readonly string $anchor,
        public readonly bool $wasDetained,
    ) {}

    /**
     * Listener-facing projection: model-free, document-detail-free.
     *
     * @return array<string, mixed>
     */
    public function toAuditPayload(): array
    {
        return [
            'withdrawal_id' => (int) $this->withdrawal->getKey(),
            'withdrawal_reference' => (string) $this->withdrawal->reference_number,
            'user_id' => $this->userId,
            'evidence_document_id' => $this->evidenceDocumentId,
            'anchor' => $this->anchor,
            'was_detained' => $this->wasDetained,
            'action' => 'withdrawal_kyc_approved',
        ];
    }
}
