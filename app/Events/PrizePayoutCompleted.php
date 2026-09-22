<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a prize payout has fully completed: the payout row is
 * Completed, the linked financial transaction posted its balanced ledger
 * entries, and the winner's wallet shows the credited balance.
 *
 * LISTENERS DECIDE NOTHING
 * The event fires AFTER the money has landed, outside the settling
 * transaction. A listener must treat it as a notification (emails, SMS,
 * push, dashboards, reporting projections) and may never attempt to mutate
 * the payout, the wallet or the ledger from within the event handler — the
 * monetary truth is already fixed at dispatch time.
 *
 * PUBLIC PAYLOAD DISCIPLINE
 * The broadcast payload contains only what the winner's own surface needs.
 * It never includes ledger ids, internal account codes, the payee wallet id
 * or anyone else's ticket details, because the public channel reaches every
 * connected player of the draw.
 */
final class PrizePayoutCompleted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  string  $correlationId  The execution-context correlation id
     *                                 under which the payout landed, so a
     *                                 listener can join its own logs/audit
     *                                 onto the paying run without guessing.
     */
    public function __construct(
        public readonly Payout $payout,
        public readonly User $winner,
        public readonly string $correlationId,
    ) {}

    /**
     * The winner's private channel only: a prize is the player's own money,
     * not draw-level trivia for everyone else to see.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('user.'.(int) $this->winner->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payout.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'payout_id' => (int) $this->payout->id,
            'reference_number' => (string) $this->payout->reference_number,
            'draw_id' => (int) $this->payout->draw_id,
            'bet_id' => $this->payout->bet_id !== null ? (int) $this->payout->bet_id : null,
            'amount' => (string) $this->payout->amount,
            'currency' => $this->payout->currency->value,
            'paid_at' => $this->payout->processed_at?->toIso8601String(),
            'correlation_id' => $this->correlationId,
        ];
    }
}
