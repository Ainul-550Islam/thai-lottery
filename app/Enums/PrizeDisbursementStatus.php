<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Claim-to-payment settlement lifecycle for ONE disbursement row.
 *
 *   Pending ──▶ Reserved ──▶ Disbursed ──▶ Reversed
 *      │           │            │
 *      │           │            └──▶ Failed
 *      │           └──▶ Failed ────┘
 *      └──▶ Failed
 *
 * SEMANTICS
 * - Pending: the settlement row exists; no money lane touched yet.
 * - Reserved: funds marked against the payout — bcmath conservation
 *   begins from here, not at Disbursed.
 * - Disbursed: final settlement reached; the payout's own lifecycle
 *   moves into the completed domain.
 * - Failed: provider / driver refusal — row pronounced Failed forever
 *   (a retry is a NEW reservation, never a silent rewrite of this one).
 * - Reversed: an operator-voiced reversal of an ALREADY disbursed row —
 *   always pronounced, never the quiet undo.
 *
 * TERMINAL: Reversed, Failed.
 */
enum PrizeDisbursementStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Disbursed = 'disbursed';
    case Reversed = 'reversed';
    case Failed = 'failed';

    /**
     * @return array<int, PrizeDisbursementStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Reserved, self::Failed],
            self::Reserved => [self::Disbursed, self::Failed],
            self::Disbursed => [self::Reversed, self::Failed],
            self::Reversed => [],
            self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Reversed || $this === self::Failed;
    }

    /**
     * Is this row's amount currently HELD on the payout ledger?
     */
    public function occupiesLedger(): bool
    {
        return $this === self::Reserved || $this === self::Disbursed;
    }
}
