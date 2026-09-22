<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Draw;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a draw changes status (e.g. opens, closes, begins drawing, finishes).
 */
final class DrawStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Draw $draw,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('draws'),
            new Channel('draw.'.$this->draw->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'draw.status.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'draw_id' => (int) $this->draw->id,
            'draw_number' => (string) $this->draw->draw_number,
            'type' => $this->draw->type?->value,
            'status' => $this->draw->status?->value,
            'is_open' => $this->draw->isOpen(),
            'can_accept_bets' => $this->draw->canAcceptBets(),
            'scheduled_at' => $this->draw->scheduled_at?->toIso8601String(),
            'betting_open_at' => $this->draw->betting_open_at?->toIso8601String() ?? $this->draw->opened_at?->toIso8601String(),
            'betting_close_at' => $this->draw->betting_close_at?->toIso8601String() ?? $this->draw->closed_at?->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
