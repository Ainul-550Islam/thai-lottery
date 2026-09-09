<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Bet;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a bet has been successfully placed.
 */
final class BetPlaced implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Bet $bet,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->bet->user_id),
            new PrivateChannel('user.'.$this->bet->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bet.placed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'bet_id' => (int) $this->bet->id,
            'uuid' => (string) $this->bet->uuid,
            'bet_number' => (string) $this->bet->bet_number,
            'ticket_id' => (int) $this->bet->ticket_id,
            'draw_id' => (int) $this->bet->draw_id,
            'type' => $this->bet->type?->value,
            'total_amount' => (string) $this->bet->total_amount,
            'potential_payout' => (string) $this->bet->potential_payout,
            'status' => $this->bet->status?->value,
            'placed_at' => $this->bet->placed_at?->toIso8601String(),
        ];
    }
}
