<?php

declare(strict_types=1);

namespace Tests\Feature\Player;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Enums\PaymentMethod;
use App\Enums\TicketStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserStatus;
use App\Enums\WalletType;
use App\Events\BetPlaced;
use App\Events\DepositStatusUpdated;
use App\Events\DrawResultPublished;
use App\Events\DrawStatusUpdated;
use App\Events\WalletBalanceUpdated;
use App\Events\WithdrawalStatusUpdated;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\NumberLimit;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WinningNumber;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Phase 5.3.8: Comprehensive Player-Facing API, Web App & Real-Time Experience Test Suite.
 */
final class PlayerExperienceComprehensiveTest extends TestCase
{
    use DatabaseTruncation;

    private User $player;
    private Wallet $wallet;
    private string $token;
    private PersonalAccessToken $tokenModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedChartOfAccounts();

        $this->player = User::factory()->create([
            'name' => 'Somchai Jaidee',
            'username' => 'somchai99',
            'email' => 'somchai@example.com',
            'password' => Hash::make('Secret123!'),
            'status' => UserStatus::Active,
        ]);

        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->player->id,
            'currency' => Currency::THB,
            'type' => WalletType::Primary,
            'balance' => '5000.00',
            'locked_balance' => '0.00',
        ]);

        $created = $this->player->createToken('player-mobile');
        $this->token = $created->plainTextToken;
        $this->tokenModel = $created->accessToken;
    }

    private function seedChartOfAccounts(): void
    {
        $accounts = [
            ['1000', 'System Cash', 'asset'],
            ['2000', 'Player Liability', 'liability'],
            ['4000', 'Bet Revenue', 'revenue'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'currency' => Currency::primary()->value,
                    'is_active' => true,
                ],
            );
        }
    }

    private function ensureLimit(Draw $draw, string $market, string $rawNumber): void
    {
        $definition = config('lottery.markets.'.$market);

        if (! is_array($definition)) {
            return;
        }

        $betType = BetType::tryFrom((string) ($definition['bet_type'] ?? ''));
        $digits = (int) ($definition['digits'] ?? 0);

        if ($betType === null || $digits < 1 || ! ctype_digit($rawNumber) || strlen($rawNumber) > $digits) {
            return;
        }

        $number = str_pad($rawNumber, $digits, '0', STR_PAD_LEFT);

        $limit = NumberLimit::query()
            ->where('draw_id', (int) $draw->getKey())
            ->where('bet_type', $betType->value)
            ->where('number', $number)
            ->first();

        if (! $limit instanceof NumberLimit) {
            $limit = new NumberLimit();
            $limit->draw_id = (int) $draw->getKey();
            $limit->bet_type = $betType;
            $limit->number = $number;
            $limit->max_amount = '100000.00';
            $limit->current_amount = '0.00';
            $limit->maximum_payout_exposure = null;
            $limit->current_payout_exposure = '0.00';
            $limit->status = LimitStatus::Active;
            $limit->save();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Section 1: Authentication and Session Security
    |--------------------------------------------------------------------------
    */

    public function test_01_unauthenticated_api_request_rejected(): void
    {
        $response = $this->getJson('/api/v1/draws');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'unauthenticated'],
            ]);
    }

    public function test_02_authenticated_player_can_fetch_identity(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $this->player->id,
                        'username' => 'somchai99',
                        'email' => 'somchai@example.com',
                    ],
                ],
            ]);
    }

    public function test_03_suspended_player_is_blocked_by_active_middleware(): void
    {
        $this->player->status = UserStatus::Suspended;
        $this->player->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/draws');

        $response->assertStatus(403);
    }

    public function test_04_logout_revokes_current_token(): void
    {
        $logoutResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $this->tokenModel->id,
        ]);
    }

    public function test_05_web_login_authenticates_active_player(): void
    {
        $response = $this->post(route('login.attempt'), [
            'login' => 'somchai99',
            'password' => 'Secret123!',
        ]);

        $response->assertRedirect(route('player.dashboard'));
        $this->assertAuthenticatedAs($this->player);
    }

    public function test_06_web_login_blocks_suspended_player(): void
    {
        $this->player->status = UserStatus::Suspended;
        $this->player->save();

        $response = $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'somchai99',
            'password' => 'Secret123!',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_07_web_logout_clears_session(): void
    {
        $this->actingAs($this->player);

        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Section 2: Draws & Results API & Web UX
    |--------------------------------------------------------------------------
    */

    public function test_08_player_can_list_draws_with_pagination(): void
    {
        Draw::factory()->count(5)->create([
            'status' => DrawStatus::Open,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/draws?per_page=3');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'items' => [
                        '*' => [
                            'id',
                            'draw_number',
                            'type',
                            'status',
                            'is_open',
                            'can_accept_bets',
                            'scheduled_at',
                        ],
                    ],
                    'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
                ],
            ]);
    }

    public function test_09_player_can_filter_draws_by_status_and_type(): void
    {
        Draw::factory()->create(['status' => DrawStatus::Open]);
        Draw::factory()->create(['status' => DrawStatus::Completed]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/draws?status=open');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('open', $items[0]['status']);
    }

    public function test_10_player_can_fetch_current_open_draw(): void
    {
        $draw = Draw::factory()->create([
            'draw_number' => 'TH-2026-09-01',
            'status' => DrawStatus::Open,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/draws/current');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $draw->id,
                    'draw_number' => 'TH-2026-09-01',
                    'status' => 'open',
                    'is_open' => true,
                ],
            ]);
    }

    public function test_11_player_receives_404_when_no_active_draw_exists(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/draws/current');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'no_active_draw'],
            ]);
    }

    public function test_12_player_can_view_single_draw_detail(): void
    {
        $draw = Draw::factory()->create([
            'draw_number' => 'TH-2026-DRAW-88',
            'status' => DrawStatus::Open,
        ]);

        // Fetch by numeric ID
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/draws/'.$draw->id);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['id' => $draw->id, 'draw_number' => 'TH-2026-DRAW-88'],
            ]);

        // Fetch by draw_number slug
        $slugResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/draws/TH-2026-DRAW-88');

        $slugResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['id' => $draw->id, 'draw_number' => 'TH-2026-DRAW-88'],
            ]);
    }

    public function test_13_player_can_view_published_draw_results(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
        ]);

        $result = new DrawResult();
        $result->forceFill([
            'draw_id' => $draw->id,
            'first_prize' => '834592',
            'second_prize' => ['111111', '222222', '333333'],
            'third_prize' => ['444444', '555555', '666666'],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 12,
            'total_payout' => '150000.00',
            'metadata' => ['bottom_two' => '45'],
            'published_at' => now(),
        ])->save();

        $wn = new WinningNumber();
        $wn->forceFill([
            'draw_id' => $draw->id,
            'bet_type' => BetType::TwoD->value,
            'number' => '45',
            'position' => 'bottom',
            'payout_multiplier' => 90,
            'total_winners' => 5,
            'published_at' => now(),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/draws/'.$draw->id.'/results');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'first_prize' => '834592',
                    'two_digit_bottom' => '45',
                    'total_winners' => 12,
                ],
            ]);
    }

    public function test_14_results_endpoint_returns_404_for_unpublished_draw(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::Open,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/draws/'.$draw->id.'/results');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'results_not_available'],
            ]);
    }

    public function test_15_web_draws_and_detail_views_render_properly(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::Open,
        ]);

        $this->actingAs($this->player);

        $drawsView = $this->get(route('player.draws'));
        $drawsView->assertStatus(200)
            ->assertSee('Thai Lottery Draws');

        $detailView = $this->get(route('player.draws.detail', $draw->id));
        $detailView->assertStatus(200)
            ->assertSee('Draw #'.$draw->draw_number);
    }

    /*
    |--------------------------------------------------------------------------
    | Section 3: Wagering & Bet Lifecycle
    |--------------------------------------------------------------------------
    */

    public function test_16_player_can_purchase_valid_bet_end_to_end(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::Open,
            'betting_open_at' => now()->subHour(),
            'betting_close_at' => now()->addHour(),
        ]);

        $this->ensureLimit($draw, '2d_top', '45');

        $payload = [
            'draw_id' => $draw->id,
            'client_key' => 'client_key_valid_test_16_abc',
            'items' => [
                [
                    'market' => '2d_top',
                    'number' => '45',
                    'stake' => '100.00',
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/bets/purchase', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_stake' => '100.00',
                    'bet' => [
                        'status' => 'active',
                    ],
                ],
            ]);

        $this->wallet->refresh();
        $this->assertEquals('4900.00', (string) $this->wallet->balance);

        $this->assertDatabaseHas('bets', [
            'user_id' => $this->player->id,
            'draw_id' => $draw->id,
            'stake_amount' => 100.00,
        ]);
    }

    public function test_17_bet_purchase_rejects_insufficient_wallet_balance(): void
    {
        $this->wallet->balance = '20.00';
        $this->wallet->save();

        $draw = Draw::factory()->create([
            'status' => DrawStatus::Open,
            'betting_open_at' => now()->subHour(),
            'betting_close_at' => now()->addHour(),
        ]);

        $this->ensureLimit($draw, '2d_top', '45');

        $payload = [
            'draw_id' => $draw->id,
            'client_key' => 'client_key_insufficient_17',
            'items' => [
                [
                    'market' => '2d_top',
                    'number' => '45',
                    'stake' => '100.00',
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/bets/purchase', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'insufficient_balance'],
            ]);

        $this->wallet->refresh();
        $this->assertEquals('20.00', (string) $this->wallet->balance);
    }

    public function test_18_bet_purchase_rejects_closed_or_completed_draw(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::Closed,
            'betting_open_at' => now()->subHours(2),
            'betting_close_at' => now()->subHour(),
            'closed_at' => now()->subHour(),
        ]);

        $payload = [
            'draw_id' => $draw->id,
            'client_key' => 'client_key_closed_draw_18',
            'items' => [
                [
                    'market' => '2d_top',
                    'number' => '45',
                    'stake' => '100.00',
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/bets/purchase', $payload);

        $response->assertStatus(422);
        $this->assertContains($response->json('error.code'), ['draw_closed', 'draw_not_open']);
    }

    public function test_19_bet_purchase_rejects_invalid_market_or_number(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::Open,
        ]);

        // Wrong digit count for 3D
        $payload = [
            'draw_id' => $draw->id,
            'client_key' => 'client_key_invalid_market_19',
            'items' => [
                [
                    'market' => '3d_direct',
                    'number' => '12', // requires 3 digits
                    'stake' => '50.00',
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/bets/purchase', $payload);

        $response->assertStatus(422);
    }

    public function test_20_duplicate_idempotency_key_returns_cached_bet_response(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::Open,
            'betting_open_at' => now()->subHour(),
            'betting_close_at' => now()->addHour(),
        ]);

        $this->ensureLimit($draw, '2d_top', '99');

        $clientKey = 'unique_idempotency_key_test_20_xyz';
        $payload = [
            'draw_id' => $draw->id,
            'client_key' => $clientKey,
            'items' => [
                [
                    'market' => '2d_top',
                    'number' => '99',
                    'stake' => '100.00',
                ],
            ],
        ];

        // 1st request
        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/bets/purchase', $payload);

        $res1->assertStatus(201);
        $betNumber1 = $res1->json('data.bet.bet_number');

        // 2nd duplicate request
        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/bets/purchase', $payload);

        $res2->assertStatus(200);
        $betNumber2 = $res2->json('data.bet.bet_number');

        $this->assertEquals($betNumber1, $betNumber2);
        $this->wallet->refresh();
        $this->assertEquals('4900.00', (string) $this->wallet->balance); // Only charged once!
    }

    public function test_21_player_can_list_own_bets_with_pagination(): void
    {
        $draw = Draw::factory()->create(['status' => DrawStatus::Open]);

        for ($i = 0; $i < 4; $i++) {
            $b = new Bet();
            $b->forceFill([
                'user_id' => $this->player->id,
                'draw_id' => $draw->id,
                'bet_number' => 'BET-2026-000'.$i,
                'type' => BetType::TwoD->value,
                'stake_amount' => '50.00',
                'potential_payout' => '4500.00',
                'status' => BetStatus::Pending->value,
                'placed_at' => now(),
            ])->save();
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/bets?per_page=2');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'items',
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
                ],
            ]);

        $this->assertEquals(4, $response->json('data.pagination.total'));
    }

    public function test_22_player_cannot_view_foreign_bet_idor(): void
    {
        $otherPlayer = User::factory()->create();
        $draw = Draw::factory()->create();

        $foreignBet = new Bet();
        $foreignBet->forceFill([
            'user_id' => $otherPlayer->id,
            'draw_id' => $draw->id,
            'bet_number' => 'BET-OTHER-999',
            'type' => BetType::TwoD->value,
            'stake_amount' => '50.00',
            'potential_payout' => '4500.00',
            'status' => BetStatus::Pending->value,
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/bets/'.$foreignBet->id);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'resource_not_found'],
            ]);
    }

    public function test_23_player_can_check_bet_status(): void
    {
        $draw = Draw::factory()->create();

        $bet = new Bet();
        $bet->forceFill([
            'user_id' => $this->player->id,
            'draw_id' => $draw->id,
            'bet_number' => 'BET-STATUS-001',
            'type' => BetType::TwoD->value,
            'stake_amount' => '50.00',
            'potential_payout' => '4500.00',
            'status' => BetStatus::Pending->value,
            'placed_at' => now(),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/bets/'.$bet->id.'/status');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $bet->id,
                    'bet_number' => 'BET-STATUS-001',
                    'status' => 'pending',
                ],
            ]);
    }

    public function test_24_player_can_list_own_tickets(): void
    {
        $draw = Draw::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $t = new Ticket();
            $t->forceFill([
                'user_id' => $this->player->id,
                'draw_id' => $draw->id,
                'ticket_number' => 'TCK-2026-000'.$i,
                'status' => TicketStatus::Pending->value,
                'total_amount' => '100.00',
                'issued_at' => now(),
            ])->save();
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/tickets');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.items'));
    }

    public function test_25_player_cannot_view_foreign_ticket_idor(): void
    {
        $otherPlayer = User::factory()->create();
        $draw = Draw::factory()->create();

        $foreignTicket = new Ticket();
        $foreignTicket->forceFill([
            'user_id' => $otherPlayer->id,
            'draw_id' => $draw->id,
            'ticket_number' => 'TCK-OTHER-999',
            'status' => TicketStatus::Pending->value,
            'total_amount' => '100.00',
            'issued_at' => now(),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/tickets/'.$foreignTicket->id);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'resource_not_found'],
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Section 4: Wallet, Deposits, Withdrawals & Ledger Isolation
    |--------------------------------------------------------------------------
    */

    public function test_26_player_can_fetch_wallet_balance_and_available_amount(): void
    {
        $this->wallet->balance = '5000.00';
        $this->wallet->locked_balance = '500.00';
        $this->wallet->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/wallet');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'wallet' => [
                        'balance' => '5000.00',
                        'locked_balance' => '500.00',
                        'available_balance' => '4500.00',
                        'currency' => 'THB',
                    ],
                ],
            ]);
    }

    public function test_27_player_can_list_own_financial_transactions(): void
    {
        $tx = new FinancialTransaction();
        $tx->forceFill([
            'user_id' => $this->player->id,
            'wallet_id' => $this->wallet->id,
            'reference_number' => 'TX-DEP-1001',
            'type' => TransactionType::Deposit->value,
            'status' => TransactionStatus::Completed->value,
            'amount' => '1000.00',
            'fee' => '0.00',
            'currency' => Currency::THB->value,
            'description' => 'Test deposit',
            'processed_at' => now(),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/wallet/transactions');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('deposit', $items[0]['type']);
        $this->assertEquals('1000.00', $items[0]['amount']);
    }

    public function test_28_player_cannot_view_other_user_financial_transactions(): void
    {
        $otherPlayer = User::factory()->create();
        $otherWallet = Wallet::factory()->create(['user_id' => $otherPlayer->id]);

        $tx = new FinancialTransaction();
        $tx->forceFill([
            'user_id' => $otherPlayer->id,
            'wallet_id' => $otherWallet->id,
            'reference_number' => 'TX-OTHER-999',
            'type' => TransactionType::Deposit->value,
            'status' => TransactionStatus::Completed->value,
            'amount' => '9999.00',
            'fee' => '0.00',
            'currency' => Currency::THB->value,
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/wallet/transactions');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(0, $items);
    }

    public function test_29_player_can_list_available_deposit_methods(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/deposits/methods');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'methods',
                    'default_currency',
                    'currencies',
                ],
            ]);
    }

    public function test_30_player_can_initiate_deposit(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/deposits', [
                'amount' => '500.00',
                'method' => 'stripe',
                'currency' => 'THB',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'deposit' => [
                        'amount' => '500.00',
                        'method' => 'stripe',
                        'currency' => 'THB',
                    ],
                ],
            ]);
    }

    public function test_31_player_can_list_own_deposits(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $d = new Deposit();
            $d->forceFill([
                'user_id' => $this->player->id,
                'wallet_id' => $this->wallet->id,
                'reference_number' => 'DP-2026-00'.$i,
                'amount' => '500.00',
                'fee' => '0.00',
                'net_amount' => '500.00',
                'currency' => Currency::THB->value,
                'method' => PaymentMethod::Stripe->value,
                'status' => DepositStatus::Pending->value,
            ])->save();
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/deposits');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_32_player_cannot_view_foreign_deposit_idor(): void
    {
        $otherPlayer = User::factory()->create();
        $otherWallet = Wallet::factory()->create(['user_id' => $otherPlayer->id]);

        $foreignDeposit = new Deposit();
        $foreignDeposit->forceFill([
            'user_id' => $otherPlayer->id,
            'wallet_id' => $otherWallet->id,
            'reference_number' => 'DP-OTHER-999',
            'amount' => '500.00',
            'fee' => '0.00',
            'net_amount' => '500.00',
            'currency' => Currency::THB->value,
            'method' => PaymentMethod::Stripe->value,
            'status' => DepositStatus::Pending->value,
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/deposits/'.$foreignDeposit->id);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'deposit_not_found'],
            ]);
    }

    public function test_33_player_can_request_withdrawal(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/withdrawals', [
                'amount' => '1000.00',
                'method' => 'bank_transfer',
                'currency' => 'THB',
                'payout_details' => [
                    'bank_account' => '1234567890',
                    'bank_name' => 'Bangkok Bank',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'withdrawal' => [
                        'amount' => '1000.00',
                        'method' => 'bank_transfer',
                        'currency' => 'THB',
                    ],
                ],
            ]);

        $this->wallet->refresh();
        $this->assertEquals('1000.00', (string) $this->wallet->locked_balance);
        $this->assertEquals('4000.00', (string) $this->wallet->getAvailableBalance());
    }

    public function test_34_withdrawal_rejects_amount_exceeding_available_balance(): void
    {
        $this->wallet->balance = '100.00';
        $this->wallet->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/withdrawals', [
                'amount' => '500.00',
                'method' => 'bank_transfer',
                'currency' => 'THB',
            ]);

        $response->assertStatus(422);
    }

    public function test_35_player_can_list_own_withdrawals(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $w = new Withdrawal();
            $w->forceFill([
                'user_id' => $this->player->id,
                'wallet_id' => $this->wallet->id,
                'reference_number' => 'WD-2026-00'.$i,
                'amount' => '500.00',
                'fee' => '0.00',
                'net_amount' => '500.00',
                'currency' => Currency::THB->value,
                'method' => PaymentMethod::BankTransfer->value,
                'status' => \App\Enums\WithdrawalStatus::Pending->value,
                'requested_at' => now(),
            ])->save();
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/withdrawals');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_36_player_cannot_view_foreign_withdrawal_idor(): void
    {
        $otherPlayer = User::factory()->create();
        $otherWallet = Wallet::factory()->create(['user_id' => $otherPlayer->id]);

        $foreignWithdrawal = new Withdrawal();
        $foreignWithdrawal->forceFill([
            'user_id' => $otherPlayer->id,
            'wallet_id' => $otherWallet->id,
            'reference_number' => 'WD-OTHER-999',
            'amount' => '500.00',
            'fee' => '0.00',
            'net_amount' => '500.00',
            'currency' => Currency::THB->value,
            'method' => PaymentMethod::BankTransfer->value,
            'status' => \App\Enums\WithdrawalStatus::Pending->value,
            'requested_at' => now(),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/withdrawals/'.$foreignWithdrawal->id);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'withdrawal_not_found'],
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Section 5: Profile, Settings & Real-Time Broadcast Security
    |--------------------------------------------------------------------------
    */

    public function test_37_player_can_fetch_and_update_profile(): void
    {
        $fetchRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/profile');

        $fetchRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'name' => 'Somchai Jaidee',
                        'email' => 'somchai@example.com',
                    ],
                ],
            ]);

        $updateRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/profile', [
                'name' => 'Somchai Jaidee Updated',
                'phone' => '+66899999999',
            ]);

        $updateRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'name' => 'Somchai Jaidee Updated',
                        'phone' => '+66899999999',
                    ],
                ],
            ]);

        $this->player->refresh();
        $this->assertEquals('Somchai Jaidee Updated', $this->player->name);
        $this->assertEquals('+66899999999', $this->player->phone);
    }

    public function test_38_player_can_change_password_with_current_password_verification(): void
    {
        // 1. Wrong current password fails
        $wrongRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'WrongPassword123!',
                'password' => 'NewSecret123!',
                'password_confirmation' => 'NewSecret123!',
            ]);

        $wrongRes->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'invalid_current_password'],
            ]);

        // 2. Correct current password succeeds
        $validRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'Secret123!',
                'password' => 'NewSecret123!',
                'password_confirmation' => 'NewSecret123!',
            ]);

        $validRes->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->player->refresh();
        $this->assertTrue(Hash::check('NewSecret123!', $this->player->password));
    }

    public function test_39_private_channel_authorizes_owner_and_denies_foreign_user(): void
    {
        $otherPlayer = User::factory()->create();

        $channels = Broadcast::getChannels();
        $userChannelCallback = $channels->get('App.Models.User.{id}');

        $this->assertNotNull($userChannelCallback);
        $this->assertTrue($userChannelCallback($this->player, (string) $this->player->id));
        $this->assertFalse($userChannelCallback($this->player, (string) $otherPlayer->id));
    }

    public function test_40_broadcast_events_payload_sanitization(): void
    {
        $draw = Draw::factory()->create(['status' => DrawStatus::Open]);
        $result = new DrawResult();
        $result->forceFill([
            'draw_id' => $draw->id,
            'first_prize' => '123456',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 0,
            'total_payout' => '0.00',
        ])->save();

        $drawStatusEvent = new DrawStatusUpdated($draw);
        $this->assertEquals('draw.status.updated', $drawStatusEvent->broadcastAs());
        $statusPayload = $drawStatusEvent->broadcastWith();
        $this->assertEquals($draw->id, $statusPayload['draw_id']);
        $this->assertArrayNotHasKey('password', $statusPayload);

        $drawResultEvent = new DrawResultPublished($draw, $result);
        $this->assertEquals('draw.result.published', $drawResultEvent->broadcastAs());
        $resultPayload = $drawResultEvent->broadcastWith();
        $this->assertEquals('123456', $resultPayload['first_prize']);

        $walletEvent = new WalletBalanceUpdated($this->wallet);
        $this->assertEquals('wallet.balance.updated', $walletEvent->broadcastAs());
        $walletPayload = $walletEvent->broadcastWith();
        $this->assertEquals('5000.00', $walletPayload['balance']);

        $bet = new Bet();
        $bet->forceFill([
            'user_id' => $this->player->id,
            'draw_id' => $draw->id,
            'bet_number' => 'BET-EVENT-001',
            'type' => BetType::TwoD->value,
            'stake_amount' => '100.00',
            'potential_payout' => '9000.00',
            'status' => BetStatus::Pending->value,
        ])->save();
        $betEvent = new BetPlaced($bet);
        $this->assertEquals('bet.placed', $betEvent->broadcastAs());

        $withdrawal = new Withdrawal();
        $withdrawal->forceFill([
            'user_id' => $this->player->id,
            'wallet_id' => $this->wallet->id,
            'reference_number' => 'WD-EVENT-001',
            'amount' => '500.00',
            'fee' => '0.00',
            'net_amount' => '500.00',
            'currency' => Currency::THB->value,
            'method' => PaymentMethod::BankTransfer->value,
            'status' => \App\Enums\WithdrawalStatus::Pending->value,
        ])->save();
        $wEvent = new WithdrawalStatusUpdated($withdrawal);
        $this->assertEquals('withdrawal.status.updated', $wEvent->broadcastAs());

        $deposit = new Deposit();
        $deposit->forceFill([
            'user_id' => $this->player->id,
            'wallet_id' => $this->wallet->id,
            'reference_number' => 'DP-EVENT-001',
            'amount' => '500.00',
            'fee' => '0.00',
            'net_amount' => '500.00',
            'currency' => Currency::THB->value,
            'method' => PaymentMethod::Stripe->value,
            'status' => DepositStatus::Pending->value,
        ])->save();
        $dEvent = new DepositStatusUpdated($deposit);
        $this->assertEquals('deposit.status.updated', $dEvent->broadcastAs());
    }

    public function test_41_web_app_all_screens_render_for_authenticated_player(): void
    {
        $this->actingAs($this->player);

        $routes = [
            'player.dashboard',
            'player.draws',
            'player.bet',
            'player.bets',
            'player.wallet',
            'player.deposit',
            'player.withdraw',
            'player.profile',
        ];

        foreach ($routes as $route) {
            $response = $this->get(route($route));
            $response->assertStatus(200);
        }
    }
}
