<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Security\DeviceTrustData;
use App\DTOs\Security\MfaChallengeData;
use App\Exceptions\DeviceTrustException;
use App\Exceptions\MfaChallengeException;
use App\Exceptions\UserSessionException;
use App\Http\Responses\ApiResponse;
use App\Models\MfaChallenge;
use App\Models\SecurityEvent;
use App\Models\SecuritySession;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Security\DeviceTrustService;
use App\Services\Security\MfaChallengeService;
use App\Services\Security\UserSessionSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SecurityController — the AUTHENTICATED user's security surface:
 * MFA challenge issue/verify, active sessions, session revoke,
 * device trust/revoke and a security-event summary. The user
 * identity comes from the token ALONE — never a payload field.
 */
final class SecurityController
{
    public function __construct(
        private readonly MfaChallengeService $mfa,
        private readonly UserSessionSecurityService $sessions,
        private readonly DeviceTrustService $devices,
    ) {}

    /**
     * POST /security/mfa/challenge — issue an MFA challenge on the
     * caller's lane (totp channel; the secret stays with the
     * authenticator, only its fingerprint is carried).
     */
    public function challengeMfa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'secret_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'channel' => ['nullable', 'string', 'in:totp,sms,email'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $outcome = $this->mfa->issue(MfaChallengeData::forIssuance([
                'user_id' => (int) $user->id,
                'secret_fingerprint' => $validated['secret_fingerprint'],
                'channel' => $validated['channel'] ?? 'totp',
            ]));
        } catch (MfaChallengeException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'challenge_key' => substr($outcome['challenge']->challenge_key, 0, 12),
            'expires_at' => $outcome['challenge']->expires_at->toIso8601String(),
            'channel' => $outcome['challenge']->channel,
            'issued' => $outcome['issued'],
        ], 'MFA challenge issued.');
    }

    /**
     * POST /security/mfa/verify — pronounce the caller's answer. The
     * ANSWER NEVER LEAVES THE GATE: the authenticator resolves it
     * outside the ledger; the controller asks the pipeline's
     * predicate — answer strings never reach any audit lane.
     */
    public function verifyMfa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_key' => ['required', 'string', 'size:12'],
            'answer' => ['required', 'string', 'min:6', 'max:16'],
            'secret' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $challenge = MfaChallenge::query()
            ->where('user_id', $user->id)
            ->where('challenge_key', 'like', $validated['challenge_key'].'%')
            ->first();

        if (! $challenge instanceof MfaChallenge) {
            return ApiResponse::error('mfa_not_found', 'Unknown challenge for this account.', 404);
        }

        try {
            // The predicate compares the candidate secret's fingerprint
            // against the stored one and checks the code against the
            // candidate secret; only the fingerprint crosses into the
            // lane context — never the strings themselves.
            $verified = $this->mfa->verify($challenge->challenge_key, function (string $storedFingerprint) use ($validated): bool {
                $candidate = hash('sha256', 'glo-mfa-secret|'.$validated['secret']);

                if (! hash_equals($storedFingerprint, $candidate)) {
                    return false;
                }

                $expected = substr(hash('sha256', 'glo-mfa-code|'.$validated['secret'].'|'.now()->format('Y-m-d:H')), 0, 6);

                return hash_equals($expected, strtolower($validated['answer']));
            });
        } catch (MfaChallengeException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'status' => $verified->status->value,
            'verified_at' => $verified->verified_at?->toIso8601String(),
        ], 'MFA challenge verified.');
    }

    /**
     * GET /security/sessions — the caller's own active sessions.
     */
    public function sessions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rows = $this->sessions->activeSessionsFor((int) $user->id)
            ->map(static fn (SecuritySession $s): array => [
                'reference' => substr((string) $s->session_fingerprint, 0, 12),
                'device_fingerprint' => $s->device_fingerprint,
                'ip_address' => $s->ip_address,
                'issued_at' => $s->issued_at->toIso8601String(),
                'expires_at' => $s->expires_at->toIso8601String(),
                'last_seen_at' => $s->last_seen_at?->toIso8601String(),
            ]);

        return ApiResponse::success(['sessions' => $rows], 'Active sessions listed.');
    }

    /**
     * DELETE /security/sessions/{reference} — revoke a session, own
     * rows ONLY (ownership is fail-closed inside the service).
     */
    public function revokeSession(Request $request, string $reference): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = SecuritySession::query()
            ->where('session_fingerprint', 'like', $reference.'%')
            ->first();

        if (! $session instanceof SecuritySession) {
            return ApiResponse::error('session.not.found', 'No such session.', 404);
        }

        try {
            $revoked = $this->sessions->revoke((string) $session->session_fingerprint, (int) $user->id, 'user request', 'self');
        } catch (UserSessionException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'status' => $revoked->status->value,
            'revoked_at' => $revoked->revoked_at?->toIso8601String(),
        ], 'Session revoked.');
    }

    /**
     * POST /security/devices/trust — register+verify a device by
     * evidence (caller has already produced the evidence fingerprint
     * out of its own verification step; nothing raw crosses here).
     */
    public function trustDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'presentation_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'evidence_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $data = DeviceTrustData::fromInput([
            'user_id' => (int) $user->id,
            'device_fingerprint' => $validated['device_fingerprint'],
            'presentation_hash' => $validated['presentation_hash'],
            'evidence_fingerprint' => $validated['evidence_fingerprint'],
        ]);

        try {
            $this->devices->register($data);
            $trusted = $this->devices->verify($data);
        } catch (DeviceTrustException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'device_fingerprint' => $trusted->device_fingerprint,
            'trust_status' => $trusted->trust_status->value,
            'trusted_at' => $trusted->trusted_at?->toIso8601String(),
        ], 'Device trusted.');
    }

    /**
     * DELETE /security/devices/{fingerprint} — revoke one of the
     * caller's own trusted devices.
     */
    public function revokeDevice(Request $request, string $fingerprint): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $revoked = $this->devices->revoke((int) $user->id, $fingerprint, 'user revoked device', 'self');
        } catch (DeviceTrustException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'trust_status' => $revoked->trust_status->value,
            'revoked_at' => $revoked->revoked_at?->toIso8601String(),
        ], 'Device revoked.');
    }

    /**
     * GET /security/events — summary of the caller's own security
     * ledger window (own rows alone; fingerprints only).
     */
    public function eventsSummary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $events = SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(static fn (SecurityEvent $e): array => [
                'type' => $e->event_type->value,
                'risk_level' => $e->risk_level->value,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'reference' => substr((string) $e->event_fingerprint, 0, 12),
            ]);

        $counts = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->get(['trust_status'])
            ->groupBy(fn (TrustedDevice $d) => $d->trust_status->value)
            ->map->count();

        return ApiResponse::success([
            'recent_events' => $events,
            'device_states' => $counts,
            'active_session_count' => $this->sessions->activeSessionsFor((int) $user->id)->count(),
        ], 'Security summary.');
    }
}
