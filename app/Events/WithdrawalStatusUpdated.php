<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Withdrawal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a withdrawal's status changes.
 */
final class WithdrawalStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Withdrawal $withdrawal,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->withdrawal->user_id),
            new PrivateChannel('user.'.$this->withdrawal->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'withdrawal.status.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'withdrawal_id' => (int) $this->withdrawal->id,
            'reference_number' => (string) $this->withdrawal->reference_number,
            'status' => $this->withdrawal->status?->value,
            'amount' => (string) $this->withdrawal->amount,
            'fee' => (string) $this->withdrawal->fee,
            'net_amount' => (string) $this->withdrawal->net_amount,
            'currency' => $this->withdrawal->currency?->value,
            'method' => $this->withdrawal->method?->value,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
