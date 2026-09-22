<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Official-result certification lifecycle.
 *
 *   Draft ──▶ PendingReview ──▶ Certified ──▶ Published ──▶ Superseded
 *      │            │               ｜           ｜
 *      └────────────┴────────────────┴───────────┘
 *                   (no other transitions)
 *
 * SEMANTICS
 * - Draft: a certification conversation exists but has not yet been
 *   pronounced on. Nothing about it is evidence.
 * - PendingReview: evidence gathered, review gate open.
 * - Certified: the house's certifier has signed the result; the paper may
 *   now stand at exercises. Certified IS a terminal-admitting state.
 * - Published: consumption has rotated this certification onto the
 *   public board. Driven by the publication lane, never directly.
 * - Superseded: a later certification for the same draw (corrected
 *   paper) has taken over; the old row is history forever.
 *
 * TERMINAL: Superseded. Published may still be superseded (a withdrawn
 * wrong result). Certified may be published or superseded.
 */
enum DrawCertificationStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Certified = 'certified';
    case Published = 'published';
    case Superseded = 'superseded';

    /**
     * The closed transition map. Anything not listed is not a conversation
     * this lane holds — refused rather than silently rewritten.
     *
     * @return array<int, DrawCertificationStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Certified],
            self::PendingReview => [self::Certified, self::Superseded],
            self::Certified => [self::Published, self::Superseded],
            self::Published => [self::Superseded],
            self::Superseded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Superseded;
    }

    /**
     * May this certification stand behind PUBLICATION? Certified paper,
     * plus Published paper whose board row came down (a retracted board
     * re-publishes as the next version under the SAME certified truth —
     * requiring a fresh certification for a board re-spin would be a
     * ceremony with no new evidence). Draft/PendingReview/Superseded may
     * never stand behind the board.
     */
    public function isPublishable(): bool
    {
        return $this === self::Certified || $this === self::Published;
    }

    /**
     * May this certification be SUPERSEDED out from under the board?
     */
    public function maySupersede(): bool
    {
        return $this !== self::Superseded;
    }
}
