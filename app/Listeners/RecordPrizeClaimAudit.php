<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\PrizeClaimApproved;
use App\Models\AuditLog;
use App\Models\Payout;

/**
 * Turns every prize-claim APPROVAL into one permanent audit row.
 *
 * WHY THIS LISTENER EXISTS AT ALL
 * -------------------------------
 * The claim service already writes its own audit lines on submission and on
 * rejection; it does not write the APPROVAL line itself — because the same
 * approval has two distinct births (auto-approve on submission below the
 * review threshold, operator release from the UnderReview queue) and the
 * service would have to name the approval twice and could drift. The event
 * is the single seam through which BOTH births must pass, so wiring the
 * audit here means the line is written for every approval, ever, in exactly
 * one place.
 *
 * IDEMPOTENT BY ANCHOR
 * --------------------
 * The event contract promises at-most-once delivery per claim, but a queue
 * redelivery or an eager Event::dispatch in tests can still name the same
 * approval twice. The listener therefore refuses its own duplicate: when an
 * audit row already exists for this payout carrying metadata.action =
 * 'prize_claim_approved', the write is skipped. The anchor is the payout id
 * plus the action marker — the two things every audit row of this lane must
 * name anyway.
 *
 * RISK LEVEL
 *   auto-approved  → Low: no human decision was taken; the threshold rule
 *                    did it, matching the risk of the submission itself.
 *   human-approved → Medium: money-releasing human decisions are what the
 *                    audit exists to answer for.
 */
class RecordPrizeClaimAudit
{
    /**
     * The metadata.action marker stamped on every row this listener writes
     * (and checked before writing one).
     */
    public const ACTION_MARKER = 'prize_claim_approved';

    /**
     * Write the approval audit line. Synchronous and quiet: audit is part
     * of the approval's meaning, not a side-effect that may lag it.
     */
    public function handle(PrizeClaimApproved $event): void
    {
        if ($this->alreadyRecorded($event)) {
            return;
        }

        $log = new AuditLog;

        $description = $event->autoApproved
            ? sprintf(
                'Prize claim auto-approved (below review threshold) for bet #%d: %s %s to claimant #%d on payout #%d.',
                $event->betId,
                $event->amount(),
                $event->currency(),
                $event->claimantUserId,
                (int) $event->payout->getKey(),
            )
            : sprintf(
                'Prize claim approved by operator #%d for bet #%d: %s %s to claimant #%d on payout #%d.',
                (int) $event->approvedByUserId,
                $event->betId,
                $event->amount(),
                $event->currency(),
                $event->claimantUserId,
                (int) $event->payout->getKey(),
            );

        $log->fill([
            'user_id' => $event->approvedByUserId,
            'action' => AuditAction::Update,
            'risk_level' => $event->autoApproved ? RiskLevel::Low : RiskLevel::Medium,
            'auditable_type' => Payout::class,
            'auditable_id' => $event->payout->getKey(),
            'description' => $description,
            'metadata' => $event->toAuditPayload(),
        ]);

        $log->save();
    }

    /**
     * The anchor check: has this payout's claim approval already been
     * recorded? Metadata is queried through a LIKE-free existence probe —
     * auditable_type/auditable_id narrow to the payout, then the action
     * marker is matched with the sqlite-compatible json_extract the other
     * metadata lanes use.
     */
    private function alreadyRecorded(PrizeClaimApproved $event): bool
    {
        return AuditLog::query()
            ->where('auditable_type', Payout::class)
            ->where('auditable_id', (int) $event->payout->getKey())
            ->whereRaw("json_extract(metadata, '$.action') = ?", [self::ACTION_MARKER])
            ->exists();
    }
}
