<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Compliance/settlement financial-hold lifecycle.
 *
 *   Active ──▶ Reviewed ──▶ Released
 *     │           │
 *     │           └──▶ Converted
 *     │           │
 *     │           └──▶ Expired
 *     └──▶ Expired
 *
 * SEMANTICS
 * - Active: hold sits on the wallet (locked); the reviewer hasn't
 *   spoken.
 * - Reviewed: a human inspected the source evidence; the hold waits
 *   for one specific verdict.
 * - Released: funds return to available as-is (innocent lane).
 * - Converted: the held amount became a FORMAL flow (adjustment
 *   debit through WalletService with ledger posting + evidence) — the
 *   hold is a staging lane, the flow is the legal trail.
 * - Expired: the hold outlived its lawful horizon; the review job
 *   pronounced itExpired BY EVIDENCE, and a reviewed expiry releases
 *   its funds, never strands money forever.
 *
 * TERMINAL: Released, Converted, Expired.
 */
enum FinancialHoldStatus: string
{
    case Active = 'active';
    case Reviewed = 'reviewed';
    case Released = 'released';
    case Converted = 'converted';
    case Expired = 'expired';

    /**
     * @return array<int, FinancialHoldStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Reviewed, self::Released, self::Expired],
            self::Reviewed => [self::Released, self::Converted, self::Expired],
            self::Released => [],
            self::Converted => [],
            self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Released || $this === self::Converted || $this === self::Expired;
    }

    /**
     * Does this hold currently occupy locked wallet money?
     */
    public function occupiesWallet(): bool
    {
        return $this === self::Active || $this === self::Reviewed;
    }
}
