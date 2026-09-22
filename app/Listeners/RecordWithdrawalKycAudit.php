<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\WithdrawalKycApproved;
use App\Models\AuditLog;
use App\Models\Withdrawal;

/**
 * One permanent, scrubbed, deduplicated audit row per KYC-gate PASS.
 *
 * WHY A LISTENER AT ALL
 * ---------------------
 * The gate's two pass births (direct pass and lifted detention) would
 * each need their own audit write inside the gate service — two places
 * naming the same "this pass must be audited" rule, demonstrably able to
 * drift. The event is the ONE seam every pass runs through; the audit
 * write lives here so gate pass = audit, provably, per anchor.
 *
 * ANCHOR-DEDUPLICATED
 * -------------------
 * The gate service already replays same-anchor passes without firing,
 * but idempotency here does not lean on that: the listener probes for an
 * existing audit row (auditable Withdrawal id + metadata.action marker +
 * anchor) BEFORE writing. Queue redeliveries and eager Event::dispatch
 * in tests collapse onto one row per anchor.
 *
 * SCRUBBED AUDIT
 * --------------
 * KYC/identity data must never live in a generic audit record: the row
 * keeps ONLY the correlation context (withdrawal reference, user id,
 * evidence document ID, anchor, detention birth). Document numbers,
 * file paths, identity fields: the event never carries them and this
 * listener adds an allowlist on top.
 *
 * RISK LEVEL
 *   lifted detention → High: a gate that first refused and then passed is
 *   the strongest anomaly an auditor can be handed — it MUST be the
 *   loudest row.
 *   direct pass → Medium: money-out identity certification.
 */
class RecordWithdrawalKycAudit
{
    /**
     * The metadata.action marker on every row this listener writes.
     */
    public const ACTION_MARKER = 'withdrawal_kyc_approved';

    /**
     * Write the gate-pass audit row, scrubbed + deduplicated.
     */
    public function handle(WithdrawalKycApproved $event): void
    {
        if ($this->alreadyRecorded($event)) {
            return;
        }

        // The allowlist: correlation context, never identity evidence.
        $payload = $event->toAuditPayload();

        $scrubbed = [
            'withdrawal_id' => $payload['withdrawal_id'],
            'withdrawal_reference' => $payload['withdrawal_reference'],
            'user_id' => $payload['user_id'],
            'evidence_document_id' => $payload['evidence_document_id'],
            'anchor' => $payload['anchor'],
            'was_detained' => $payload['was_detained'],
            'action' => self::ACTION_MARKER,
        ];

        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Withdraw,
            'risk_level' => $event->wasDetained ? RiskLevel::High : RiskLevel::Medium,
            'auditable_type' => Withdrawal::class,
            'auditable_id' => $event->withdrawal->getKey(),
            'description' => $event->wasDetained
                ? sprintf(
                    'KYC gate PASSED (detention lifted) for withdrawal [%s] of user #%d under anchor %s.',
                    (string) $event->withdrawal->reference_number,
                    $event->userId,
                    substr($event->anchor, 0, 12).'…',
                )
                : sprintf(
                    'KYC gate PASSED for withdrawal [%s] of user #%d under anchor %s.',
                    (string) $event->withdrawal->reference_number,
                    $event->userId,
                    substr($event->anchor, 0, 12).'…',
                ),
            'metadata' => $scrubbed,
        ]);

        $log->save();
    }

    /**
     * The probe: one pass row per (withdrawal, anchor) — sqlite-compatible
     * json_extract, matching the codebase's metadata-lane convention.
     */
    private function alreadyRecorded(WithdrawalKycApproved $event): bool
    {
        return AuditLog::query()
            ->where('auditable_type', Withdrawal::class)
            ->where('auditable_id', (int) $event->withdrawal->getKey())
            ->whereRaw("json_extract(metadata, '$.action') = ?", [self::ACTION_MARKER])
            ->whereRaw("json_extract(metadata, '$.anchor') = ?", [$event->anchor])
            ->exists();
    }
}
