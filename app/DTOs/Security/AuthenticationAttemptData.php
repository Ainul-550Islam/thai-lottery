<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use App\Enums\AuthenticationMethod;
use App\Exceptions\AuthenticationSecurityException;

/**
 * Deterministic authentication-attempt identity: actor, method, and
 * the IP/device CONTEXT (never the cleartext identifier — the DTO
 * hashes it on arrival). The same attempt re-pronounced anywhere
 * lands on the same fingerprint.
 */
final class AuthenticationAttemptData
{
    public readonly AuthenticationMethod $method;

    public readonly string $identifierHash;

    public function __construct(
        public readonly ?int $userId,
        AuthenticationMethod|string $method,
        string $identifier,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly ?string $deviceFingerprint,
        public readonly \DateTimeInterface $attemptedAt,
    ) {
        $this->method = is_string($method) ? AuthenticationMethod::from($method) : $method;
        $this->identifierHash = hash('sha256', 'glo-id|'.mb_strtolower(trim($identifier)));
    }

    /**
     * @param  array{user_id?:int|null, method:AuthenticationMethod|string, identifier:string, ip_address?:string|null, user_agent?:string|null, device_fingerprint?:string|null, attempted_at?:\DateTimeInterface|null}  $data
     */
    public static function fromInput(array $data): self
    {
        $identifier = (string) ($data['identifier'] ?? '');

        if (trim($identifier) === '') {
            throw AuthenticationSecurityException::malformed('An attempt identifier is required');
        }

        $device = isset($data['device_fingerprint']) ? strtolower(trim((string) $data['device_fingerprint'])) : null;

        if ($device !== null && ! preg_match('/^[a-f0-9]{64}$/', $device)) {
            throw AuthenticationSecurityException::malformed('A device fingerprint must be 64 hex chars when supplied');
        }

        return new self(
            userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
            method: $data['method'] ?? null ?? throw AuthenticationSecurityException::malformed('An authentication method is required'),
            identifier: $identifier,
            ipAddress: isset($data['ip_address']) ? substr(trim((string) $data['ip_address']), 0, 45) : null,
            userAgent: isset($data['user_agent']) ? substr(trim((string) $data['user_agent']), 0, 255) : null,
            deviceFingerprint: $device,
            attemptedAt: $data['attempted_at'] ?? now(),
        );
    }

    /**
     * Same actor + method + context + moment = one attempt identity.
     */
    public function attemptFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-auth-attempt',
            (string) ($this->userId ?? 0),
            $this->method->value,
            $this->identifierHash,
            (string) $this->ipAddress,
            (string) $this->deviceFingerprint,
            $this->attemptedAt->format(DATE_ATOM),
        ]));
    }
}
