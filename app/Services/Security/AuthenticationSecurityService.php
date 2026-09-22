<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\Security\AuthenticationAttemptData;
use App\DTOs\Security\SecurityEventData;
use App\Enums\SecurityEventType;
use App\Enums\SecurityRiskLevel;
use App\Events\SuspiciousAuthenticationDetected;
use App\Exceptions\AuthenticationSecurityException;
use App\Models\AuthenticationAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AuthenticationSecurityService — the central FAIL-CLOSED
 * authentication gate. It exists so callers (AuthController and any
 * future gates) never re-implement attempt recording, rate-limits,
 * account-state law, or risk parking themselves.
 *
 * - Record every attempt ONCE by deterministic fingerprint.
 * - Gate repeat failures into lockout/rate-limit, then park riskier
 *   contexts as suspicious (envelope + audit), all without leaking
 *   account existence to the caller.
 */
final class AuthenticationSecurityService
{
    private const LOCKOUT_FAIL_COUNT = 10;

    private const LOCKOUT_WINDOW_MINUTES = 15;

    private const LOCKOUT_DURATION_MINUTES = 30;

    public function __construct(
        private readonly SecurityEventService $events,
        private readonly SecurityRiskAssessmentService $risk,
    ) {}

    /**
     * THE GATE: called BEFORE credentials are checked. Refuses with a
     * named desk code when the identifier/context is out of bounds.
     *
     * @throws AuthenticationSecurityException
     */
    public function assertAttemptAllowed(AuthenticationAttemptData $data): void
    {
        $failures = AuthenticationAttempt::query()
            ->where('identifier_hash', $data->identifierHash)
            ->where('outcome', 'failed')
            ->where('attempted_at', '>=', now()->subMinutes(self::LOCKOUT_WINDOW_MINUTES))
            ->count();

        if ($failures >= self::LOCKOUT_FAIL_COUNT) {
            throw AuthenticationSecurityException::lockedOut(
                $data->identifierHash,
                now()->addMinutes(self::LOCKOUT_DURATION_MINUTES)->toIso8601String(),
            );
        }

        if ($failures >= self::LOCKOUT_FAIL_COUNT - 3) {
            throw AuthenticationSecurityException::rateLimited($data->identifierHash, 60);
        }
    }

    /**
     * RECORD: exactly-once persistence; the same attempt from any
     * path is one row.
     *
     * @return array{attempt: AuthenticationAttempt, persisted: bool}
     */
    public function record(AuthenticationAttemptData $data, string $outcome, ?string $outcomeReason = null): array
    {
        return DB::transaction(function () use ($data, $outcome, $outcomeReason): array {
            $fingerprint = $data->attemptFingerprint();

            /** @var AuthenticationAttempt|null $existing */
            $existing = AuthenticationAttempt::query()->where('attempt_fingerprint', $fingerprint)->first();

            if ($existing instanceof AuthenticationAttempt) {
                return ['attempt' => $existing, 'persisted' => false];
            }

            $attempt = AuthenticationAttempt::query()->create([
                'attempt_fingerprint' => $fingerprint,
                'user_id' => $data->userId,
                'method' => $data->method,
                'identifier_hash' => $data->identifierHash,
                'ip_address' => $data->ipAddress,
                'user_agent' => $data->userAgent,
                'device_fingerprint' => $data->deviceFingerprint,
                'outcome' => $outcome,
                'outcome_reason' => $outcomeReason,
                'attempted_at' => $data->attemptedAt,
            ]);

            return ['attempt' => $attempt, 'persisted' => true];
        });
    }

    /**
     * POST-SEAT REVIEW: assess risk, persist the security row, and if
     * the evidence crosses the bar, publish the suspicious envelope
     * (exactly-once: the row's fingerprint is the envelope's anchor).
     *
     * @return array{score: int, level: SecurityRiskLevel, reasons: array<int, string>, parked: bool}
     */
    public function postSeatReview(User $user, AuthenticationAttemptData $data): array
    {
        $assessment = $this->risk->assess(
            userId: (int) $user->id,
            identifierHash: $data->identifierHash,
            ipAddress: $data->ipAddress,
            deviceFingerprint: $data->deviceFingerprint,
        );

        $parked = $this->risk->parksForReview($assessment['level']);

        if ($parked) {
            $published = $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::SuspiciousAuthentication,
                'user_id' => (int) $user->id,
                'risk_level' => $assessment['level'],
                'ip_address' => $data->ipAddress,
                'device_fingerprint' => $data->deviceFingerprint,
                'payload' => [
                    'score' => $assessment['score'],
                    'reasons' => $assessment['reasons'],
                    'scoring_version' => SecurityRiskAssessmentService::SCORING_VERSION,
                ],
                'occurred_at' => $data->attemptedAt,
            ]));

            if ($published['persisted']) {
                event(new SuspiciousAuthenticationDetected($published['event'], $data->ipAddress, (string) $data->userId));
            }
        }

        return [...$assessment, 'parked' => $parked];
    }

    /**
     * Login outcome persistence by index of concision around the desk.
     */
    public function loginSucceeded(User $user, AuthenticationAttemptData $data): void
    {
        $this->events->publish(SecurityEventData::fromInput([
            'event_type' => SecurityEventType::LoginSucceeded,
            'user_id' => (int) $user->id,
            'ip_address' => $data->ipAddress,
            'device_fingerprint' => $data->deviceFingerprint,
        ]));
    }

    public function loginFailed(?int $userId, AuthenticationAttemptData $data): void
    {
        $this->events->publish(SecurityEventData::fromInput([
            'event_type' => SecurityEventType::LoginFailed,
            'user_id' => $userId,
            'risk_level' => SecurityRiskLevel::Medium,
            'ip_address' => $data->ipAddress,
            'device_fingerprint' => $data->deviceFingerprint,
        ]));
    }
}
