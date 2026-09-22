<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * SelfExclusionException — fail-closed self-exclusion refusals.
 * Every refusal carries a stable machine-readable CODE, keyed to the
 * desk reference so operators can find the row it belongs to.
 */
final class SelfExclusionException extends RuntimeException
{
    public const MALFORMED = 'SE_MALFORMED';

    public const INVALID_DURATION = 'SE_INVALID_DURATION';

    public const ACTIVE_EXCLUSION = 'SE_ACTIVE_EXCLUSION';

    public const FORBIDDEN_CANCELLATION = 'SE_FORBIDDEN_CANCELLATION';

    public const DUPLICATE_REQUEST = 'SE_DUPLICATE_REQUEST';

    public const REQUEST_FORK = 'SE_REQUEST_FORK';

    public const INVALID_TRANSITION = 'SE_INVALID_TRANSITION';

    public const NOT_FOUND = 'SE_NOT_FOUND';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Self-exclusion refused: '.$message);
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

    public static function invalidDuration(string $reason): self
    {
        return new self(self::INVALID_DURATION, $reason, ['reason' => $reason]);
    }

    public static function activeExclusion(int $userId, string $until): self
    {
        return new self(self::ACTIVE_EXCLUSION, sprintf(
            'user [%d] stands under an active exclusion until %s', $userId, $until,
        ), ['user_id' => $userId, 'excluded_until' => $until]);
    }

    public static function forbiddenCancellation(string $reference, string $status): self
    {
        return new self(self::FORBIDDEN_CANCELLATION, sprintf(
            'exclusion [%s] in status [%s] cannot be cancelled', $reference, $status,
        ), ['reference' => $reference, 'status' => $status]);
    }

    public static function duplicate(string $reference): self
    {
        return new self(self::DUPLICATE_REQUEST, sprintf(
            'request [%s] was already pronounced under different facts', $reference,
        ), ['reference' => $reference]);
    }

    public static function requestFork(string $reference): self
    {
        return new self(self::REQUEST_FORK, sprintf(
            'request [%s] collides with the same fingerprint but different facts', $reference,
        ), ['reference' => $reference]);
    }

    public static function invalidTransition(string $reference, string $from, string $to): self
    {
        return new self(self::INVALID_TRANSITION, sprintf(
            'exclusion [%s] may not move %s → %s', $reference, $from, $to,
        ), ['reference' => $reference, 'from' => $from, 'to' => $to]);
    }

    public static function notFound(string $reference): self
    {
        return new self(self::NOT_FOUND, sprintf('exclusion [%s] is unknown to this desk', $reference), ['reference' => $reference]);
    }
}
