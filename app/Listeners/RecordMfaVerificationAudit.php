<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\MfaChallengeVerified;
use App\Models\AuditLog;
use App\Models\MfaChallenge;

/**
 * RecordMfaVerificationAudit — dedicated MFA verification audit with
 * SENSITIVE-VALUE REDACTION: the ledger never learns the answer,
 * only the attempt outcome, channel and fingerprints.
 */
final class RecordMfaVerificationAudit
{
    private const LOOKBACK_HOURS = 48;

    public function handle(MfaChallengeVerified $event): void
    {
        $this->stamp($event->challenge, true, 'mfa-evt-audit:'.$event->verificationFingerprint());
    }

    /**
     * THE STATIC SCRIBE — called synchronously inside the verify
     * transaction; anchor-deduplicated across replays.
     */
    public function from(MfaChallenge $challenge, bool $verified): void
    {
        $this->stamp($challenge, $verified, 'mfa-audit:'.(string) $challenge->challenge_key.':'.($verified ? 'verified' : 'failed:'.$challenge->attempts));
    }

    private function stamp(MfaChallenge $challenge, bool $verified, string $anchor): void
    {
        $exists = AuditLog::query()
            ->where('auditable_type', MfaChallenge::class)
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => $challenge->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => MfaChallenge::class,
            'auditable_id' => $challenge->id,
            'metadata' => [
                'lane' => 'mfa-challenge',
                'risk_rating' => $verified ? RiskLevel::Medium->value : RiskLevel::High->value,
                'challenge_key' => $challenge->challenge_key,
                'channel' => $challenge->channel,
                'status' => $challenge->status->value,
                'attempts' => $challenge->attempts,
                'audit_anchor' => $anchor,
                // REDACTION LAW: no answer, secret or device payload ever
                // appears below this line — fingerprints and outcomes alone.
            ],
        ]);
    }
}
