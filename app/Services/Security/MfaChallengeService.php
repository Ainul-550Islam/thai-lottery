<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\Security\MfaChallengeData;
use App\DTOs\Security\SecurityEventData;
use App\Enums\MfaChallengeStatus;
use App\Enums\SecurityEventType;
use App\Enums\SecurityRiskLevel;
use App\Events\MfaChallengeVerified;
use App\Exceptions\MfaChallengeException;
use App\Listeners\RecordMfaVerificationAudit;
use App\Models\MfaChallenge;
use Illuminate\Support\Facades\DB;

/**
 * MfaChallengeService — create / verify / expire / replay-protect MFA
 * challenges. Verification compares against the secret fingerprint
 * supplied at verify time by the caller's authenticator pipeline —
 * the row NEVER stores the secret, and the answer itself never
 * reaches the ledger (whose payload carries only booleans + counts).
 */
final class MfaChallengeService
{
    public function __construct(
        private readonly SecurityEventService $events,
        private readonly RecordMfaVerificationAudit $audit,
    ) {}

    /**
     * ISSUE: deterministic per (user, channel, secretfp, expires) —
     * re-issuing an identical challenge window lands the existing one.
     *
     * @return array{challenge: MfaChallenge, issued: bool}
     */
    public function issue(MfaChallengeData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var MfaChallenge|null $existing */
            $existing = MfaChallenge::query()->where('challenge_key', $data->challengeKey())->first();

            if ($existing instanceof MfaChallenge) {
                return ['challenge' => $existing, 'issued' => false];
            }

            // One live pending challenge per lane at a time: a new lane
            // pronunciation expired siblings in the same seat.
            MfaChallenge::query()
                ->where('user_id', $data->userId)
                ->where('channel', $data->channel)
                ->where('status', MfaChallengeStatus::Pending->value)
                ->update(['status' => MfaChallengeStatus::Expired->value]);

            $challenge = MfaChallenge::query()->create([
                'challenge_key' => $data->challengeKey(),
                'user_id' => $data->userId,
                'status' => MfaChallengeStatus::Pending,
                'channel' => $data->channel,
                'secret_fingerprint' => $data->secretFingerprint,
                'attempts' => 0,
                'issued_at' => $data->issuedAt,
                'expires_at' => $data->expiresAt,
            ]);

            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::MfaChallengeIssued,
                'user_id' => $data->userId,
                'payload' => ['challenge_key' => substr($challenge->challenge_key, 0, 12), 'channel' => $challenge->channel],
                'occurred_at' => $data->issuedAt,
            ]));

            return ['challenge' => $challenge, 'issued' => true];
        });
    }

    /**
     * VERIFY: the secret matcher is a caller-supplied pure predicate
     * so the answer never enters this ledger. Replay-protected: once
     * Verified, Locked or Expired, no second pronouncement lands.
     *
     * @param  callable(string $secretFingerprint): bool  $predicate
     */
    public function verify(string $challengeKey, callable $predicate): MfaChallenge
    {
        $outcome = DB::transaction(function () use ($challengeKey, $predicate): array {
            /** @var MfaChallenge|null $locked */
            $locked = MfaChallenge::query()->lockForUpdate()->where('challenge_key', $challengeKey)->first();

            if (! $locked instanceof MfaChallenge) {
                throw MfaChallengeException::notFound($challengeKey);
            }

            if ($locked->status === MfaChallengeStatus::Verified) {
                return ['challenge' => $locked, 'kind' => 'replay'];
            }

            if ($locked->status === MfaChallengeStatus::Locked) {
                throw MfaChallengeException::locked($challengeKey);
            }

            if ($locked->status === MfaChallengeStatus::Expired || $locked->isPastHorizon()) {
                $locked->status = MfaChallengeStatus::Expired;
                $locked->save();

                throw MfaChallengeException::expired($challengeKey);
            }

            if (! $locked->status->acceptsVerification()) {
                throw MfaChallengeException::replay($challengeKey);
            }

            $matched = (bool) $predicate((string) $locked->secret_fingerprint);

            if ($matched) {
                $locked->status = MfaChallengeStatus::Verified;
                $locked->verified_at = now();
                $locked->save();

                return ['challenge' => $locked->refresh(), 'kind' => 'verified'];
            }

            $locked->attempts++;
            $locked->status = MfaChallengeStatus::Failed;

            if ($locked->attempts >= MfaChallengeException::MAX_ATTEMPTS) {
                $locked->status = MfaChallengeStatus::Locked;
                $locked->locked_at = now();
            }

            $locked->save();

            return ['challenge' => $locked->refresh(), 'kind' => 'failed', 'attempts_left' => MfaChallengeException::MAX_ATTEMPTS - $locked->attempts];
        });

        $challenge = $outcome['challenge'];

        if ($outcome['kind'] === 'verified') {
            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::MfaVerified,
                'user_id' => $challenge->user_id,
                'payload' => ['challenge_key' => substr($challengeKey, 0, 12), 'channel' => $challenge->channel],
            ]));
            $this->audit->from($challenge, true);
            event(new MfaChallengeVerified($challenge, (string) $challenge->verified_at?->toIso8601String()));

            return $challenge;
        }

        if ($outcome['kind'] === 'failed') {
            $event = $challenge->status === MfaChallengeStatus::Locked
                ? SecurityEventType::MfaLocked
                : SecurityEventType::MfaFailed;

            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => $event,
                'user_id' => $challenge->user_id,
                'risk_level' => $challenge->status === MfaChallengeStatus::Locked ? SecurityRiskLevel::High : SecurityRiskLevel::Medium,
                'payload' => ['challenge_key' => substr($challengeKey, 0, 12), 'attempts' => $challenge->attempts],
            ]));
            $this->audit->from($challenge, false);

            if ($challenge->status === MfaChallengeStatus::Locked) {
                throw MfaChallengeException::locked($challengeKey);
            }

            throw MfaChallengeException::verificationFailed($challengeKey, (int) ($outcome['attempts_left'] ?? 0));
        }

        return $challenge;
    }

    /**
     * Expire challenges past their server-horizon. Replay-safe.
     */
    public function expireDue(int $limit = 200): int
    {
        $expired = MfaChallenge::query()
            ->whereIn('status', [MfaChallengeStatus::Pending->value, MfaChallengeStatus::Failed->value])
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->update(['status' => MfaChallengeStatus::Expired->value]);

        return (int) $expired;
    }

    public function currentPendingFor(int $userId, ?string $channel = null): ?MfaChallenge
    {
        return MfaChallenge::query()
            ->where('user_id', $userId)
            ->where('status', MfaChallengeStatus::Pending->value)
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->first();
    }
}
