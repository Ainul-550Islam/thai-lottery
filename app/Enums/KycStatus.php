<?php

declare(strict_types=1);

namespace App\Enums;

enum KycStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::Unverified, self::Rejected, self::Expired], true);
    }
}
