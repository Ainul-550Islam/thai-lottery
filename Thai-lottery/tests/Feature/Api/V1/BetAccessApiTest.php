<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Bet;
use App\Models\Ticket;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 4.4 - the read endpoints, and the IDOR protection around them.
 *
 * Each test buys through the real API first, so the bet being read is a genuine one
 * produced by the real purchase pipeline rather than a hand-built fixture that might not
 * resemble what the system actually stores.
 */
final class BetAccessApiTest extends ApiPurchaseTestCase
{
    private const PURCHASE_URI = '/api/v1/bets/purchase';

    // -----------------------------------------------------------------------------
    // U-W: one user cannot reach another user's resources
    // -----------------------------------------------------------------------------

    #[Test]
    public function u_another_users_bet_is_not_accessible(): void
    {
        $owner = $this->buy('u-owner');
        $intruder = $this->fixture();

        $betId = (int) Bet::query()->sole()->getKey();

        // The owner can read their own bet.
        $this->actingAs($owner['user'], 'sanctum')
            ->getJson('/api/v1/bets/'.$betId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $betId);

        // The intruder cannot - and is told the resource does not exist, rather than that
        // it exists and belongs to someone else. A 403 here would let them enumerate ids.
        $response = $this->actingAs($intruder['user'], 'sanctum')
            ->getJson('/api/v1/bets/'.$betId);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'resource_not_found');

        // No fragment of the owner's bet leaked into the refusal.
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('bet_number', $body);
        $this->assertStringNotContainsString('stake', $body);
    }

    #[Test]
    public function u2_another_users_bet_is_not_accessible_by_uuid_either(): void
    {
        $owner = $this->buy('u2-owner');
        $intruder = $this->fixture();

        $bet = Bet::query()->sole();

        if ($bet->uuid === null) {
            $this->markTestSkipped('This build does not assign bet uuids.');
        }

        $this->actingAs($owner['user'], 'sanctum')
            ->getJson('/api/v1/bets/'.$bet->uuid)
            ->assertStatus(200);

        $this->actingAs($intruder['user'], 'sanctum')
            ->getJson('/api/v1/bets/'.$bet->uuid)
            ->assertStatus(404);
    }

    #[Test]
    public function u3_another_users_bet_status_is_not_accessible(): void
    {
        $owner = $this->buy('u3-owner');
        $intruder = $this->fixture();

        $betId = (int) Bet::query()->sole()->getKey();

        $this->actingAs($owner['user'], 'sanctum')
            ->getJson('/api/v1/bets/'.$betId.'/status')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $this->actingAs($intruder['user'], 'sanctum')
            ->getJson('/api/v1/bets/'.$betId.'/status')
            ->assertStatus(404);
    }

    #[Test]
    public function v_another_users_ticket_is_not_accessible(): void
    {
        $owner = $this->buy('v-owner');
        $intruder = $this->fixture();

        $ticketId = (int) Ticket::query()->sole()->getKey();

        $this->actingAs($owner['user'], 'sanctum')
            ->getJson('/api/v1/tickets/'.$ticketId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $ticketId);

        $response = $this->actingAs($intruder['user'], 'sanctum')
            ->getJson('/api/v1/tickets/'.$ticketId);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'resource_not_found');
        $this->assertStringNotContainsString('ticket_number', (string) $response->getContent());
    }

    #[Test]
    public function w_a_hostile_route_parameter_cannot_leak_anything(): void
    {
        $this->buy('w-owner');
        $intruder = $this->fixture();

        $probes = [
            '0',
            '999999999',
            'not-a-uuid',
            '00000000-0000-4000-8000-000000000000',
        ];

        foreach ($probes as $probe) {
            $response = $this->actingAs($intruder['user'], 'sanctum')
                ->getJson('/api/v1/bets/'.$probe);

            // Every probe answers 404 in the documented envelope. None answers 200, none
            // answers 500, and none returns a database error.
            $this->assertContains(
                $response->status(),
                [404],
                sprintf('The probe "%s" must answer 404, not %d.', $probe, $response->status()),
            );

            $response->assertJsonPath('success', false);
            $this->assertStringNotContainsString('SQL', (string) $response->getContent());
        }
    }

    #[Test]
    public function w2_route_parameters_that_cannot_be_identifiers_never_reach_the_query(): void
    {
        $intruder = $this->fixture();

        // These do not match the route constraint at all, so they resolve to no route.
        // They still come back as the API envelope rather than an HTML error page.
        foreach (['1 OR 1=1', "1'; drop table bets;--", '../../etc/passwd'] as $probe) {
            $response = $this->actingAs($intruder['user'], 'sanctum')
                ->getJson('/api/v1/bets/'.urlencode($probe));

            $this->assertContains($response->status(), [404, 400]);
            $this->assertStringNotContainsString('SQL', (string) $response->getContent());
            $this->assertStringNotContainsString('Exception', (string) $response->getContent());
        }

        // And the table is intact.
        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function x_the_read_endpoints_require_authentication(): void
    {
        $this->buy('read-auth');

        $betId = (int) Bet::query()->sole()->getKey();
        $ticketId = (int) Ticket::query()->sole()->getKey();

        // buy() had to authenticate to create the fixture, and actingAs() keeps that user
        // resolved on the guard for the REST of the test. Forgetting the guards puts the
        // request back to genuinely anonymous, which is the condition under test - without
        // this the assertion below would be made against a still-authenticated request and
        // would prove nothing.
        Auth::forgetGuards();

        foreach ([
            '/api/v1/bets/'.$betId,
            '/api/v1/bets/'.$betId.'/status',
            '/api/v1/tickets/'.$ticketId,
        ] as $uri) {
            $response = $this->getJson($uri);

            $response->assertStatus(401);
            $response->assertJsonPath('error.code', 'unauthenticated');
        }
    }

    #[Test]
    public function y_the_bet_read_response_exposes_only_safe_fields(): void
    {
        $owner = $this->buy('read-shape');

        $betId = (int) Bet::query()->sole()->getKey();

        $response = $this->actingAs($owner['user'], 'sanctum')->getJson('/api/v1/bets/'.$betId);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'bet_number',
                'draw_id',
                'status',
                'stake',
                'potential_payout',
                'items' => [['number', 'stake', 'payout_multiplier', 'potential_payout']],
            ],
        ]);

        // Money is a string, and the leading-zero number survives the read path too.
        $this->assertIsString($response->json('data.stake'));
        $this->assertSame('10.00', $response->json('data.stake'));
        $this->assertSame('007', $response->json('data.items.0.number'));

        $body = (string) $response->getContent();

        foreach (['user_id', 'wallet_id', 'ledger', 'idempotency_key', 'password'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                sprintf('The bet read response must not contain "%s".', $forbidden),
            );
        }
    }

    #[Test]
    public function z_the_ticket_read_response_nests_only_the_owners_bets(): void
    {
        $owner = $this->buy('ticket-shape');

        $ticketId = (int) Ticket::query()->sole()->getKey();

        $response = $this->actingAs($owner['user'], 'sanctum')
            ->getJson('/api/v1/tickets/'.$ticketId);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['id', 'ticket_number', 'status', 'total_amount', 'bets'],
        ]);

        $this->assertIsString($response->json('data.total_amount'));
        $this->assertSame('10.00', $response->json('data.total_amount'));

        /** @var array<int, array<string, mixed>> $bets */
        $bets = $response->json('data.bets');

        $this->assertCount(1, $bets);
        $this->assertSame((int) Bet::query()->sole()->getKey(), $bets[0]['id']);
    }

    #[Test]
    public function aa_the_read_endpoints_perform_no_writes(): void
    {
        $owner = $this->buy('read-only');

        $bet = Bet::query()->sole();
        $ticket = Ticket::query()->sole();

        $betUpdatedAt = (string) $bet->updated_at;
        $ticketUpdatedAt = (string) $ticket->updated_at;
        $balanceBefore = (string) $owner['wallet']->refresh()->balance;

        $betId = (int) $bet->getKey();

        $this->actingAs($owner['user'], 'sanctum')->getJson('/api/v1/bets/'.$betId)->assertStatus(200);
        $this->actingAs($owner['user'], 'sanctum')->getJson('/api/v1/bets/'.$betId.'/status')->assertStatus(200);
        $this->actingAs($owner['user'], 'sanctum')->getJson('/api/v1/tickets/'.$ticket->getKey())->assertStatus(200);

        // Nothing was touched by reading.
        $this->assertSame($betUpdatedAt, (string) $bet->fresh()->updated_at);
        $this->assertSame($ticketUpdatedAt, (string) $ticket->fresh()->updated_at);
        $this->assertSame($balanceBefore, (string) $owner['wallet']->refresh()->balance);
        $this->assertSame(1, Bet::query()->count());
    }

    /**
     * Buy one 3D Direct bet on '007' through the real API.
     *
     * '007' is used deliberately so every read-path test also proves the leading zeros
     * survive being read back out, not only being written in.
     *
     * @return array{user: \App\Models\User, wallet: \App\Models\Wallet, draw: \App\Models\Draw}
     */
    private function buy(string $keySeed): array
    {
        $fixture = $this->fixture();
        $this->ensureLimit($fixture['draw'], '3d_direct', '007');

        $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::PURCHASE_URI,
            $this->payload($fixture['draw'], '3d_direct', '007', '10.00', $this->key($keySeed)),
        )->assertStatus(201);

        return $fixture;
    }
}
