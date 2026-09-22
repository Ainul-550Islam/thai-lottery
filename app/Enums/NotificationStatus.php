<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * NotificationStatus — the notification lifecycle.
 *
 * Pending → Queued → Sent → Delivered; Failed for retryable
 * provider refusals; Suppressed when policy silences it; Expired
 * when the server-horizon ran before delivery. Delivered, Expired
 * and Suppressed are terminal — nothing re-opens them.
 */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Queued, self::Suppressed, self::Expired],
            self::Queued => [self::Sent, self::Failed, self::Suppressed, self::Expired],
            self::Sent => [self::Delivered, self::Failed, self::Expired],
            self::Failed => [self::Queued, self::Expired],
            self::Delivered, self::Suppressed, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isDeliverable(): bool
    {
        return ! $this->isTerminal() && $this !== self::Sent;
    }
}
