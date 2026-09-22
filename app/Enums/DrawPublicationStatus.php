<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The public visibility lifecycle of a draw result.
 *
 *   Pending ──▶ Published ──▶ Retracted ──▶ RePublished
 *     │              │             │             │
 *     └──────────────┴─────────────┘        (final)
 *
 * SEMANTICS
 * - Pending: the publication row exists (created with the certification)
 *   but carries no numbers the public may act on.
 * - Published: live on the board; the fingerprint ON the row is the only
 *   answer the public surface may give.
 * - Retracted: withdrawn from the board; history, and never silently
 *   rotates back — re-publication is a NEW informed act (new version).
 * - RePublished: the row after a retracted one was revived by a fresh
 *   decision; terminal per-row (any further change is a new version
 *   row + this one retained for the board's audit reasons).
 *
 * TERMINAL: RePublished. Rows never die — they rotate.
 */
enum DrawPublicationStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Retracted = 'retracted';
    case RePublished = 'republished';

    /**
     * @return array<int, DrawPublicationStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Published],
            self::Published => [self::Retracted],
            self::Retracted => [self::RePublished],
            self::RePublished => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::RePublished;
    }

    /**
     * Is this row currently able to answer the public surface?
     */
    public function isPubliclyLive(): bool
    {
        return $this === self::Published || $this === self::RePublished;
    }

    /**
     * May this row be retracted from the board?
     */
    public function mayRetract(): bool
    {
        return $this === self::Published || $this === self::RePublished;
    }
}
