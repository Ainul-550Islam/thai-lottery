<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Security\ResponsibleGamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class ResponsibleGamingTest extends TestCase
{
    use RefreshDatabase;

    private User $player;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->player = User::factory()->create(['status' => UserStatus::Active]);
        $this->token = $this->player->createToken('test_rg_token')->plainTextToken;
    }

    public function test_player_can_fetch_and_set_responsible_gaming_limits(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/responsible-gaming');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'limits' => [
                        'is_self_excluded' => false,
                    ],
                ],
            ]);

        $updateRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/responsible-gaming/limits', [
                'daily_deposit_limit' => '5000.00',
                'single_bet_limit' => '1000.00',
            ]);

        $updateRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'daily_deposit_limit' => '5000.00',
                    'single_bet_limit' => '1000.00',
                ],
            ]);
    }

    public function test_player_can_activate_self_exclusion(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/responsible-gaming/self-exclude', [
                'days' => 30,
                'reason' => 'Taking a break.',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_self_excluded' => true,
                ],
            ]);

        $this->player->refresh();
        $this->assertTrue($this->player->isSelfExcluded());
        $this->assertFalse($this->player->canTransact());
    }

    public function test_responsible_gaming_service_enforces_bet_limit(): void
    {
        $service = app(ResponsibleGamingService::class);
        $service->setLimits($this->player, singleBetLimit: '500.00');

        // Within limit: passes
        $service->assertBetAllowed($this->player, '500.00');

        // Exceeds limit: throws
        $this->expectException(InvalidArgumentException::class);
        $service->assertBetAllowed($this->player, '500.01');
    }

    public function test_self_excluded_player_cannot_place_bets(): void
    {
        $service = app(ResponsibleGamingService::class);
        $service->selfExclude($this->player, 7);

        $this->expectException(InvalidArgumentException::class);
        $service->assertBetAllowed($this->player, '10.00');
    }
}
