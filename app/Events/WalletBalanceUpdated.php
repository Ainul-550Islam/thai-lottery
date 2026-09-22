<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Wallet;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a player's wallet balance changes.
 */
final class WalletBalanceUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Wallet $wallet,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->wallet->user_id),
            new PrivateChannel('user.'.$this->wallet->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'wallet.balance.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'wallet_id' => (int) $this->wallet->id,
            'user_id' => (int) $this->wallet->user_id,
            'currency' => $this->wallet->currency?->value,
            'balance' => (string) $this->wallet->balance,
            'locked_balance' => (string) $this->wallet->locked_balance,
            'available_balance' => (string) $this->wallet->getAvailableBalance(),
            'reason' => $this->reason,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
