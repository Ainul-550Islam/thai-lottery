<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * NotificationException — stable notification-domain codes.
 */
final class NotificationException extends RuntimeException
{
    public const MALFORMED = 'NOTIF_MALFORMED';
    public const CHANNEL_UNAVAILABLE = 'NOTIF_CHANNEL_UNAVAILABLE';
    public const SUPPRESSED = 'NOTIF_SUPPRESSED';
    public const OWNERSHIP_MISMATCH = 'NOTIF_OWNERSHIP_MISMATCH';
    public const NOT_FOUND = 'NOTIF_NOT_FOUND';
    public const INVALID_TRANSITION = 'NOTIF_INVALID_TRANSITION';

    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Notification refused: '.$message);
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

    public static function channelUnavailable(string $channel): self
    {
        return new self(self::CHANNEL_UNAVAILABLE, sprintf('channel [%s] is not wired on this desk', $channel), ['channel' => $channel]);
    }

    public static function suppressed(string $fingerprint, string $reason): self
    {
        return new self(self::SUPPRESSED, sprintf('message [%s] suppressed: %s', substr($fingerprint, 0, 12), $reason), ['message_fingerprint' => $fingerprint, 'reason' => $reason]);
    }

    public static function ownershipMismatch(int $messageId, int $requesterId): self
    {
        return new self(self::OWNERSHIP_MISMATCH, sprintf('message [%d] does not belong to user [%d]', $messageId, $requesterId), ['message_id' => $messageId, 'requester_user_id' => $requesterId]);
    }

    public static function notFound(string $reference): self
    {
        return new self(self::NOT_FOUND, sprintf('notification [%s] is unknown', $reference), ['reference' => $reference]);
    }

    public static function invalidTransition(string $fingerprint, string $from, string $to): self
    {
        return new self(self::INVALID_TRANSITION, sprintf('message [%s] may not move %s → %s', substr($fingerprint, 0, 12), $from, $to), ['message_fingerprint' => $fingerprint, 'from' => $from, 'to' => $to]);
    }
}
