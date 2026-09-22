<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityRiskLevel;
use App\Models\AuthenticationAttempt;
use App\Models\SecuritySession;
use App\Models\TrustedDevice;

/**
 * SecurityRiskAssessmentService — deterministic risk evaluation from
 * authentication / session / device evidence. Pure function of the
 * ledger: same evidence, same verdict, every replay. The scoring
 * rules are versioned here (SCORING_VERSION) so audits can name
 * exactly which math produced a classification.
 */
final class SecurityRiskAssessmentService
{
    public const SCORING_VERSION = 'sec-risk-v1';

    /** Evidence windows the assessment reads. */
    private const FAILED_WINDOW_MINUTES = 60;

    private const EXOTIC_IP_WINDOW_DAYS = 30;

    /**
     * Score an authentication CONTEXT before/at its seat:
     * untrusted+known device, exotic IP for the account, a recent
     * storm of failures against the identifier — weighted, capped,
     * banded by SecurityRiskLevel::fromScore.
     *
     * @return array{score: int, level: SecurityRiskLevel, reasons: array<int, string>}
     */
    public function assess(?int $userId, ?string $identifierHash, ?string $ipAddress, ?string $deviceFingerprint): array
    {
        $score = 0;
        $reasons = [];

        $recentFailures = $identifierHash !== null
            ? AuthenticationAttempt::query()
                ->where('identifier_hash', $identifierHash)
                ->where('outcome', 'failed')
                ->where('attempted_at', '>=', now()->subMinutes(self::FAILED_WINDOW_MINUTES))
                ->count()
            : 0;

        if ($recentFailures >= 5) {
            $score += 40;
            $reasons[] = 'FAILED_STORM';
        } elseif ($recentFailures >= 3) {
            $score += 25;
            $reasons[] = 'FAILURE_CLUSTER';
        }

        if ($userId !== null && $deviceFingerprint !== null) {
            $knownDevices = TrustedDevice::query()
                ->where('user_id', $userId)
                ->pluck('device_fingerprint')
                ->all();

            if ($knownDevices !== [] && ! in_array($deviceFingerprint, $knownDevices, true)) {
                $score += 30;
                $reasons[] = 'UNFAMILIAR_DEVICE';
            }
        }

        if ($userId !== null && $ipAddress !== null) {
            $seen = SecuritySession::query()
                ->where('user_id', $userId)
                ->where('ip_address', $ipAddress)
                ->where('issued_at', '>=', now()->subDays(self::EXOTIC_IP_WINDOW_DAYS))
                ->exists();

            $anyHistory = SecuritySession::query()
                ->where('user_id', $userId)
                ->where('issued_at', '>=', now()->subDays(self::EXOTIC_IP_WINDOW_DAYS))
                ->exists();

            if ($anyHistory && ! $seen) {
                $score += 25;
                $reasons[] = 'EXOTIC_IP';
            }
        }

        $score = min(100, $score);

        return [
            'score' => $score,
            'level' => SecurityRiskLevel::fromScore($score),
            'reasons' => $reasons,
        ];
    }

    /**
     * Whether the assessed level crosses the "park it" bar: the lane
     * parks sessions at High+ for desk review.
     */
    public function parksForReview(SecurityRiskLevel $level): bool
    {
        return $level->severity() >= SecurityRiskLevel::High->severity();
    }
}
