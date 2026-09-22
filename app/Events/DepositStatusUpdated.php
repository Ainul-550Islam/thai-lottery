<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Deposit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a deposit's status changes.
 */
final class DepositStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Deposit $deposit,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->deposit->user_id),
            new PrivateChannel('user.'.$this->deposit->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deposit.status.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'deposit_id' => (int) $this->deposit->id,
            'reference_number' => (string) $this->deposit->reference_number,
            'status' => $this->deposit->status?->value,
            'amount' => (string) $this->deposit->amount,
            'fee' => (string) $this->deposit->fee,
            'net_amount' => (string) $this->deposit->net_amount,
            'currency' => $this->deposit->currency?->value,
            'method' => $this->deposit->method?->value,
            'confirmed_at' => $this->deposit->confirmed_at?->toIso8601String(),
            'failed_at' => $this->deposit->failed_at?->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
