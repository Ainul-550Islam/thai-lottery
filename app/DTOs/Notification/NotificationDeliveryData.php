<?php

declare(strict_types=1);

namespace App\DTOs\Notification;

use App\Exceptions\NotificationDeliveryException;

/**
 * Provider delivery attempt data: attempt number + correlation
 * identity. The identity ties (notification, attempt #) so provider
 * replays of THE SAME attempt land exactly-once.
 */
final class NotificationDeliveryData
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        public readonly int $notificationId,
        public readonly string $channel,
        public readonly int $attemptNumber,
        public readonly ?string $providerReference = null,
    ) {
    }

    /**
     * @param array{notification_id:int, channel:string, attempt_number:int, provider_reference?:string|null} $data
     */
    public static function forAttempt(array $data): self
    {
        $attemptNumber = (int) ($data['attempt_number'] ?? 1);

        if ($attemptNumber < 1 || $attemptNumber > self::MAX_ATTEMPTS) {
            throw NotificationDeliveryException::retryCeiling((int) ($data['notification_id'] ?? 0), self::MAX_ATTEMPTS);
        }

        return new self(
            notificationId: (int) ($data['notification_id'] ?? 0),
            channel: strtolower(trim((string) ($data['channel'] ?? 'in_app'))),
            attemptNumber: $attemptNumber,
            providerReference: isset($data['provider_reference']) ? substr((string) $data['provider_reference'], 0, 128) : null,
        );
    }

    /**
     * Deterministic identity of THE attempt.
     */
    public function attemptIdentity(): string
    {
        return hash('sha256', implode('|', [
            'glo-notif-attempt', (string) $this->notificationId, $this->channel, (string) $this->attemptNumber,
        ]));
    }
}
