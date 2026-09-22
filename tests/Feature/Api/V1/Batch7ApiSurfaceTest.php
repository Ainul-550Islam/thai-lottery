<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DrawConfirmationStatus;
use App\Enums\DrawType;
use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Enums\TicketProductStatus;
use App\Enums\WalletType;
use App\Enums\WithdrawalStatus;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Payout;
use App\Models\Ticket;
use App\Models\TicketProduct;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature proof of the Batch-7 API surface: payouts read/cancel-petition,
 * withdrawal create/cancel, prize-claim delegation, draw maker/checker
 * pipeline, product catalogue, and owner-scoped ownership reads.
 *
 * Namespace-level note: the suite runs on DatabaseTruncation for the same
 * reason as the sibling suites — several financial services assert they
 * are the outermost transaction.
 */
final class Batch7ApiSurfaceTest extends TestCase
{
    use DatabaseTruncation;

    private User $player;

    private Wallet $wallet;

    private string $token;

    private User $admin;

    private string $adminToken;

    private User $admin2;

    private string $admin2Token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('lottery.claims.window_days', 730);

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('player', 'web');

        $this->player = $this->freshUser()->create();
        $this->player->syncRoles(['player']);
        $this->token = $this->player->createToken('api')->plainTextToken;

        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->player->id,
            'type' => WalletType::Primary->value,
            'currency' => Currency::THB->value,
            'balance' => '10000.00',
            'locked_balance' => '0.00',
        ]);

        $this->admin = $this->freshUser()->create();
        $this->admin->syncRoles(['admin']);
        $this->adminToken = $this->admin->createToken('api')->plainTextToken;

        $this->admin2 = $this->freshUser()->create();
        $this->admin2->syncRoles(['admin']);
        $this->admin2Token = $this->admin2->createToken('api')->plainTextToken;
    }

    /* ------------------------------------------------ PAYOUT LANE ----- */

    public function test_payout_index_lists_only_the_callers_obligations(): void
    {
        $this->makePayout($this->player, 'PAY-A-1');
        $this->makePayout($this->player, 'PAY-A-2');

        $other = $this->freshUser()->create();
        $this->makePayout($other, 'PAY-B-1');

        $response = $this->apiAs($this->token, 'GET', '/api/v1/payouts');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));

        foreach ($response->json('data.items') as $item) {
            $this->assertStringStartsWith('PAY-A-', (string) $item['reference_number']);
            $this->assertArrayNotHasKey('provider_reference', $item);
            $this->assertArrayNotHasKey('metadata', $item);
        }
    }

    public function test_payout_show_own_is_sanitized_and_foreign_is_403(): void
    {
        $payout = $this->makePayout($this->player, 'PAY-OWN-1');

        $own = $this->apiAs($this->token, 'GET', '/api/v1/payouts/'.$payout->reference_number);
        $own->assertStatus(200)
            ->assertJsonPath('data.payout.reference_number', 'PAY-OWN-1')
            ->assertJsonPath('data.payout.status', 'pending');
        $this->assertArrayNotHasKey('provider_reference', (array) $own->json('data.payout'));

        $other = $this->freshUser()->create();
        $foreign = $this->makePayout($other, 'PAY-FGN-1');

        $this->apiAs($this->token, 'GET', '/api/v1/payouts/'.$foreign->reference_number)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'payout_forbidden');
    }

    public function test_payout_show_operator_may_read_any_payout(): void
    {
        $foreign = $this->makePayout($this->player, 'PAY-FGN-OP');

        // An operator may read any payout.
        $this->apiAs($this->adminToken, 'GET', '/api/v1/payouts/'.$foreign->reference_number)
            ->assertStatus(200)
            ->assertJsonPath('data.payout.reference_number', 'PAY-FGN-OP');
    }

    public function test_payout_cancel_petition_is_idempotent_and_state_guarded(): void
    {
        $pending = $this->makePayout($this->player, 'PAY-CANCEL-1');

        $first = $this->apiAs($this->token, 'POST', '/api/v1/payouts/'.$pending->reference_number.'/cancel', ['reason' => 'no longer needed']);
        $first->assertStatus(200);

        // A repeat re-serves the same petition (no duplicate).
        $second = $this->apiAs($this->token, 'POST', '/api/v1/payouts/'.$pending->reference_number.'/cancel', ['reason' => 'no longer needed']);
        $second->assertStatus(200);

        $lane = $pending->fresh()->metadata['approval'] ?? [];
        $this->assertSame('pending', $lane['cancellation_petition']['outcome'] ?? null);

        // A payout that left rest refuses the petition at the policy.
        $done = $this->makePayout($this->player, 'PAY-DONE-1', PayoutStatus::Completed);
        $this->apiAs($this->token, 'POST', '/api/v1/payouts/'.$done->reference_number.'/cancel')
            ->assertStatus(403);
    }

    /* ---------------------------------------------- WITHDRAWAL LANE --- */

    public function test_withdrawal_create_uses_request_validation_and_kyc_lane(): void
    {
        // Client-asserted balance/KYC facts are refused loudly.
        $this->apiAs($this->token, 'POST', '/api/v1/withdrawals', [
                'amount' => '500.00', 'method' => 'bank_transfer', 'balance' => '999999.00',
            ])
            ->assertStatus(422);

        $created = $this->apiAs($this->token, 'POST', '/api/v1/withdrawals', [
                'amount' => '500.00',
                'method' => 'bank_transfer',
                'currency' => 'THB',
            ]);

        $created->assertStatus(201)
            ->assertJsonPath('data.withdrawal.status', 'pending');
        $this->assertEquals('500.00', (string) $this->wallet->fresh()->locked_balance);
    }

    public function test_withdrawal_cancel_pending_releases_and_completed_refuses(): void
    {
        $pending = $this->makeWithdrawal($this->player, 'WD-CXL-1', WithdrawalStatus::Pending);

        $ok = $this->apiAs($this->token, 'POST', '/api/v1/withdrawals/'.$pending->reference_number.'/cancel', ['reason' => 'changed my mind']);
        $ok->assertStatus(200);

        $done = $this->makeWithdrawal($this->player, 'WD-CXL-2', WithdrawalStatus::Completed);
        $this->apiAs($this->token, 'POST', '/api/v1/withdrawals/'.$done->reference_number.'/cancel')
            ->assertStatus(422);
    }

    /* -------------------------------------------------- PRIZE LANE ---- */

    public function test_prize_claim_delegates_and_replays(): void
    {
        [$bet, $payout] = $this->makeWonBetAndPayout($this->player, 1_000_001, 'PAY-CLAIM-1');

        $first = $this->apiAs($this->token, 'POST', '/api/v1/prize-claims', ['bet_id' => $bet->id]);
        $first->assertStatus(201)
            ->assertJsonPath('data.claim.bet_id', $bet->id);

        $second = $this->apiAs($this->token, 'POST', '/api/v1/prize-claims', ['bet_id' => $bet->id]);
        $second->assertStatus(200)
            ->assertJsonPath('data.claim.no_op', true);

        // Foreign bet: 404 from the controller's owner-scope pre-question.
        $other = $this->freshUser()->create();
        [$foreignBet] = $this->makeWonBetAndPayout($other, 1_000_002, 'PAY-CLAIM-2');
        $this->apiAs($this->token, 'POST', '/api/v1/prize-claims', ['bet_id' => $foreignBet->id])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'prize_claim_not_found');

        $list = $this->apiAs($this->token, 'GET', '/api/v1/prize-claims');
        $list->assertStatus(200);
        $this->assertCount(1, $list->json('data.items'));
    }

    /* --------------------------------------------- DRAW PIPELINE ------ */

    public function test_player_cannot_ingest_draw_results(): void
    {
        $draw = Draw::factory()->create();

        $this->apiAs($this->token, 'POST', '/api/v1/draws/'.$draw->id.'/result/ingest', [
                'first_prize' => '123456',
                'source' => 'manual',
            ])
            ->assertStatus(403);
    }

    public function test_maker_checker_publish_flow_end_to_end(): void
    {
        $draw = Draw::factory()->create(['status' => \App\Enums\DrawStatus::Closed]);
        app(\App\Services\Draw\DrawLifecycleService::class)->markResultPending($draw);

        $staged = $this->apiAs($this->adminToken, 'POST', '/api/v1/draws/'.$draw->id.'/result/ingest', [
                'first_prize' => '123456',
                'source' => 'manual',
            ]);
        $staged->assertStatus(201);

        // The UI review screen's view of the lane sees a Pending card.
        $status = app(\App\Services\Draw\DrawResultConfirmationService::class)->statusOf((int) $draw->id);
        $this->assertSame(DrawConfirmationStatus::Pending, $status);

        // A checker (a different operator) confirms.
        $confirmed = $this->apiAs($this->admin2Token, 'POST', '/api/v1/draws/'.$draw->id.'/result/confirm', [
                'first_prize' => '123456',
                'notes' => 'Verified against the physical announcement sheet.',
            ]);
        $confirmed->assertStatus(200);

        // Only now publication is admitted.
        $published = $this->apiAs($this->adminToken, 'POST', '/api/v1/draws/'.$draw->id.'/result/publish');
        $published->assertStatus(200)
            ->assertJsonPath('data.published.first_prize', '123456');

        $this->assertInstanceOf(DrawResult::class, DrawResult::query()->where('draw_id', $draw->id)->first());

        // Public shape on show.
        $shown = $this->apiAs($this->token, 'GET', '/api/v1/draws/'.$draw->id.'/result');
        $shown->assertStatus(200)
            ->assertJsonPath('data.result.first_prize', '123456')
            ->assertJsonPath('data.result.publication.shape', 'public');
    }

    /* ------------------------------------------- PRODUCT CATALOGUE ---- */

    public function test_player_sees_only_active_products(): void
    {
        $draw = Draw::factory()->create();
        $this->makeProduct($draw, 'GLO-1D-80THB-ACT', TicketProductStatus::Active);
        $this->makeProduct($draw, 'GLO-1D-80THB-SUS', TicketProductStatus::Suspended);

        $index = $this->apiAs($this->token, 'GET', '/api/v1/ticket-products');
        $index->assertStatus(200);
        $this->assertCount(1, $index->json('data.items'));
        $this->assertSame('GLO-1D-80THB-ACT', $index->json('data.items.0.product_code'));

        $this->apiAs($this->token, 'GET', '/api/v1/ticket-products/GLO-1D-80THB-SUS')
            ->assertStatus(404);

        // Operator widening: suspended row visible.
        $ops = $this->apiAs($this->adminToken, 'GET', '/api/v1/ticket-products?lane=all');
        $ops->assertStatus(200);
        $this->assertCount(2, $ops->json('data.items'));
    }

    /* ------------------------------------------- OWNERSHIP READ ------- */

    public function test_ownership_reads_are_owner_scoped(): void
    {
        $ticket = $this->makeTicket($this->player, 'TKT-OWN-1');
        $other = $this->freshUser()->create();
        $this->makeTicket($other, 'TKT-FGN-1');

        $index = $this->apiAs($this->token, 'GET', '/api/v1/ticket-ownership');
        $index->assertStatus(200);
        $this->assertCount(1, $index->json('data.items'));
        $this->assertSame('TKT-OWN-1', $index->json('data.items.0.ticket_number'));

        $this->apiAs($this->token, 'GET', '/api/v1/ticket-ownership/TKT-FGN-1')
            ->assertStatus(404);

        $this->apiAs($this->token, 'GET', '/api/v1/ticket-ownership/TKT-OWN-1')
            ->assertStatus(200)
            ->assertJsonPath('data.ownership.owner_user_id', $this->player->id);
    }

    /* ------------------------------------------------ HTTP HELPERS ---- */

    /**
     * Make an authenticated JSON API call as the principal behind $token.
     *
     * WHY Auth::forgetGuards() IS MANDATORY HERE
     * In the single-process test harness the auth-guard resolver is a
     * singleton: the first authenticated request in a test pins the Request
     * guard's captured Request object, so every later ->user() read — even
     * for a DIFFERENT bearer token — replays the first request's
     * authentication, silently. In production (one request per process) the
     * bug never exists. Forgetting guards between calls forces each test
     * call to resolve its own principal from its own bearer token. This is
     * what `Sanctum::actingAs()` accomplishes for the canonical pattern;
     * this helper does the same for the token-header pattern every sibling
     * suite uses.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function apiAs(string $token, string $method, string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    /* --------------------------------------------------- FIXTURES ----- */

    /**
     * The DatabaseTruncation trait resets AUTO_INCREMENT but the users.email
     * unique index keeps its REUSED row VALUES out of any test's reach;
     * cross-prefixing the factory uniqueness container guarantees a fresh
     * interface every DatabaseTruncation test file, whoever's in it.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<User>
     */
    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(function (): array {
            $stub = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));

            return [
                'email' => $stub.'.'.\Illuminate\Support\Str::ulid()->toBase32().'@b7.local',
                'username' => 'b7'.$stub.'.'.\Illuminate\Support\Str::random(6),
                'phone' => '+8800'.random_int(100_000_000, 999_999_999),
            ];
        });
    }

    private function makePayout(User $user, string $reference, PayoutStatus $status = PayoutStatus::Pending): Payout
    {
        $payout = new Payout();
        $payout->forceFill([
            'reference_number' => $reference,
            'draw_id' => Draw::factory()->create()->id,
            'user_id' => $user->id,
            'wallet_id' => $user->id === $this->player->id ? $this->wallet->id : Wallet::factory()->create(['user_id' => $user->id, 'currency' => Currency::THB->value])->id,
            'currency' => Currency::THB->value,
            'amount' => '100.00',
            'multiplier' => '1.00',
            'metadata' => [],
        ]);
        $payout->status = $status;
        $payout->save();

        return $payout;
    }

    private function makeWithdrawal(User $user, string $reference, WithdrawalStatus $status): Withdrawal
    {
        $row = new Withdrawal();
        $row->forceFill([
            'reference_number' => $reference,
            'user_id' => $user->id,
            'wallet_id' => $user->id === $this->player->id ? $this->wallet->id : Wallet::factory()->create(['user_id' => $user->id, 'currency' => Currency::THB->value])->id,
            'method' => PaymentMethod::BankTransfer->value,
            'amount' => '500.00',
            'fee' => '0.00',
            'net_amount' => '500.00',
            'payout_details' => [],
            'metadata' => [],
            'requested_at' => now(),
        ]);
        $row->currency = Currency::THB;
        $row->status = $status;
        $row->save();

        return $row;
    }

    /**
     * @return array{0: Bet, 1: Payout}
     */
    private function makeWonBetAndPayout(User $user, int $suffix, string $payoutRef): array
    {
        $draw = Draw::factory()->create(['status' => \App\Enums\DrawStatus::ResultPublished]);
        $draw->completed_at = now();

        $bet = new Bet();
        $bet->forceFill([
            'bet_number' => 'BET-'.$suffix,
            'user_id' => $user->id,
            'draw_id' => $draw->id,
            'type' => \App\Enums\BetType::TwoD,
            'currency' => Currency::THB->value,
            'stake_amount' => '10.00',
            'potential_payout' => '900.00',
            'actual_payout' => '900.00',
            'total_numbers' => 1,
            'metadata' => [],
            'placed_at' => now(),
        ]);
        $bet->status = BetStatus::Won;
        $bet->save();

        $payout = new Payout();
        $payout->forceFill([
            'reference_number' => $payoutRef,
            'draw_id' => $draw->id,
            'bet_id' => $bet->id,
            'user_id' => $user->id,
            'wallet_id' => $user->id === $this->player->id ? $this->wallet->id : Wallet::factory()->create(['user_id' => $user->id, 'currency' => Currency::THB->value])->id,
            'currency' => Currency::THB->value,
            'amount' => '900.00',
            'multiplier' => '1.00',
            'metadata' => [],
        ]);
        $payout->status = PayoutStatus::Pending;
        $payout->save();

        $bet->payout_id = $payout->id;
        $bet->save();

        return [$bet, $payout];
    }

    private function makeProduct(Draw $draw, string $code, TicketProductStatus $status): TicketProduct
    {
        $product = new TicketProduct();
        $product->forceFill([
            'product_key' => hash('sha256', $code),
            'product_code' => $code,
            'draw_id' => $draw->id,
            'denomination' => '80.00',
            'currency' => Currency::THB->value,
            'units_total' => 100,
            'units_allocated' => 10,
            'starts_at' => Carbon::yesterday(),
            'ends_at' => Carbon::tomorrow(),
            'metadata' => [],
        ]);
        $product->status = $status;
        $product->save();

        return $product;
    }

    private function makeTicket(User $user, string $number): Ticket
    {
        $ticket = new Ticket();
        $ticket->forceFill([
            'ticket_number' => $number,
            'user_id' => $user->id,
            'draw_id' => Draw::factory()->create()->id,
            'currency' => Currency::THB->value,
            'total_amount' => '80.00',
            'total_bets' => 1,
            'total_numbers' => 1,
            'metadata' => [],
        ]);
        $ticket->status = \App\Enums\TicketStatus::Confirmed;
        $ticket->save();

        app(\App\Services\Ticket\TicketOwnershipService::class)->bind($ticket, (int) $user->id);

        return $ticket->fresh();
    }
}
