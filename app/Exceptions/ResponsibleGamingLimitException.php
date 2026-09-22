<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * ResponsibleGamingLimitException — limit-lane refusals, each with a
 * stable machine-readable code (amount, conflicts, cooldown, forks).
 */
final class ResponsibleGamingLimitException extends RuntimeException
{
    public const MALFORMED = 'RGL_MALFORMED';

    public const INVALID_AMOUNT = 'RGL_INVALID_AMOUNT';

    public const LOWER_LIMIT_CONFLICT = 'RGL_LOWER_LIMIT_CONFLICT';

    public const COOLDOWN_VIOLATION = 'RGL_COOLDOWN_VIOLATION';

    public const DUPLICATE_VERSION = 'RGL_DUPLICATE_VERSION';

    public const INVALID_TRANSITION = 'RGL_INVALID_TRANSITION';

    public const ACTIVE_EXCLUSION = 'RGL_ACTIVE_EXCLUSION';

    public const NOT_FOUND = 'RGL_NOT_FOUND';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Responsible gaming limit refused: '.$message);
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

    public static function invalidAmount(string $amount): self
    {
        return new self(self::INVALID_AMOUNT, sprintf(
            'amount [%s] is not an exact positive decimal within band', $amount,
        ), ['amount' => $amount]);
    }

    public static function lowerLimitConflict(string $type, string $current, string $proposed): self
    {
        return new self(self::LOWER_LIMIT_CONFLICT, sprintf(
            'a pending stricter %s limit already stands (%s vs %s)', $type, $current, $proposed,
        ), ['limit_type' => $type, 'current' => $current, 'proposed' => $proposed]);
    }

    public static function cooldownViolation(string $type, string $untilIso): self
    {
        return new self(self::COOLDOWN_VIOLATION, sprintf(
            'a %s increase is cooling off until %s', $type, $untilIso,
        ), ['limit_type' => $type, 'until' => $untilIso]);
    }

    public static function duplicateVersion(string $limitKey): self
    {
        return new self(self::DUPLICATE_VERSION, sprintf(
            'limit version [%s] exists under different facts', substr($limitKey, 0, 12),
        ), ['limit_key' => $limitKey]);
    }

    public static function invalidTransition(string $limitKey, string $from, string $to): self
    {
        return new self(self::INVALID_TRANSITION, sprintf(
            'limit version [%s] may not move %s → %s', substr($limitKey, 0, 12), $from, $to,
        ), ['limit_key' => $limitKey, 'from' => $from, 'to' => $to]);
    }

    public static function activeExclusion(string $reference): self
    {
        return new self(self::ACTIVE_EXCLUSION, sprintf(
            'limits may not be lowered while exclusion [%s] stands active', $reference,
        ), ['reference' => $reference]);
    }

    public static function notFound(string $limitKey): self
    {
        return new self(self::NOT_FOUND, sprintf(
            'limit version [%s] is unknown to this desk', substr($limitKey, 0, 12),
        ), ['limit_key' => $limitKey]);
    }
}
