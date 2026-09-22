<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use App\Exceptions\UserSessionException;
use Illuminate\Support\Carbon;

/**
 * Session identity for issuance/rotation: fingerprint, device
 * binding, timestamps, and (for revocation) the revoking context.
 */
final class UserSessionData
{
    public const LIFETIME_MINUTES = 60 * 8; // eight hours, server-pronounced

    public function __construct(
        public readonly int $userId,
        public readonly ?string $deviceFingerprint,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly \DateTimeInterface $issuedAt,
        public readonly \DateTimeInterface $expiresAt,
    ) {}

    /**
     * @param  array{user_id:int, device_fingerprint?:string|null, ip_address?:string|null, user_agent?:string|null, issued_at?:\DateTimeInterface|null, expires_at?:\DateTimeInterface|null}  $data
     */
    public static function forIssuance(array $data): self
    {
        $issuedAt = $data['issued_at'] ?? now();
        $expiresAt = $data['expires_at']
            ?? Carbon::instance(new \DateTime($issuedAt->format(DATE_ATOM)))
                ->addMinutes(self::LIFETIME_MINUTES);

        if (Carbon::instance(new \DateTime($expiresAt->format(DATE_ATOM)))
            ->lessThanOrEqualTo(Carbon::instance(new \DateTime($issuedAt->format(DATE_ATOM))))) {
            throw UserSessionException::invalidSession('Session expiry must follow issuance');
        }

        $device = isset($data['device_fingerprint']) ? strtolower(trim((string) $data['device_fingerprint'])) : null;

        if ($device !== null && ! preg_match('/^[a-f0-9]{64}$/', $device)) {
            throw UserSessionException::invalidSession('A device fingerprint must be 64 hex chars when supplied');
        }

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            deviceFingerprint: $device,
            ipAddress: isset($data['ip_address']) ? substr(trim((string) $data['ip_address']), 0, 45) : null,
            userAgent: isset($data['user_agent']) ? substr(trim((string) $data['user_agent']), 0, 255) : null,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        );
    }

    /**
     * One (user, device, context, window) = one session identity. A
     * nonce issued by the server keeps two truly simultaneous logins
     * distinct while replays of THE SAME pronouncement land on one row.
     */
    public function sessionFingerprint(string $serverNonce): string
    {
        return hash('sha256', implode('|', [
            'glo-sec-session', (string) $this->userId, (string) $this->deviceFingerprint,
            (string) $this->ipAddress, $this->issuedAt->format(DATE_ATOM), $serverNonce,
        ]));
    }
}
