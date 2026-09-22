<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * NotificationDeliveryException — delivery/retry/provider-attempt
 * failures with stable desk codes.
 */
final class NotificationDeliveryException extends RuntimeException
{
    public const RETRY_CEILING = 'NOTIFDEL_RETRY_CEILING';
    public const TERMINAL_REPEAT = 'NOTIFDEL_TERMINAL_REPEAT';
    public const ATTEMPT_FORK = 'NOTIFDEL_ATTEMPT_FORK';
    public const PROVIDER_REFUSAL = 'NOTIFDEL_PROVIDER_REFUSAL';
    public const INVALID_RECEIPT = 'NOTIFDEL_INVALID_RECEIPT';

    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Notification delivery refused: '.$message);
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

    public static function retryCeiling(int $notificationId, int $max): self
    {
        return new self(self::RETRY_CEILING, sprintf('notification [%d] reached the %d-attempt ceiling', $notificationId, $max), ['notification_id' => $notificationId, 'max_attempts' => $max]);
    }

    public static function terminalRepeat(int $notificationId): self
    {
        return new self(self::TERMINAL_REPEAT, sprintf('notification [%d] is terminal; no further delivery may be attempted', $notificationId), ['notification_id' => $notificationId]);
    }

    public static function attemptFork(string $identity): self
    {
        return new self(self::ATTEMPT_FORK, sprintf('attempt [%s] exists under different facts', substr($identity, 0, 12)), ['attempt_identity' => $identity]);
    }

    public static function providerRefusal(string $channel, string $reason): self
    {
        return new self(self::PROVIDER_REFUSAL, sprintf('provider on channel [%s] refused: %s', $channel, substr($reason, 0, 64)), ['channel' => $channel, 'reason' => $reason]);
    }

    public static function invalidReceipt(string $reason): self
    {
        return new self(self::INVALID_RECEIPT, $reason, ['reason' => $reason]);
    }
}
