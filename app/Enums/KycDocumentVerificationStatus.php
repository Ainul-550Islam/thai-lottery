<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Individual document lifecycle (batch-13's authoritative lane).
 *
 *   Uploaded   — bytes registered, fingerprinted; not yet read
 *   Processing — claimed by the document engine for review
 *   Verified   — the desk/engine pronounced it genuine evidence
 *   Rejected   — refused (incomplete, forged, unreadable) with reason
 *   Expired    — its own validity date passed; evidence lapses
 *
 * The estate's LEGACY per-document column speaks KycStatus
 * (pending/under_review/verified/rejected/expired); the lanes are
 * kept in correspondence so legacy gates never go blind (the document
 * service stamps both columns from this lifecycle).
 */
enum KycDocumentVerificationStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Processing => 'Processing',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Uploaded => in_array($next, [self::Processing, self::Rejected, self::Expired], true),
            self::Processing => in_array($next, [self::Verified, self::Rejected, self::Expired], true),
            self::Verified => $next === self::Expired,
            self::Rejected, self::Expired => false,
        };
    }

    /**
     * The legacy lane word for the same fact (kept in sync when the
     * authoritative lane moves).
     */
    public function legacyStatus(): ?KycStatus
    {
        return match ($this) {
            self::Uploaded, self::Processing => KycStatus::UnderReview,
            self::Verified => KycStatus::Verified,
            self::Rejected => KycStatus::Rejected,
            self::Expired => KycStatus::Expired,
        };
    }

    public function countsAsEvidence(): bool
    {
        return $this === self::Verified;
    }
}
