<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * NotificationPreferenceException — preference ownership/state/
 * update failures.
 */
final class NotificationPreferenceException extends RuntimeException
{
    public const MALFORMED = 'NOTIFPREF_MALFORMED';
    public const MANDATORY_CANNOT_BE_SILENCED = 'NOTIFPREF_MANDATORY_CANNOT_BE_SILENCED';
    public const QUIET_HOURS_CONFLICT = 'NOTIFPREF_QUIET_HOURS_CONFLICT';
    public const NOT_FOUND = 'NOTIFPREF_NOT_FOUND';

    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Notification preference refused: '.$message);
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

    public static function mandatoryCannotBeSilenced(string $eventType): self
    {
        return new self(self::MANDATORY_CANNOT_BE_SILENCED, sprintf('event type [%s] is a mandatory notice; preferences may not silence it', $eventType), ['event_type' => $eventType]);
    }

    public static function notFound(int $userId, string $eventType): self
    {
        return new self(self::NOT_FOUND, sprintf('user [%d] carries no preference for [%s]', $userId, $eventType), ['user_id' => $userId, 'event_type' => $eventType]);
    }
}
