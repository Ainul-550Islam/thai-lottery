<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Authoritative user-level KYC verification lifecycle.
 *
 *   Pending     — no decision yet (documents still being gathered)
 *   UnderReview — a desk/system review is reading the evidence
 *   Verified    — the house pronounces the identity proven
 *   Rejected    — the evidence was refused (a desk keeps the reason)
 *   Expired     — formerly verified, now stale (documents lapsed)
 *
 * The VERDICT is server-authoritative: nothing in this lifecycle is
 * set from client-supplied outcomes; verdicts derive from verified
 * document evidence ONLY (KycVerificationService).
 */
enum KycVerificationStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under Review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::UnderReview, self::Rejected], true),
            self::UnderReview => in_array($next, [self::Verified, self::Rejected], true),
            self::Rejected => $next === self::Pending,       // a refused applicant may re-apply
            self::Verified => $next === self::Expired,       // verified → stale only by lapse
            self::Expired => $next === self::Pending,
        };
    }

    public function countsAsVerified(): bool
    {
        return $this === self::Verified;
    }
}
