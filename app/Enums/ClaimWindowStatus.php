<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The eligibility lifecycle of a prize CLAIM WINDOW — the time slice within
 * which a winner may assert their prize.
 *
 * WHERE IT LIVES
 * --------------
 * A win creates an OPEN window (GLO parity: by default 730 days after the
 * draw). The window NEVER closes early; it:
 *
 *   Open ──(time passes, no claim payment completes)──▶ Expired
 *     │                                                    │
 *     │   (claim paid / sweeper stamps)                      ▼
 *     └───────────────────────────(final)───────────▶ Closed
 *
 * OPEN:    a claim submitted now is lawful.
 * EXPIRED: the window's repository-side deadline has passed — money is
 *          lapsed and must stop presenting as an obligation, but the final
 *          ledger face-turn hasn't happened yet (the sweeper job writes it).
 * CLOSED:  terminal, by either of two roads: the prize was PAID within an
 *          open window (no more to assert), or the sweeper closed out the
 *          expired obligation. Both are audits, not deletions.
 *
 * The window's lifecycle is a function of the win + the clock; this enum
 * carries no arithmetic beyond its pure status helpers. ClaimWindowService
 * is the only writer of these transitions.
 */
enum ClaimWindowStatus: string
{
    case Open = 'open';
    case Expired = 'expired';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Expired => 'Expired',
            self::Closed => 'Closed',
        };
    }

    /**
     * Whether a claim may be submitted today against this window.
     */
    public function allowsClaim(): bool
    {
        return $this === self::Open;
    }

    /**
     * Whether the window may still transition further. Open and Expired can
     * still move (to Closed by payment, or Closed by sweeper); Closed is the
     * end of history.
     */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * Transitions the window may lawfully make out of this state.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Expired, self::Closed],
            self::Expired => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Badge color for admin surfaces, in Filament vocabulary.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'green',
            self::Expired => 'yellow',
            self::Closed => 'gray',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
