<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Winner notification lifecycle.
 *
 *   Pending ──▶ Queued ──▶ Sent ──▶ Acknowledged
 *                 │          │
 *                 │          └──▶ Failed ──▶ Queued (retry is a new
 *                 │                 │        pronounced decision)
 *                 └──▶ Failed ────┘
 *
 * SEMANTICS
 * - Pending: the notification row exists; the dispatcher hasn't spoken.
 * - Queued: handed to the delivery lane (queue entry minted).
 * - Sent: the provider accepted the hand-off. Not "read".
 * - Failed: the provider refused or the attempt timed out; retry is
 *   allowed and is NEVER a silent re-queue.
 * - Acknowledged: the claimant digitally touched the prize surface.
 *
 * TERMINAL: Acknowledged. Sent rows age (a long-unserved Sent says the
 * board may care); Failed rows wait for a retry.
 */
enum WinnerNotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Acknowledged = 'acknowledged';

    /**
     * @return array<int, WinnerNotificationStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Queued, self::Failed],
            self::Queued => [self::Sent, self::Failed],
            self::Sent => [self::Acknowledged, self::Failed],
            self::Failed => [self::Queued],
            self::Acknowledged => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Acknowledged;
    }

    /**
     * Is the duplicate-suppression window for dispatch STILL open?
     */
    public function admitsSendAttempt(): bool
    {
        return $this === self::Queued;
    }
}
