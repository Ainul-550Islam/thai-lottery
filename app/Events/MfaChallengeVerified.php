<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MfaChallenge;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * MfaChallengeVerified — published EXACTLY ONCE after a valid MFA
 * verification (the service re-delivers only replays of the same
 * verification pronouncement).
 */
final class MfaChallengeVerified
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MfaChallenge $challenge,
        public readonly string $verifiedAt,
    ) {}

    /**
     * THE ANCHOR: this verification pronouncement may audit once.
     */
    public function verificationFingerprint(): string
    {
        return hash('sha256', 'glo-mfa-ver|'.(string) $this->challenge->challenge_key.'|'.$this->verifiedAt);
    }
}
