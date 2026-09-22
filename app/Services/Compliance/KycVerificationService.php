<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\DTOs\Compliance\KycVerificationData;
use App\Enums\AuditAction;
use App\Enums\KycDocumentType;
use App\Enums\KycDocumentVerificationStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\RiskLevel;
use App\Exceptions\KycVerificationException;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative user-level KYC verdicts.
 *
 * THE LAW OF THIS LANE
 *   1. DERIVED, NEVER SUPPLIED — the verdict is a deterministic
 *      function of the user's VERIFIED documents: at least one
 *      primary identity document (NationalId / Passport /
 *      DrivingLicense) with its lane status Verified and its own
 *      expiry date still in the future ⇒ Verified. Anything else ⇒
 *      refused by name. No caller can hand in a verdict; there is
 *      literally no parameter for one.
 *   2. EXPIRED EVIDENCE FAILS CLOSED — a verdict reigns only while
 *      its evidence is alive; the service derives AGAIN each time it
 *      pronounces.
 *   3. DETERMINISTIC REPLAY — the verification reference derives
 *      from (user, document-set fingerprint): re-pronouncing the same
 *      evidence serves the existing decision; a different decision
 *      under the same reference is a fork, cried loudly.
 *   4. ONE LIVE CONVERSATION per user — a current (non-terminal)
 *      row is refreshed when the derived verdict changes for the same
 *      docset; a sealed (Rejected/Expired) conversation rotates.
 */
final class KycVerificationService
{
    /* ----------------------------------------------- pronounce ---- */

    /**
     * Derive and pronounce the user's CURRENT KYC verdict from live
     * evidence. Returns the decision row.
     *
     * @return array{verification: KycVerification, replayed: bool, derived: bool}
     *
     * @throws KycVerificationException
     */
    public function pronounce(int $userId, ?int $reviewerUserId = null, string $source = 'system'): array
    {
        return DB::transaction(function () use ($userId, $reviewerUserId, $source): array {
            /** @var User|null $user */
            $user = User::query()->lockForUpdate()->find($userId);

            if (! $user instanceof User) {
                throw KycVerificationException::notFound('user:'.$userId);
            }

            // LIVE EVIDENCE, read under our own facts.
            $primaryFingerprint = null;
            $docset = [];

            KycDocument::query()
                ->where('user_id', $user->id)
                ->where('verification_status', KycDocumentVerificationStatus::Verified->value)
                ->lockForUpdate()
                ->get()
                ->each(function (KycDocument $document) use (&$primaryFingerprint, &$docset): void {
                    if ((string) $document->document_fingerprint === '') {
                        return;
                    }

                    // Expiry is re-proved at pronounce time, always.
                    if ($document->expires_at !== null && $document->expires_at->isPast()) {
                        return;
                    }

                    $docset[] = (string) $document->document_fingerprint;

                    $type = $document->document_type instanceof KycDocumentType
                        ? $document->document_type
                        : KycDocumentType::tryFrom((string) $document->document_type);

                    if ($type instanceof KycDocumentType && $type->isPrimaryIdentityDocument()) {
                        $primaryFingerprint = $primaryFingerprint ?? (string) $document->document_fingerprint;
                    }
                });

            $derived = $primaryFingerprint !== null;
            $setFingerprint = KycVerificationData::documentSetFingerprint($docset);

            $reference = KycVerificationData::referenceFor($userId, $setFingerprint);

            // REPLAY BY REFERENCE: same reference → same verdict must
            // already be the recorded one; disagreement is a fork.
            /** @var KycVerification|null $existing */
            $existing = KycVerification::query()
                ->lockForUpdate()
                ->where('verification_reference', $reference)
                ->first();

            $wantedStatus = $derived ? KycVerificationStatus::Verified : KycVerificationStatus::Rejected;

            if ($existing instanceof KycVerification) {
                $factsMatch = (int) $existing->user_id === $userId
                    && (string) $existing->document_set_fingerprint === $setFingerprint
                    && $existing->status === $wantedStatus;

                if (! $factsMatch) {
                    throw KycVerificationException::duplicateDecision($reference);
                }

                return ['verification' => $existing, 'replayed' => true, 'derived' => $derived];
            }

            if (! $derived) {
                // The evidence can't carry a verdict — pronounced by
                // name, never by silent status.
                throw KycVerificationException::invalidDocumentSet(
                    $userId,
                    $primaryFingerprint === null && $docset === []
                        ? 'no live verified primary identity document exists'
                        : 'no live verified primary identity document exists',
                    ['docset_size' => count($docset)],
                );
            }

            // One live conversation per user: refresh a current row;
            // rotate a sealed one.
            /** @var KycVerification|null $live */
            $live = KycVerification::query()
                ->lockForUpdate()
                ->where('user_id', $userId)
                ->whereNotIn('status', [
                    KycVerificationStatus::Expired->value,
                ])
                ->orderByDesc('id')
                ->first();

            $expiry = KycDocument::query()
                ->where('user_id', $userId)
                ->where('verification_status', KycDocumentVerificationStatus::Verified->value)
                ->whereNotNull('expires_at')
                ->min('expires_at');

            if ($live instanceof KycVerification) {
                $status = $live->status instanceof KycVerificationStatus ? $live->status : KycVerificationStatus::tryFrom((string) $live->status);
                $target = KycVerificationStatus::Verified;

                if ($status === $target) {
                    // Refresh the live row with the new docset.
                    $live->fill([
                        'verification_reference' => $reference,
                        'document_set_fingerprint' => $setFingerprint,
                        'expires_at' => $expiry,
                    ]);
                    $live->save();

                    return ['verification' => $live, 'replayed' => false, 'derived' => true];
                }

                if ($status === null || ! $status->canTransitionTo($target)) {
                    throw KycVerificationException::conflict($reference, sprintf('the live verification is %s', $status?->value ?? 'unknown'));
                }

                $live->status = $target;
                $live->verification_reference = $reference;
                $live->document_set_fingerprint = $setFingerprint;
                $live->decided_at = now();
                $live->reviewer_id = $reviewerUserId;
                $live->source = strtolower(trim($source));
                $live->expires_at = $expiry;
                $live->save();

                $this->recordAudit($live, sprintf('Derived Verified from docset [%s...]', substr($setFingerprint, 0, 8)));

                return ['verification' => $live, 'replayed' => false, 'derived' => true];
            }

            $row = new KycVerification;
            $row->fill([
                'verification_reference' => $reference,
                'user_id' => $userId,
                'document_set_fingerprint' => $setFingerprint,
                'reviewer_id' => $reviewerUserId,
                'source' => strtolower(trim($source)),
                'decided_at' => now(),
                'expires_at' => $expiry,
                'metadata' => [],
            ]);
            $row->status = KycVerificationStatus::Verified;
            $row->save();

            $this->recordAudit($row, sprintf('Derived Verified from docset [%s...]', substr($setFingerprint, 0, 8)));

            return ['verification' => $row, 'replayed' => false, 'derived' => true];
        });
    }

    /* ------------------------------------------------- derive ----- */

    /**
     * Read-only verb for inquiry callers: the user's live verdict, or
     * null if no decision exists. Implements "derive, never trust":
     * the live row IS the verdict (the user column is NEVER the
     * authority for a premium payout).
     */
    public function liveVerdictFor(int $userId): ?KycVerification
    {
        return KycVerification::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', [KycVerificationStatus::Expired->value])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Expire a verdict when its reigning evidence lapses (the desk /
     * a document expiry path calls this — physics, not vocabulary).
     *
     * @throws KycVerificationException
     */
    public function expire(KycVerification $verification): KycVerification
    {
        return DB::transaction(function () use ($verification): KycVerification {
            /** @var KycVerification|null $locked */
            $locked = KycVerification::query()->lockForUpdate()->find((int) $verification->getKey());

            if (! $locked instanceof KycVerification) {
                throw KycVerificationException::notFound((string) $verification->verification_reference);
            }

            if ($locked->status === KycVerificationStatus::Expired) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(KycVerificationStatus::Expired)) {
                throw KycVerificationException::conflict(
                    (string) $locked->verification_reference,
                    sprintf('a %s verification may not lapse by this verb', $locked->status->value),
                );
            }

            $locked->status = KycVerificationStatus::Expired;
            $locked->save();

            $this->recordAudit($locked, 'Verdict lapsed with its evidence', RiskLevel::High);

            return $locked;
        });
    }

    /* ------------------------------------------------ internals ---- */

    private function recordAudit(KycVerification $verification, string $description, RiskLevel $riskLevel = RiskLevel::Medium): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => (int) $verification->user_id,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => KycVerification::class,
            'auditable_id' => (int) $verification->getKey(),
            'description' => $description,
            'metadata' => [
                'verification_reference' => (string) $verification->verification_reference,
                'media_fingerprint_prefix' => substr((string) $verification->document_set_fingerprint, 0, 12),
                'source' => (string) $verification->source,
                'lane' => 'kyc-verification',
            ],
        ]);

        $log->save();
    }
}
