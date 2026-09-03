<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\BetType;
use App\Enums\LedgerEntryType;
use App\Enums\LimitStatus;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\LedgerEntry;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Phase 4.4 - the HTTP purchase endpoint.
 *
 * Every test here drives the real route through the real middleware stack. None of them
 * calls BetPurchaseService directly: the point of this suite is the boundary, so bypassing
 * the boundary would prove nothing about it.
 *
 * Numbers and stakes are sent as STRINGS everywhere, because that is the contract, and
 * every assertion about money compares strings. There is no float, no intval and no round
 * in this file - a test that used them could pass while the code under test was wrong.
 */
final class BetPurchaseApiTest extends ApiPurchaseTestCase
{
    private const URI = '/api/v1/bets/purchase';

    // -----------------------------------------------------------------------------
    // A-D: authentication and identity
    // -----------------------------------------------------------------------------

    #[Test]
    public function a_unauthenticated_purchase_request_is_rejected(): void
    {
        $fixture = $this->fixture();

        $response = $this->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('a')),
        );

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'unauthenticated');

        // The refusal happened before any application code ran: nothing was written.
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    #[Test]
    public function b_authenticated_purchase_request_succeeds(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('b')),
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.replayed', false);
        $response->assertJsonPath('data.items.0.number', '123');
        $response->assertJsonPath('data.total_stake', '10.00');

        $this->assertSame(1, Bet::query()->count());
        $this->assertSame(1, Ticket::query()->count());
    }

    #[Test]
    public function c_the_bet_owner_is_taken_from_the_authenticated_context(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('c')),
        )->assertStatus(201);

        $bet = Bet::query()->sole();

        $this->assertSame((int) $fixture['user']->getKey(), (int) $bet->user_id);
    }

    #[Test]
    public function d_a_spoofed_user_id_in_the_payload_is_rejected_and_never_honoured(): void
    {
        $attacker = $this->fixture();
        $victim = $this->fixture();

        $payload = $this->payload($attacker['draw'], '3d_direct', '123', '10.00', $this->key('d'));
        $payload['user_id'] = (int) $victim['user']->getKey();

        $response = $this->actingAs($attacker['user'], 'sanctum')->postJson(self::URI, $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');

        // Neither the attacker nor the victim has a bet: the request was refused outright.
        $this->assertSame(0, Bet::query()->count());
    }

    // -----------------------------------------------------------------------------
    // E-M: request and domain validation
    // -----------------------------------------------------------------------------

    #[Test]
    public function e_a_purchase_for_a_draw_that_does_not_exist_is_refused(): void
    {
        $fixture = $this->fixture();

        $payload = $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('e'));
        $payload['draw_id'] = 987654321;

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, $payload);

        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'draw_not_found');
        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function f_a_purchase_for_a_closed_draw_is_refused(): void
    {
        $fixture = $this->fixture();
        $closed = $this->closedDraw();

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($closed, '3d_direct', '123', '10.00', $this->key('f')),
        );

        $response->assertJsonPath('success', false);

        // Either vocabulary is correct and both are in the required set: the domain
        // distinguishes "the draw's status is closed" from "the draw is not open for
        // betting right now", and both are honest answers to this request.
        $this->assertContains(
            $response->json('error.code'),
            ['draw_closed', 'draw_not_open'],
            'A closed draw must be refused with a draw-state error code.',
        );

        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function g_an_unknown_market_is_refused(): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '4d_direct', '1234', '10.00', $this->key('g')),
        );

        $response->assertStatus(422);
        $this->assertContains($response->json('error.code'), ['validation_failed', 'invalid_market']);
        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function h_a_number_with_the_wrong_digit_length_is_refused(): void
    {
        $fixture = $this->fixture();

        // Four digits for a market that takes three.
        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '1234', '10.00', $this->key('h')),
        );

        $response->assertStatus(422);
        $this->assertContains(
            $response->json('error.code'),
            ['validation_failed', 'invalid_digits', 'invalid_number'],
        );
        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function i_a_leading_zero_number_is_preserved_end_to_end(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '007');

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '007', '10.00', $this->key('i')),
        );

        $response->assertStatus(201);

        // The response says '007', as a JSON string.
        $response->assertJsonPath('data.items.0.number', '007');
        $this->assertIsString($response->json('data.items.0.number'));

        // The database says '007'.
        $item = BetItem::query()->sole();
        $this->assertSame('007', $item->number);

        // And it is emphatically not the integer 7 anywhere: the raw response body
        // contains the quoted string, not a bare 7.
        $this->assertStringContainsString('"number":"007"', $response->getContent());
        $this->assertNotSame(7, $response->json('data.items.0.number'));
    }

    #[Test]
    public function j_the_number_099_is_preserved_end_to_end(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '099');

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '099', '10.00', $this->key('j')),
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.items.0.number', '099');
        $this->assertSame('099', BetItem::query()->sole()->number);
    }

    #[Test]
    public function k_a_malformed_stake_is_refused(): void
    {
        $fixture = $this->fixture();

        // Three decimal places: more precision than the currency has.
        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.005', $this->key('k')),
        );

        $response->assertStatus(422);
        $this->assertContains($response->json('error.code'), ['validation_failed', 'invalid_stake']);
        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function l_a_zero_stake_is_refused(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '0.00', $this->key('l')),
        );

        $response->assertStatus(422);
        $this->assertContains($response->json('error.code'), ['validation_failed', 'invalid_stake']);
        $this->assertSame(0, Bet::query()->count());

        // A zero-stake bet must never be recorded, and no ledger entry may exist for it.
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    #[Test]
    public function m_a_negative_stake_is_refused(): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '-10.00', $this->key('m')),
        );

        $response->assertStatus(422);
        $this->assertContains($response->json('error.code'), ['validation_failed', 'invalid_stake']);
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    // -----------------------------------------------------------------------------
    // N-O: financial and risk refusals arrive as stable API codes
    // -----------------------------------------------------------------------------

    #[Test]
    public function n_an_insufficient_balance_is_mapped_to_a_stable_error_code(): void
    {
        // A wallet holding less than the minimum stake.
        $fixture = $this->fixture('5.00');
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '100.00', $this->key('n')),
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'insufficient_balance');

        // The refusal must not disclose wallet internals.
        $this->assertStringNotContainsString('wallet_id', (string) $response->getContent());

        $this->assertSame(0, Bet::query()->count());

        // The balance is untouched, to the exact string.
        $fixture['wallet']->refresh();
        $this->assertSame('5.00', (string) $fixture['wallet']->balance);
    }

    #[Test]
    public function o_a_number_limit_refusal_is_mapped_to_a_stable_error_code(): void
    {
        $fixture = $this->fixture();

        // The number's own ceiling is already consumed.
        $this->tightenLimit($fixture['draw'], BetType::ThreeD, '123', [
            'max_amount' => '10.00',
            'current_amount' => '10.00',
            'status' => LimitStatus::Active,
        ]);

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('o')),
        );

        $response->assertStatus(422);
        $this->assertContains(
            $response->json('error.code'),
            ['number_limit_exceeded', 'risk_rejected'],
            'A number-limit refusal must surface as a risk or number-limit code, never as a 500.',
        );

        $this->assertSame(0, Bet::query()->count());
        $fixture['wallet']->refresh();
        $this->assertSame('1000.00', (string) $fixture['wallet']->balance);
    }

    // -----------------------------------------------------------------------------
    // P-S: idempotency
    // -----------------------------------------------------------------------------

    #[Test]
    public function p_an_identical_replayed_request_returns_the_same_purchase_once(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $payload = $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('p'));

        $first = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, $payload);
        $first->assertStatus(201);
        $first->assertJsonPath('data.replayed', false);

        $second = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, $payload);

        // A replay is a 200, not a 201: nothing was created by the second call.
        $second->assertStatus(200);
        $second->assertJsonPath('success', true);
        $second->assertJsonPath('data.replayed', true);

        // Same bet, same ticket, same money.
        $this->assertSame($first->json('data.bet.id'), $second->json('data.bet.id'));
        $this->assertSame($first->json('data.ticket.id'), $second->json('data.ticket.id'));
        $this->assertSame($first->json('data.total_stake'), $second->json('data.total_stake'));

        // Exactly one purchase exists, and the wallet was debited exactly once.
        $this->assertSame(1, Bet::query()->count());
        $this->assertSame(1, BetItem::query()->count());
        $this->assertSame(1, Ticket::query()->count());

        $fixture['wallet']->refresh();
        $this->assertSame('990.00', (string) $fixture['wallet']->balance);
    }

    #[Test]
    public function q_the_same_client_key_with_a_changed_payload_is_rejected(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');
        $this->ensureLimit($fixture['draw'], '3d_direct', '456');

        $key = $this->key('q');

        $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $key),
        )->assertStatus(201);

        // Same key, different number. This must NOT be treated as a replay, and must not
        // create a second purchase.
        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '456', '10.00', $key),
        );

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'idempotency_payload_mismatch');

        $this->assertSame(1, Bet::query()->count());
        $this->assertSame('123', BetItem::query()->sole()->number);

        $fixture['wallet']->refresh();
        $this->assertSame('990.00', (string) $fixture['wallet']->balance);
    }

    #[Test]
    public function r_the_same_client_key_from_two_different_users_does_not_collide(): void
    {
        $one = $this->fixture();
        $two = $this->fixture();

        $key = $this->key('r');

        $this->ensureLimit($one['draw'], '3d_direct', '123');
        $this->ensureLimit($two['draw'], '3d_direct', '123');

        $first = $this->actingAs($one['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($one['draw'], '3d_direct', '123', '10.00', $key),
        );
        $first->assertStatus(201);

        $second = $this->actingAs($two['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($two['draw'], '3d_direct', '123', '10.00', $key),
        );

        // The second user gets their OWN purchase, not the first user's replayed back at
        // them. That is the whole reason the client key is scoped rather than used raw.
        $second->assertStatus(201);
        $second->assertJsonPath('data.replayed', false);
        $this->assertNotSame($first->json('data.bet.id'), $second->json('data.bet.id'));

        $this->assertSame(2, Bet::query()->count());
        $this->assertSame(1, Bet::query()->where('user_id', $one['user']->getKey())->count());
        $this->assertSame(1, Bet::query()->where('user_id', $two['user']->getKey())->count());
    }

    #[Test]
    public function s_the_same_client_key_on_two_different_draws_does_not_collide(): void
    {
        $fixture = $this->fixture();
        $otherDraw = $this->openDraw();

        $key = $this->key('s');

        $this->ensureLimit($fixture['draw'], '3d_direct', '123');
        $this->ensureLimit($otherDraw, '3d_direct', '123');

        $first = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $key),
        );
        $first->assertStatus(201);

        $second = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($otherDraw, '3d_direct', '123', '10.00', $key),
        );

        $second->assertStatus(201);
        $second->assertJsonPath('data.replayed', false);
        $this->assertNotSame($first->json('data.bet.id'), $second->json('data.bet.id'));

        $this->assertSame(2, Bet::query()->count());
    }

    // -----------------------------------------------------------------------------
    // T: the response body is safe
    // -----------------------------------------------------------------------------

    #[Test]
    public function t_the_success_response_exposes_only_safe_data(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('t')),
        );

        $response->assertStatus(201);

        // What a client legitimately needs is present.
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'status',
                'replayed',
                'client_key',
                'bet' => ['id', 'uuid', 'bet_number', 'status'],
                'ticket' => ['id', 'uuid', 'ticket_number', 'status'],
                'total_stake',
                'potential_payout',
                'items',
            ],
        ]);

        $body = (string) $response->getContent();

        // What it must never receive.
        foreach ([
            'wallet_id',
            'ledger_account_id',
            'ledger_entry_ids',
            'financial_transaction_id',
            'available_balance',
            'risk_reservation',
            'idempotency_key',
            'password',
            'remember_token',
            'trace',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                sprintf('The purchase response must not contain "%s".', $forbidden),
            );
        }

        // The bet's owner id is not echoed back either.
        $this->assertArrayNotHasKey('user_id', (array) $response->json('data.bet'));
    }

    // -----------------------------------------------------------------------------
    // X-Y: the controller does not move money itself
    // -----------------------------------------------------------------------------

    #[Test]
    public function x_the_wallet_is_only_ever_mutated_by_the_purchase_service(): void
    {
        $fixture = $this->fixture('1000.00');
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('x')),
        )->assertStatus(201);

        // Exactly one debit of exactly the stake. A controller that also touched the
        // balance would show up here as a double debit.
        $fixture['wallet']->refresh();
        $this->assertSame('990.00', (string) $fixture['wallet']->balance);

        // And the wallet row was written once by the purchase, not twice.
        $this->assertSame(
            1,
            Wallet::query()->where('user_id', $fixture['user']->getKey())->count(),
        );
    }

    #[Test]
    public function y_ledger_entries_are_written_only_by_the_domain_and_stay_balanced(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('y')),
        )->assertStatus(201);

        $entries = LedgerEntry::query()->get();

        // The purchase posted a double entry. The controller added nothing of its own, so
        // debits still equal credits to the cent.
        $this->assertGreaterThanOrEqual(2, $entries->count());

        // This schema stores one signed side per row: a `type` of debit or credit and a
        // positive `amount`. Balance therefore means the debit rows and the credit rows sum
        // to the same figure, which is summed here with bcmath so the check itself cannot
        // introduce a rounding error.
        $debits = '0.00';
        $credits = '0.00';

        foreach ($entries as $entry) {
            $amount = (string) $entry->amount;

            if ($entry->type === LedgerEntryType::Debit) {
                $debits = bcadd($debits, $amount, 2);

                continue;
            }

            $credits = bcadd($credits, $amount, 2);
        }

        $this->assertSame($debits, $credits, 'The ledger must balance after an API purchase.');
        $this->assertSame('10.00', $debits);
    }

    // -----------------------------------------------------------------------------
    // Z-AA: a multi-item request is all-or-nothing
    // -----------------------------------------------------------------------------

    #[Test]
    public function z_a_multi_item_request_is_atomic_and_charges_nothing_when_it_cannot_be_honoured(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');
        $this->ensureLimit($fixture['draw'], '2d_top', '45');

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, [
            'draw_id' => (int) $fixture['draw']->getKey(),
            'client_key' => $this->key('z'),
            'items' => [
                ['market' => '3d_direct', 'number' => '123', 'stake' => '10.00'],
                ['market' => '2d_top', 'number' => '45', 'stake' => '10.00'],
            ],
        ]);

        // The request is refused as a whole. See the report's CONFLICT section: the
        // verified Phase 4.3 engine asserts that it owns its transaction, so two
        // selections cannot share one atomic transaction without modifying financial code
        // this phase must not change silently. Refusing before any mutation is the only
        // answer that keeps the all-or-nothing guarantee intact.
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'multi_item_purchase_unsupported');

        // NOTHING happened. Not one bet, not one item, not one ticket, not one ledger
        // entry, and not one satang moved.
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame(0, BetItem::query()->count());
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());

        $fixture['wallet']->refresh();
        $this->assertSame('1000.00', (string) $fixture['wallet']->balance);
    }

    #[Test]
    public function aa_one_unsellable_item_prevents_the_whole_multi_item_purchase(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        // The first item is perfectly valid. The second is not.
        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, [
            'draw_id' => (int) $fixture['draw']->getKey(),
            'client_key' => $this->key('aa'),
            'items' => [
                ['market' => '3d_direct', 'number' => '123', 'stake' => '10.00'],
                ['market' => '3d_direct', 'number' => '99999', 'stake' => '10.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        // The valid first item was NOT sold. This is the partial-purchase failure mode the
        // whole architecture exists to prevent, and it is asserted directly.
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame(0, BetItem::query()->count());
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());

        $fixture['wallet']->refresh();
        $this->assertSame('1000.00', (string) $fixture['wallet']->balance);
    }

    // -----------------------------------------------------------------------------
    // AB-AG: all six markets sell through the API
    // -----------------------------------------------------------------------------

    #[Test]
    public function ab_three_digit_direct_sells_through_the_api(): void
    {
        $data = $this->sell('3d_direct', '123', '10.00', 'ab');

        $this->assertSame('123', $data['items'][0]['number']);
        $this->assertSame('10.00', $data['total_stake']);
        // 10.00 x 900, from config. Asserted against the config value below in AI.
        $this->assertSame('9000.00', $data['potential_payout']);
    }

    #[Test]
    public function ac_three_digit_tod_sells_through_the_api_as_one_charge(): void
    {
        $data = $this->sell('3d_tod', '123', '10.00', 'ac');

        $this->assertSame('123', $data['items'][0]['number']);

        // ONE item, ONE stake charge - not six, one per arrangement.
        $this->assertCount(1, $data['items']);
        $this->assertSame('10.00', $data['total_stake']);
        $this->assertSame(1, BetItem::query()->count());

        // The arrangements are recorded as coverage, and 123 has six of them.
        $metadata = $data['items'][0]['metadata'];
        $this->assertSame(6, $metadata['covered_number_count']);
        $this->assertSame(1, $metadata['charges']);

        // 10.00 x 45.
        $this->assertSame('450.00', $data['potential_payout']);

        // The wallet was debited once, for the single stake.
        $wallet = Wallet::query()->sole();
        $this->assertSame('990.00', (string) $wallet->balance);
    }

    #[Test]
    public function ac2_a_tod_selection_with_a_repeated_digit_covers_fewer_arrangements(): void
    {
        // 112 has three distinct arrangements, and 111 has one. The coverage count must
        // reflect that rather than always reporting six.
        $three = $this->sell('3d_tod', '112', '10.00', 'ac2a');
        $this->assertSame(3, $three['items'][0]['metadata']['covered_number_count']);
        $this->assertSame('10.00', $three['total_stake']);
    }

    #[Test]
    public function ac3_a_tod_selection_of_a_triple_covers_one_arrangement(): void
    {
        $one = $this->sell('3d_tod', '111', '10.00', 'ac3a');
        $this->assertSame(1, $one['items'][0]['metadata']['covered_number_count']);
        $this->assertSame('10.00', $one['total_stake']);
    }

    #[Test]
    public function ac4_a_tod_selection_of_007_keeps_its_leading_zeros(): void
    {
        $data = $this->sell('3d_tod', '007', '10.00', 'ac4a');

        $this->assertSame('007', $data['items'][0]['number']);
        $this->assertSame('007', BetItem::query()->sole()->number);

        // 007 has three arrangements (007, 070, 700) and every one of them is a
        // three-character string. If any had been reduced to the integer 7, this would
        // fail.
        $covered = $data['items'][0]['metadata']['covered_numbers'];
        $this->assertCount(3, $covered);

        foreach ($covered as $number) {
            $this->assertIsString($number);
            $this->assertSame(3, strlen($number));
        }

        $this->assertContains('007', $covered);
    }

    #[Test]
    public function ad_two_digit_top_sells_through_the_api(): void
    {
        $data = $this->sell('2d_top', '45', '10.00', 'ad');

        $this->assertSame('45', $data['items'][0]['number']);
        $this->assertSame('top', $data['items'][0]['position']);
        // 10.00 x 90.
        $this->assertSame('900.00', $data['potential_payout']);
    }

    #[Test]
    public function ae_two_digit_bottom_sells_through_the_api(): void
    {
        $data = $this->sell('2d_bottom', '45', '10.00', 'ae');

        $this->assertSame('45', $data['items'][0]['number']);
        $this->assertSame('bottom', $data['items'][0]['position']);
        $this->assertSame('900.00', $data['potential_payout']);
    }

    #[Test]
    public function af_run_top_sells_through_the_api(): void
    {
        $data = $this->sell('run_top', '7', '10.00', 'af');

        $this->assertSame('7', $data['items'][0]['number']);
        $this->assertSame('top', $data['items'][0]['position']);

        // 10.00 x 3, where the 3 is READ FROM CONFIG rather than written here. This is what
        // makes the single-digit rates safe even though the static scan cannot check them:
        // if the HTTP layer ever hard-coded a rate, it would disagree with configuration and
        // this assertion would fail.
        $rate = (string) config('lottery.markets.run_top.payout_multiplier');
        $this->assertSame($rate, (string) $data['items'][0]['payout_multiplier']);
        $this->assertSame(bcmul('10.00', $rate, 2), $data['potential_payout']);
        $this->assertSame('30.00', $data['potential_payout']);
    }

    #[Test]
    public function ag_run_bottom_sells_through_the_api(): void
    {
        $data = $this->sell('run_bottom', '7', '10.00', 'ag');

        $this->assertSame('7', $data['items'][0]['number']);
        $this->assertSame('bottom', $data['items'][0]['position']);

        // 10.00 x 4 - the configured run_bottom rate, which is NOT the same as run_top. Read
        // from config for the same reason as AF.
        $rate = (string) config('lottery.markets.run_bottom.payout_multiplier');
        $this->assertSame($rate, (string) $data['items'][0]['payout_multiplier']);
        $this->assertNotSame(
            (string) config('lottery.markets.run_top.payout_multiplier'),
            $rate,
            'run_top and run_bottom must not share a rate.',
        );
        $this->assertSame(bcmul('10.00', $rate, 2), $data['potential_payout']);
        $this->assertSame('40.00', $data['potential_payout']);
    }

    // -----------------------------------------------------------------------------
    // AH-AK: money and numbers survive the HTTP boundary intact
    // -----------------------------------------------------------------------------

    #[Test]
    public function ah_an_exact_decimal_stake_is_preserved_through_the_api(): void
    {
        // The configured step would reject a fractional stake, so it is relaxed for this
        // test only. The point under test is the API's handling of the decimal string, not
        // the step rule - which has its own Phase 4.2 coverage.
        config(['lottery.betting.amount_step' => null]);

        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.55', $this->key('ah')),
        );

        $response->assertStatus(201);

        // 10.55 in, '10.55' out - as a string, not 10.549999999999999.
        $response->assertJsonPath('data.total_stake', '10.55');
        $this->assertIsString($response->json('data.total_stake'));
        $this->assertSame('10.55', (string) Bet::query()->sole()->stake_amount);

        // 10.55 x 900 = 9495.00, exactly.
        $response->assertJsonPath('data.potential_payout', '9495.00');

        // The wallet balance is exact: 1000.00 - 10.55.
        $fixture['wallet']->refresh();
        $this->assertSame('989.45', (string) $fixture['wallet']->balance);
    }

    #[Test]
    public function ai_the_payout_multiplier_comes_from_the_authoritative_configuration(): void
    {
        $data = $this->sell('3d_direct', '123', '10.00', 'ai');

        $configured = (int) config('lottery.markets.3d_direct.payout_multiplier');

        // The rate in the response is the CONFIGURED rate, read from the same source of
        // truth the domain used. No rate is hard-coded in any Phase 4.4 file, so if an
        // operator changed the configuration, this assertion would follow it.
        $this->assertSame($configured, $data['items'][0]['payout_multiplier']);

        // And the payout is that rate applied to that stake, computed here with bcmath so
        // the check itself introduces no float error.
        $this->assertSame(
            bcmul($data['total_stake'], (string) $configured, 2),
            $data['potential_payout'],
        );
    }

    #[Test]
    public function aj_no_amount_in_the_response_is_a_json_number(): void
    {
        $data = $this->sell('3d_direct', '123', '10.00', 'aj');

        // Every money field crosses the wire as a JSON string. A float would be visible
        // here as an int/float type, and would be the first step towards a rounding error.
        foreach (['total_stake', 'potential_payout'] as $field) {
            $this->assertIsString($data[$field], sprintf('%s must be a JSON string.', $field));
        }

        $this->assertIsString($data['items'][0]['stake']);
        $this->assertIsString($data['items'][0]['potential_payout']);

        // A float-typed stake in the REQUEST is refused outright, rather than silently
        // accepted and coerced.
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '321');

        $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, [
            'draw_id' => (int) $fixture['draw']->getKey(),
            'client_key' => $this->key('aj2'),
            'items' => [
                // A JSON number, not a string.
                ['market' => '3d_direct', 'number' => '321', 'stake' => 10.5],
            ],
        ])->assertStatus(422);
    }

    #[Test]
    public function ak_an_integer_lottery_number_in_the_request_is_refused(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '007');

        // JSON integer 7. Accepting this would be the exact bug that turns '007' into 7,
        // so the API refuses it and asks for the string instead.
        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, [
            'draw_id' => (int) $fixture['draw']->getKey(),
            'client_key' => $this->key('ak'),
            'items' => [
                ['market' => '3d_direct', 'number' => 7, 'stake' => '10.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, Bet::query()->count());
    }

    // -----------------------------------------------------------------------------
    // AL-AM: rate limiting and production-shaped failures
    // -----------------------------------------------------------------------------

    #[Test]
    public function al_the_purchase_endpoint_is_rate_limited(): void
    {
        $fixture = $this->fixture();

        $limit = (int) config('security.rate_limits.bet.max_per_minute');
        $this->assertGreaterThan(0, $limit, 'A bet rate limit must be configured.');

        // Deliberately unsellable requests, so the limiter is exercised without buying
        // anything: the throttle middleware runs before validation, so these still count.
        $payload = $this->payload($fixture['draw'], '4d_direct', '1234', '10.00', $this->key('al'));

        for ($attempt = 1; $attempt <= $limit; $attempt++) {
            $payload['client_key'] = $this->key('al-'.$attempt);

            $this->actingAs($fixture['user'], 'sanctum')
                ->postJson(self::URI, $payload)
                ->assertStatus(422);
        }

        $payload['client_key'] = $this->key('al-over');

        $blocked = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, $payload);

        $blocked->assertStatus(429);
        $blocked->assertJsonPath('success', false);
        $blocked->assertJsonPath('error.code', 'rate_limited');

        // A well-behaved client is told when it may retry.
        $blocked->assertHeader('Retry-After');
    }

    #[Test]
    public function al2_the_rate_limit_is_scoped_per_user(): void
    {
        $one = $this->fixture();
        $two = $this->fixture();

        $limit = (int) config('security.rate_limits.bet.max_per_minute');

        $payload = $this->payload($one['draw'], '4d_direct', '1234', '10.00', $this->key('al2'));

        for ($attempt = 1; $attempt <= $limit; $attempt++) {
            $payload['client_key'] = $this->key('al2-'.$attempt);
            $this->actingAs($one['user'], 'sanctum')->postJson(self::URI, $payload);
        }

        // The first user is now blocked.
        $payload['client_key'] = $this->key('al2-over');
        $this->actingAs($one['user'], 'sanctum')
            ->postJson(self::URI, $payload)
            ->assertStatus(429);

        // The second user is not. One player's behaviour must not lock out another.
        $this->ensureLimit($two['draw'], '3d_direct', '123');

        $this->actingAs($two['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($two['draw'], '3d_direct', '123', '10.00', $this->key('al2-other')),
        )->assertStatus(201);
    }

    #[Test]
    public function am_an_unexpected_failure_never_exposes_a_stack_trace_or_internals(): void
    {
        // Debug is deliberately ENABLED. The API's error shape must not depend on a server
        // setting: a client must receive the same safe envelope either way.
        config(['app.debug' => true]);

        Route::middleware(['api'])->get('/api/v1/__phase44_failure_probe', function (): never {
            // A message shaped like the worst case: a driver error naming a table, a file
            // path and a query.
            throw new RuntimeException(
                'SQLSTATE[42S02]: Base table or view not found: 1146 '
                .'Table "thai_lottery.wallets" doesn\'t exist '
                .'(Connection: mysql, SQL: select * from `wallets` where `id` = 1) '
                .'in /var/www/app/Services/Finance/WalletService.php:118',
            );
        });

        $response = $this->getJson('/api/v1/__phase44_failure_probe');

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'transaction_failed');

        $body = (string) $response->getContent();

        foreach ([
            'SQLSTATE',
            'Base table',
            'select * from',
            '/var/www',
            'WalletService',
            'RuntimeException',
            'trace',
            'Stack trace',
            'line',
            'wallets',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                sprintf('A 500 response must not contain "%s".', $forbidden),
            );
        }

        // The envelope is still the documented one, so a client's error handling keeps
        // working during an outage.
        $response->assertJsonStructure(['success', 'error' => ['code', 'message', 'details']]);
    }

    #[Test]
    public function an_a_missing_client_key_is_refused(): void
    {
        $fixture = $this->fixture();

        $payload = $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('an'));
        unset($payload['client_key']);

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');

        // Field errors live under `error.details.fields` in this API's envelope, NOT under
        // Laravel's default top-level `errors` key, so assertJsonValidationErrors() would not
        // find them. Asserting on the real shape is what keeps the documented contract honest.
        $fields = $response->json('error.details.fields');
        $this->assertIsArray($fields);
        $this->assertArrayHasKey('client_key', $fields);

        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function ao_forbidden_financial_fields_are_refused(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        // Every one of these, individually, must be refused rather than ignored.
        $attempts = [
            ['wallet_id' => 1],
            ['payout_multiplier' => 999999],
            ['potential_payout' => '999999.00'],
            ['ledger_account_id' => 1],
            ['status' => 'won'],
            ['ticket_id' => 1],
            ['bypass_risk' => true],
            ['skip_risk' => true],
            ['force' => true],
            ['admin_override' => true],
            ['idempotency_key' => 'chosen-by-the-client'],
            ['uuid' => '00000000-0000-4000-8000-000000000000'],
        ];

        foreach ($attempts as $index => $extra) {
            // There are more attempts here than the per-minute bet limit allows, and the
            // point of THIS test is the field refusal, not the throttle (AL covers that).
            // Clearing the limiter's cache between attempts keeps a 429 from masking the
            // 422 that is actually under test.
            Cache::clear();

            $payload = $this->payload(
                $fixture['draw'],
                '3d_direct',
                '123',
                '10.00',
                $this->key('ao-'.$index),
            ) + $extra;

            $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, $payload);

            $response->assertStatus(422);
            $response->assertJsonPath(
                'error.code',
                'validation_failed',
                sprintf('The field "%s" must be refused.', array_key_first($extra)),
            );
        }

        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function ap_a_forbidden_field_inside_an_item_is_also_refused(): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(self::URI, [
            'draw_id' => (int) $fixture['draw']->getKey(),
            'client_key' => $this->key('ap'),
            'items' => [
                [
                    'market' => '3d_direct',
                    'number' => '123',
                    'stake' => '10.00',
                    // A client trying to price its own bet.
                    'payout_multiplier' => 999999,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function aq_a_suspended_account_cannot_purchase(): void
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        /** @var User $user */
        $user = $fixture['user'];
        $user->status = \App\Enums\UserStatus::Suspended;
        $user->save();

        $response = $this->actingAs($user, 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], '3d_direct', '123', '10.00', $this->key('aq')),
        );

        // Stopped by the existing EnsureUserIsActive middleware, before any application
        // code ran.
        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $this->assertSame(0, Bet::query()->count());
    }

    /**
     * Sell one item through the API and return the response's `data` payload.
     *
     * @return array<string, mixed>
     */
    private function sell(string $market, string $number, string $stake, string $keySeed): array
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], $market, $number);

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::URI,
            $this->payload($fixture['draw'], $market, $number, $stake, $this->key($keySeed)),
        );

        $response->assertStatus(201);

        /** @var array<string, mixed> $data */
        $data = $response->json('data');

        return $data;
    }
}
