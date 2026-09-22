<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use App\Exceptions\MfaChallengeException;
use Illuminate\Support\Carbon;

/**
 * Challenge creation/verification data with the deterministic
 * challenge key: same (user, channel, secret, window) = one
 * challenge, replay-protected at the key rather than by luck.
 */
final class MfaChallengeData
{
    private const VALIDITY_MINUTES = 10;

    public function __construct(
        public readonly int $userId,
        public readonly string $channel,
        public readonly string $secretFingerprint,
        public readonly \DateTimeInterface $issuedAt,
        public readonly \DateTimeInterface $expiresAt,
    ) {}

    /**
     * @param  array{user_id:int, channel?:string, secret_fingerprint:string, issued_at?:\DateTimeInterface|null, expires_at?:\DateTimeInterface|null}  $data
     */
    public static function forIssuance(array $data): self
    {
        $secret = strtolower(trim((string) ($data['secret_fingerprint'] ?? '')));
        $channel = strtolower(trim((string) ($data['channel'] ?? 'totp')));
        $issuedAt = $data['issued_at'] ?? now();

        if (! preg_match('/^[a-f0-9]{64}$/', $secret)) {
            throw MfaChallengeException::malformed('A secret fingerprint (64 hex chars) is required');
        }

        if (! in_array($channel, ['totp', 'sms', 'email'], true)) {
            throw MfaChallengeException::malformed('Channel must be totp, sms or email');
        }

        $expiresAt = $data['expires_at']
            ?? Carbon::instance(new \DateTime($issuedAt->format(DATE_ATOM)))
                ->addMinutes(self::VALIDITY_MINUTES);

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            channel: $channel,
            secretFingerprint: $secret,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        );
    }

    /**
     * Deterministic identity over the challenge's immutable facts.
     */
    public function challengeKey(): string
    {
        return hash('sha256', implode('|', [
            'glo-mfa-challenge', (string) $this->userId, $this->channel,
            $this->secretFingerprint, $this->expiresAt->format(DATE_ATOM),
        ]));
    }
}
