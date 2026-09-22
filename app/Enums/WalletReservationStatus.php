<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Money reservation lifecycle.
 *
 *   Pending ──▶ Reserved ──▶ Consumed
 *      │            │
 *      │            ├──▶ Released
 *      └──▶ Expired┘
 *
 * SEMANTICS
 * - Pending: the row exists; mechanics haven't engaged yet.
 * - Reserved: money is HELD on the wallet (locked_balance rose by
 *   exactly the reservation amount through the wallet's own hold lane).
 * - Consumed: the spend happened — the wallet's hold was consumed and
 *   the money followed its purpose's lane.
 * - Released: given back to available balance, pronouncedly (strict —
 *   exactly the amount, exactly once).
 * - Expired: the hold outlived its horizon; the sweeper released it
 *   under a wall-clock fact, never by guess.
 *
 * TERMINAL: Consumed, Released, Expired. Rows are one-attempts: a
 * retried ask makes a new reservation conversation, never reopens a
 * dead one.
 */
enum WalletReservationStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';
    case Expired = 'expired';

    /**
     * @return array<int, WalletReservationStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Reserved, self::Expired],
            self::Reserved => [self::Consumed, self::Released, self::Expired],
            self::Consumed => [],
            self::Released => [],
            self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Consumed || $this === self::Released || $this === self::Expired;
    }

    /**
     * Does this row currently hold money on the wallet?
     */
    public function occupiesWallet(): bool
    {
        return $this === self::Reserved;
    }
}
