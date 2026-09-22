<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Winning-ticket → draw-prize matching lifecycle.
 *
 *   Unmatched ──▶ Matched ──▶ Verified
 *                    │
 *                    └──▶ Rejected
 *
 * SEMANTICS
 * - Unmatched: the (draw, ticket) pair exists in conversation but no
 *   tier matched yet — the default posture before/without evidence.
 * - Matched: a deterministic match was written by the court; amounts
 *   and tier already cross-checked against the RESULT lane at creation.
 * - Verified: a second pair of eyes (operator or the automated
 *   reconciliation lane) confirmed.
 * - Rejected: the match was later found wanting — pronounced forever,
 *   never silently erased.
 *
 * TERMINAL: Verified, Rejected.
 */
enum PrizeMatchStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /**
     * @return array<int, PrizeMatchStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Unmatched => [self::Matched, self::Rejected],
            self::Matched => [self::Verified, self::Rejected],
            self::Verified => [],
            self::Rejected => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Verified || $this === self::Rejected;
    }

    /**
     * Does this status still stand behind a live prize conversation?
     */
    public function isAdmissible(): bool
    {
        return $this === self::Matched || $this === self::Verified;
    }
}
