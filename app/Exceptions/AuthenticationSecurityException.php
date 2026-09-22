<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * AuthenticationSecurityException — authentication-lane refusals with
 * stable machine-readable codes. The PUBLIC error seen by clients
 * remains the single `unauthenticated`; the desk code travels in the
 * exception and the audit lane only.
 */
final class AuthenticationSecurityException extends RuntimeException
{
    public const MALFORMED = 'AUTHSEC_MALFORMED';

    public const LOCKED_OUT = 'AUTHSEC_LOCKED_OUT';

    public const UNSUPPORTED_METHOD = 'AUTHSEC_UNSUPPORTED_METHOD';

    public const ACCOUNT_STATE_REFUSAL = 'AUTHSEC_ACCOUNT_STATE_REFUSAL';

    public const RATE_LIMITED = 'AUTHSEC_RATE_LIMITED';

    public const SUSPICIOUS = 'AUTHSEC_SUSPICIOUS';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Authentication refused: '.$message);
    }

    public function errorCode(): string
    {
        return $this->deskCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public static function malformed(string $reason): self
    {
        return new self(self::MALFORMED, $reason, ['reason' => $reason]);
    }

    public static function lockedOut(string $identifierHash, string $untilIso): self
    {
        return new self(self::LOCKED_OUT, sprintf(
            'identifier [%s] is locked out of further attempts until %s', substr($identifierHash, 0, 12), $untilIso,
        ), ['identifier_hash' => $identifierHash, 'until' => $untilIso]);
    }

    public static function unsupportedMethod(string $method): self
    {
        return new self(self::UNSUPPORTED_METHOD, sprintf(
            'method [%s] is not supported by this gate', $method,
        ), ['method' => $method]);
    }

    public static function accountState(int $userId, string $status): self
    {
        return new self(self::ACCOUNT_STATE_REFUSAL, sprintf(
            'user [%d] stands in status [%s]', $userId, $status,
        ), ['user_id' => $userId, 'status' => $status]);
    }

    public static function rateLimited(string $identifierHash, int $retryAfterSeconds): self
    {
        return new self(self::RATE_LIMITED, sprintf(
            'identifier [%s] hit the attempt ceiling; retry in %d seconds', substr($identifierHash, 0, 12), $retryAfterSeconds,
        ), ['identifier_hash' => $identifierHash, 'retry_after_seconds' => $retryAfterSeconds]);
    }

    public static function suspicious(string $eventFingerprint): self
    {
        return new self(self::SUSPICIOUS, sprintf(
            'attempt is parked for review as suspicious [%s]', substr($eventFingerprint, 0, 12),
        ), ['event_fingerprint' => $eventFingerprint]);
    }
}
