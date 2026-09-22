<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * UserSessionException — session ownership/expiry/revocation refusals.
 */
final class UserSessionException extends RuntimeException
{
    public const NOT_FOUND = 'SESSION.NOT.FOUND';

    public const INVALID = 'SESSION.INVALID';

    public const EXPIRED = 'SESSION.EXPIRED';

    public const REVOKED = 'SESSION.REVOKED';

    public const SUSPICIOUS = 'SESSION.SUSPICIOUS';

    public const OWNED_BYSOMEONE_ELSE = 'SESSION.OWNED_MISMATCH';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Session refused: '.$message);
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

    public static function notFound(string $fingerprint): self
    {
        return new self(self::NOT_FOUND, sprintf('session [%s] is unknown', substr($fingerprint, 0, 12)), ['session_fingerprint' => $fingerprint]);
    }

    public static function invalidSession(string $reason): self
    {
        return new self(self::INVALID, $reason, ['reason' => $reason]);
    }

    public static function expired(string $fingerprint): self
    {
        return new self(self::EXPIRED, sprintf('session [%s] is past its server-horizon', substr($fingerprint, 0, 12)), ['session_fingerprint' => $fingerprint]);
    }

    public static function revoked(string $fingerprint): self
    {
        return new self(self::REVOKED, sprintf('session [%s] was revoked by the desk', substr($fingerprint, 0, 12)), ['session_fingerprint' => $fingerprint]);
    }

    public static function suspicious(string $fingerprint): self
    {
        return new self(self::SUSPICIOUS, sprintf('session [%s] is parked as suspicious', substr($fingerprint, 0, 12)), ['session_fingerprint' => $fingerprint]);
    }

    public static function ownedBySomeoneElse(string $fingerprint, int $requesterId): self
    {
        return new self(self::OWNED_BYSOMEONE_ELSE, sprintf(
            'session [%s] does not belong to user [%d]', substr($fingerprint, 0, 12), $requesterId,
        ), ['session_fingerprint' => $fingerprint, 'requester_user_id' => $requesterId]);
    }
}
