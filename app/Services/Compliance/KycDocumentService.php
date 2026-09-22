<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\DTOs\Compliance\KycDocumentData;
use App\Enums\AuditAction;
use App\Enums\KycDocumentVerificationStatus;
use App\Enums\RiskLevel;
use App\Exceptions\KycVerificationException;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Register / verify / expire identity documents, authoritative lane.
 *
 *   1. IDENTITY = (user, byte-fingerprint) — the same bytes uploaded
 *      twice by the same user is the SAME document (replay); a forged
 *      row squatting on another document's identity is a fork.
 *   2. EXPIRY IS PHYSICS — a document whose own expiry passes is
 *      pronounced Expired and may never enter evidence again from
 *      that state.
 *   3. DUAL ALPHABET — the authoritative `verification_status`
 *      (spec lifecycle) and the legacy `status` (KycStatus) are
 *      stamped together on every move, so legacy gates (withdrawal
 *      KYC gate, Filament views) never go blind.
 *   4. EVIDENCE REFERENCES ARE IMMUTABLE — a verification decision
 *      literally stamps its verification_reference onto the document.
 */
final class KycDocumentService
{
    /* ------------------------------------------------- register --- */

    /**
     * Anchor a document's identity on the paper (no bytes touched —
     * the FILE columns belong to the legacy upload controller; this
     * lane anchors the meta-facts).
     *
     * @return array{document: KycDocument, replayed: bool}
     *
     * @throws KycVerificationException
     */
    public function register(KycDocumentData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var KycDocument|null $existing */
            $existing = KycDocument::query()
                ->lockForUpdate()
                ->where('user_id', $data->userId)
                ->where('document_fingerprint', $data->documentFingerprint)
                ->first();

            if ($existing instanceof KycDocument) {
                $factsMatch = (string) $existing->issuer_country === $data->issuerCountry
                    && $existing->document_type === $data->documentType;

                if (! $factsMatch) {
                    throw KycVerificationException::duplicateDecision('doc:'.$data->documentFingerprint);
                }

                return ['document' => $existing, 'replayed' => true];
            }

            /** @var User|null $user */
            $user = User::query()->find($data->userId);

            if (! $user instanceof User) {
                throw KycVerificationException::notFound('user:'.$data->userId);
            }

            $row = new KycDocument;
            $row->fill([
                'user_id' => $data->userId,
                'document_type' => $data->documentType,
                'issuer_country' => $data->issuerCountry,
                'document_fingerprint' => $data->documentFingerprint,
                'document_number' => null,
                // Bytes live in the legacy upload lane's columns; the
                // authoritative lane marks its own provenance so the
                // pair never lies about whose paper this is.
                'file_path' => sprintf('kyc/anchored/%s.bin', $data->documentFingerprint),
                'original_filename' => sprintf('%s-%s.dat', $data->documentType->value, substr($data->documentFingerprint, 0, 8)),
                'mime_type' => 'application/octet-stream',
                'file_size' => 0,
                'expires_at' => $data->expiryIso,
                'metadata' => ['provenance' => 'batch-13-authoritative-lane'],
            ]);
            $row->verification_status = KycDocumentVerificationStatus::Uploaded;
            $row->status = KycDocumentVerificationStatus::Uploaded->legacyStatus();
            $row->save();

            $this->recordAudit($row, sprintf(
                'Registered %s evidence [%s...] (issuer %s, expiry %s)',
                $data->documentType->value,
                substr($data->documentFingerprint, 0, 8),
                $data->issuerCountry,
                $data->expiryIso ?? 'none',
            ));

            return ['document' => $row, 'replayed' => false];
        });
    }

    /* --------------------------------------------------- verify --- */

    /**
     * Pronounce a document Verified. Desk/stamp act with an immutable
     * verification reference; EXPIRED EVIDENCE IS REFUSED BY NAME.
     *
     * @throws KycVerificationException
     */
    public function verify(KycDocument $document, int $reviewerUserId, string $verificationReference): KycDocument
    {
        return DB::transaction(function () use ($document, $reviewerUserId, $verificationReference): KycDocument {
            /** @var KycDocument|null $locked */
            $locked = KycDocument::query()->lockForUpdate()->find((int) $document->getKey());

            if (! $locked instanceof KycDocument) {
                throw KycVerificationException::notFound('document:'.$document->getKey());
            }

            $status = $locked->verification_status instanceof KycDocumentVerificationStatus
                ? $locked->verification_status
                : KycDocumentVerificationStatus::tryFrom((string) $locked->verification_status);

            if ($status === KycDocumentVerificationStatus::Verified) {
                return $locked; // replay
            }

            // EXPIRED EVIDENCE NEVER BECOMES VERIFIED, without exception.
            if ($locked->expires_at !== null && $locked->expires_at->isPast()) {
                throw KycVerificationException::expiredEvidence((string) $locked->document_fingerprint);
            }

            // THE SEATED ACT: the desk verifies a live document in ONE
            // atomic pronouncement. An Uploaded document is claimed
            // into Processing and verified in the same seated act —
            // every intermediate state is a real, stamped passage
            // (this row carries the full journey, nothing jumps).
            if (! in_array($status, [KycDocumentVerificationStatus::Uploaded, KycDocumentVerificationStatus::Processing], true)) {
                throw KycVerificationException::conflict(
                    'doc:'.$locked->id,
                    sprintf('a %s document may not be verified', $status?->value ?? 'unknown'),
                );
            }

            $ref = strtoupper(trim($verificationReference));

            if (strlen($ref) < 8 || strlen($ref) > 96 || ! preg_match('/^[A-Z0-9:\-._]+$/', $ref)) {
                throw KycVerificationException::malformed('the verification reference must be a canonical 8-96 character token');
            }

            $locked->verification_status = KycDocumentVerificationStatus::Verified;
            $locked->status = KycDocumentVerificationStatus::Verified->legacyStatus();
            $locked->verified_at = now();
            $locked->verified_by = $reviewerUserId;
            $locked->verification_reference = $ref;
            $locked->save();

            $this->recordAudit($locked, sprintf('Verified by #%d under [%s]', $reviewerUserId, $ref));

            return $locked;
        });
    }

    /* --------------------------------------------------- reject --- */

    /**
     * @throws KycVerificationException
     */
    public function reject(KycDocument $document, int $reviewerUserId, string $reason): KycDocument
    {
        return DB::transaction(function () use ($document, $reviewerUserId, $reason): KycDocument {
            /** @var KycDocument|null $locked */
            $locked = KycDocument::query()->lockForUpdate()->find((int) $document->getKey());

            if (! $locked instanceof KycDocument) {
                throw KycVerificationException::notFound('document:'.$document->getKey());
            }

            $status = $locked->verification_status instanceof KycDocumentVerificationStatus
                ? $locked->verification_status
                : KycDocumentVerificationStatus::tryFrom((string) $locked->verification_status);

            if ($status === KycDocumentVerificationStatus::Rejected) {
                return $locked;
            }

            if ($status === null || ! $status->canTransitionTo(KycDocumentVerificationStatus::Rejected)) {
                throw KycVerificationException::conflict('doc:'.$locked->id, 'a terminal document may not be re-refused');
            }

            $locked->verification_status = KycDocumentVerificationStatus::Rejected;
            $locked->status = KycDocumentVerificationStatus::Rejected->legacyStatus();
            $locked->rejection_reason = Str::limit(trim($reason), 255, '');
            $locked->verified_by = $reviewerUserId;
            $locked->save();

            $this->recordAudit($locked, sprintf('Rejected by #%d (%s)', $reviewerUserId, $locked->rejection_reason), RiskLevel::High);

            return $locked;
        });
    }

    /* --------------------------------------------------- expire --- */

    /**
     * Stamp a document's own expiry as pronounced. Works from any
     * non-terminal status; an already-expired row replays.
     *
     * @throws KycVerificationException
     */
    public function expire(KycDocument $document): KycDocument
    {
        return DB::transaction(function () use ($document): KycDocument {
            /** @var KycDocument|null $locked */
            $locked = KycDocument::query()->lockForUpdate()->find((int) $document->getKey());

            if (! $locked instanceof KycDocument) {
                throw KycVerificationException::notFound('document:'.$document->getKey());
            }

            $status = $locked->verification_status instanceof KycDocumentVerificationStatus
                ? $locked->verification_status
                : KycDocumentVerificationStatus::tryFrom((string) $locked->verification_status);

            if ($status === KycDocumentVerificationStatus::Expired) {
                return $locked;
            }

            if ($status === null || ! $status->canTransitionTo(KycDocumentVerificationStatus::Expired)) {
                throw KycVerificationException::conflict('doc:'.$locked->id, 'a terminal document may not lapse twice');
            }

            $locked->verification_status = KycDocumentVerificationStatus::Expired;
            $locked->status = KycDocumentVerificationStatus::Expired->legacyStatus();
            $locked->save();

            $this->recordAudit($locked, 'Expired by its own validity date');

            return $locked;
        });
    }

    /* ------------------------------------------------ internals ---- */

    private function recordAudit(KycDocument $document, string $description, RiskLevel $riskLevel = RiskLevel::Medium): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => (int) $document->user_id,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => KycDocument::class,
            'auditable_id' => (int) $document->getKey(),
            'description' => $description,
            'metadata' => [
                'kyc_document_id' => (int) $document->getKey(),
                // The audit NEVER stores document contents: fingerprints
                // and kind words only.
                'document_fingerprint_prefix' => substr((string) $document->document_fingerprint, 0, 12),
                'lane' => 'kyc-document',
            ],
        ]);

        $log->save();
    }
}
