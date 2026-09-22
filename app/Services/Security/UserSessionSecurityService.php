<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\Security\SecurityEventData;
use App\DTOs\Security\UserSessionData;
use App\Enums\SecurityEventType;
use App\Enums\UserSessionStatus;
use App\Exceptions\UserSessionException;
use App\Models\SecuritySession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UserSessionSecurityService — issue / validate / rotate / revoke
 * authenticated sessions. Server timestamps ONLY: expiry is the
 * server's own physics; client-supplied times never judge anything.
 */
final class UserSessionSecurityService
{
    public function __construct(
        private readonly SecurityEventService $events,
    ) {}

    /**
     * ISSUE: a server-nonce-backed fingerprint gives simultaneous
     * legitimate logins distinct rows while replays of THE SAME
     * issuance (same nonce) land on one row.
     */
    public function issue(UserSessionData $data, string $serverNonce): SecuritySession
    {
        return DB::transaction(function () use ($data, $serverNonce): SecuritySession {
            $fingerprint = $data->sessionFingerprint($serverNonce);

            /** @var SecuritySession|null $existing */
            $existing = SecuritySession::query()->where('session_fingerprint', $fingerprint)->first();

            if ($existing instanceof SecuritySession) {
                return $existing;
            }

            $session = SecuritySession::query()->create([
                'session_fingerprint' => $fingerprint,
                'user_id' => $data->userId,
                'status' => UserSessionStatus::Active,
                'device_fingerprint' => $data->deviceFingerprint,
                'ip_address' => $data->ipAddress,
                'user_agent' => $data->userAgent,
                'issued_at' => $data->issuedAt,
                'expires_at' => $data->expiresAt,
                'last_seen_at' => $data->issuedAt,
            ]);

            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::SessionIssued,
                'user_id' => $data->userId,
                'ip_address' => $data->ipAddress,
                'device_fingerprint' => $data->deviceFingerprint,
                'session_fingerprint' => $fingerprint,
            ]));

            return $session;
        });
    }

    /**
     * VALIDATE: fail-closed for anything that is not an Active,
     * server-time-valid session owned by the requester. Stamps
     * last-seen on passage; passive expiry is pronounced (not merely
     * judged) when the horizon has run.
     */
    public function validate(string $sessionFingerprint, int $requesterUserId): SecuritySession
    {
        return DB::transaction(function () use ($sessionFingerprint, $requesterUserId): SecuritySession {
            /** @var SecuritySession|null $locked */
            $locked = SecuritySession::query()->lockForUpdate()->where('session_fingerprint', $sessionFingerprint)->first();

            if (! $locked instanceof SecuritySession) {
                throw UserSessionException::notFound($sessionFingerprint);
            }

            if ((int) $locked->user_id !== $requesterUserId) {
                throw UserSessionException::ownedBySomeoneElse($sessionFingerprint, $requesterUserId);
            }

            if ($locked->status === UserSessionStatus::Revoked) {
                throw UserSessionException::revoked($sessionFingerprint);
            }

            if ($locked->status === UserSessionStatus::Suspicious) {
                throw UserSessionException::suspicious($sessionFingerprint);
            }

            if ($locked->status === UserSessionStatus::Expired) {
                throw UserSessionException::expired($sessionFingerprint);
            }

            if (now()->gte($locked->expires_at)) {
                $locked->status = UserSessionStatus::Expired;
                $locked->save();
                $this->events->publish(SecurityEventData::fromInput([
                    'event_type' => SecurityEventType::SessionExpired,
                    'user_id' => $locked->user_id,
                    'session_fingerprint' => $sessionFingerprint,
                ]));

                throw UserSessionException::expired($sessionFingerprint);
            }

            $locked->last_seen_at = now();
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * ROTATE: the old session seals as Rotated-by-proxy? NO — the
     * enum is closed, so rotation is pronounced as: revoke the old
     * (reason 'rotated') and issue the successor with a fresh nonce,
     * atomically.
     */
    public function rotate(string $sessionFingerprint, int $requesterUserId): SecuritySession
    {
        return DB::transaction(function () use ($sessionFingerprint, $requesterUserId): SecuritySession {
            $old = $this->validate($sessionFingerprint, $requesterUserId);

            $old->status = UserSessionStatus::Revoked;
            $old->revoked_at = now();
            $old->revoked_by = 'rotation';
            $old->revocation_reason = 'rotated in favour of a fresh issuance';
            $old->save();

            $new = $this->issue(UserSessionData::forIssuance([
                'user_id' => $old->user_id,
                'device_fingerprint' => $old->device_fingerprint,
                'ip_address' => $old->ip_address,
                'user_agent' => $old->user_agent,
            ]), Str::random(32));

            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::SessionRotated,
                'user_id' => $old->user_id,
                'session_fingerprint' => $new->session_fingerprint,
                'payload' => ['predecessor' => substr((string) $old->session_fingerprint, 0, 12)],
            ]));

            return $new;
        });
    }

    /**
     * REVOKE: deliberate, auditable, fail-closed ownership.
     */
    public function revoke(string $sessionFingerprint, int $requesterUserId, string $reason = 'user request', string $revokedBy = 'self'): SecuritySession
    {
        return DB::transaction(function () use ($sessionFingerprint, $requesterUserId, $reason, $revokedBy): SecuritySession {
            /** @var SecuritySession|null $locked */
            $locked = SecuritySession::query()->lockForUpdate()->where('session_fingerprint', $sessionFingerprint)->first();

            if (! $locked instanceof SecuritySession) {
                throw UserSessionException::notFound($sessionFingerprint);
            }

            if ((int) $locked->user_id !== $requesterUserId && $revokedBy === 'self') {
                throw UserSessionException::ownedBySomeoneElse($sessionFingerprint, $requesterUserId);
            }

            if ($locked->status === UserSessionStatus::Revoked) {
                return $locked; // already revoked — the act replays free
            }

            if (! $locked->status->canTransitionTo(UserSessionStatus::Revoked)) {
                throw UserSessionException::revoked($sessionFingerprint);
            }

            $locked->status = UserSessionStatus::Revoked;
            $locked->revoked_at = now();
            $locked->revoked_by = $revokedBy;
            $locked->revocation_reason = substr($reason, 0, 128);
            $locked->save();

            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::SessionRevoked,
                'user_id' => $locked->user_id,
                'session_fingerprint' => $sessionFingerprint,
                'payload' => ['revoked_by' => $revokedBy],
            ]));

            return $locked->refresh();
        });
    }

    /**
     * Mark a session Suspicious: parked until the desk decides. The
     * fail-closed gate refuses it from that moment.
     */
    /**
     * SWEEP-ALL: every live seat for a user, system-revoked by name.
     * ADDITIVE batch-17 helper — no existing behaviour altered; each
     * row is revoked through the same row-level seat the desk already
     * uses, so audit anchors stay exactly-once per diagnosis.
     */
    public function revokeAllSessionsFor(int $userId, string $reason, string $revokedBy = 'system'): int
    {
        $count = 0;

        SecuritySession::query()
            ->where('user_id', $userId)
            ->whereIn('status', [UserSessionStatus::Active, UserSessionStatus::Suspicious])
            ->orderBy('id')
            ->get()
            ->each(function (SecuritySession $session) use ($userId, $reason, $revokedBy, &$count): void {
                $this->revoke($session->session_fingerprint, $userId, $reason, $revokedBy);
                $count++;
            });

        return $count;
    }

    public function markSuspicious(SecuritySession $session, string $evidence): SecuritySession
    {
        return DB::transaction(function () use ($session, $evidence): SecuritySession {
            /** @var SecuritySession $locked */
            $locked = SecuritySession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status !== UserSessionStatus::Suspicious) {
                if (! $locked->status->canTransitionTo(UserSessionStatus::Suspicious)) {
                    return $locked;
                }

                $locked->status = UserSessionStatus::Suspicious;
                $locked->save();

                $this->events->publish(SecurityEventData::fromInput([
                    'event_type' => SecurityEventType::SuspiciousAuthentication,
                    'user_id' => $locked->user_id,
                    'session_fingerprint' => (string) $locked->session_fingerprint,
                    'payload' => ['evidence_reference' => $evidence],
                ]));
            }

            return $locked->refresh();
        });
    }

    /**
     * Server-horizon expiry sweep. Replay-safe (the row IS the mark).
     */
    public function expireDue(int $limit = 200): int
    {
        return (int) SecuritySession::query()
            ->where('status', UserSessionStatus::Active->value)
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->update(['status' => UserSessionStatus::Expired->value]);
    }

    /**
     * @return Collection<int, SecuritySession>
     */
    public function activeSessionsFor(int $userId): Collection
    {
        return SecuritySession::query()
            ->where('user_id', $userId)
            ->where('status', UserSessionStatus::Active->value)
            ->where('expires_at', '>', now())
            ->orderByDesc('issued_at')
            ->get();
    }
}
