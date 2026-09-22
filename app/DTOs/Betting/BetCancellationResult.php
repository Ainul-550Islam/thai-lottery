<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

use App\Enums\BetCancellationReason;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\Ticket;

/**
 * The committed outcome of a successful bet cancellation.
 *
 * WHY THIS OBJECT ONLY EVER DESCRIBES SUCCESS
 * A cancellation either commits completely or throws. Holding this object means
 * the bet is Cancelled, the risk reservation was released, the wallet refund
 * committed, and the ledger posting balanced — inside ONE transaction. Failure is
 * carried by exceptions, never by a flag here.
 *
 * REPLAY DISTINCTION
 * isReplay() reports that the bet was ALREADY cancelled when this request arrived
 * and this request changed nothing (idempotent retry). The refund reported is the
 * original one; the money moved once, during the first request.
 */
final readonly class BetCancellationResult
{
    public function __construct(
        public Bet $bet,
        public ?Ticket $ticket,
        public FinancialTransaction $refund,
        public BetCancellationReason $reason,
        public string $refundAmount,
        public string $currency,
        public bool $replayed,
    ) {
    }

    public function isReplay(): bool
    {
        return $this->replayed;
    }

    public function reason(): BetCancellationReason
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bet_id' => (int) $this->bet->getKey(),
            'bet_number' => (string) $this->bet->bet_number,
            'status' => $this->bet->status->value,
            'reason' => $this->reason->value,
            'refund_amount' => $this->refundAmount,
            'currency' => $this->currency,
            'refund_transaction_id' => (int) $this->refund->getKey(),
            'refund_reference' => $this->refund->reference_number,
            'ticket_id' => $this->ticket === null ? null : (int) $this->ticket->getKey(),
            'ticket_status' => $this->ticket?->status->value,
            'cancelled_at' => $this->bet->cancelled_at?->toIso8601String(),
            'replayed' => $this->replayed,
        ];
    }
}
