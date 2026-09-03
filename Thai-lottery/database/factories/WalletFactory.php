<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Wallet>
 */
class WalletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => WalletType::Primary,
            'status' => WalletStatus::Active,
            'currency' => Currency::THB,
            'balance' => '0.00',
            'locked_balance' => '0.00',
            'total_deposited' => '0.00',
            'total_withdrawn' => '0.00',
            'total_wagered' => '0.00',
            'total_won' => '0.00',
        ];
    }

    public function withBalance(string $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'balance' => $amount,
        ]);
    }

    public function frozen(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WalletStatus::Frozen,
            'locked_at' => now(),
            'locked_reason' => 'Compliance review',
        ]);
    }
}
