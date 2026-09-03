<?php

namespace Database\Factories;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Draw>
 */
class DrawFactory extends Factory
{
    public function definition(): array
    {
        return [
            'draw_number' => 'DRW-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'type' => DrawType::TwoD,
            'status' => DrawStatus::Scheduled,
            'scheduled_at' => now()->addHour(),
            'total_bets' => 0,
            'total_amount_wagered' => '0.00',
            'total_payout' => '0.00',
            'house_profit' => '0.00',
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DrawStatus::Open,
            'opened_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DrawStatus::Closed,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
        ]);
    }
}
