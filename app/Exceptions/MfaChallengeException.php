<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * MfaChallengeException — stable MFA lifecycle/verification refusals.
 */
final class MfaChallengeException extends RuntimeException
{
    public const MALFORMED = 'MFA_MALFORMED';

    public const EXPIRED = 'MFA_EXPIRED';

    public const LOCKED = 'MFA_LOCKED';

    public const VERIFICATION_FAILED = 'MFA_VERIFICATION_FAILED';

    public const REPLAY = 'MFA_REPLAY';

    public const NOT_FOUND = 'MFA_NOT_FOUND';

    /** Attempts before the challenge locks (fail-closed ballot). */
    public const MAX_ATTEMPTS = 5;

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('MFA challenge refused: '.$message);
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

    public static function expired(string $challengeKey): self
    {
        return new self(self::EXPIRED, sprintf(
            'challenge [%s] is past its server-horizon', substr($challengeKey, 0, 12),
        ), ['challenge_key' => $challengeKey]);
    }

    public static function locked(string $challengeKey): self
    {
        return new self(self::LOCKED, sprintf(
            'challenge [%s] locked after %d failed answers', substr($challengeKey, 0, 12), self::MAX_ATTEMPTS,
        ), ['challenge_key' => $challengeKey, 'max_attempts' => self::MAX_ATTEMPTS]);
    }

    public static function verificationFailed(string $challengeKey, int $attemptsLeft): self
    {
        return new self(self::VERIFICATION_FAILED, sprintf(
            'answer did not match; %d attempts remain on challenge [%s]', $attemptsLeft, substr($challengeKey, 0, 12),
        ), ['challenge_key' => $challengeKey, 'attempts_left' => $attemptsLeft]);
    }

    public static function replay(string $challengeKey): self
    {
        return new self(self::REPLAY, sprintf(
            'challenge [%s] was already pronounced; a second pronouncement is refused', substr($challengeKey, 0, 12),
        ), ['challenge_key' => $challengeKey]);
    }

    public static function notFound(string $challengeKey): self
    {
        return new self(self::NOT_FOUND, sprintf(
            'challenge [%s] is unknown to this gate', substr($challengeKey, 0, 12),
        ), ['challenge_key' => $challengeKey]);
    }
}
