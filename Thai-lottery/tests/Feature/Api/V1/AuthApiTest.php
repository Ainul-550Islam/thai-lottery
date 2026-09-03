<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The token surface added after Phase 5.1.
 *
 * These tests pin the two properties that matter most about a sign-in endpoint:
 * a valid credential yields a working token, and an invalid one yields the SAME
 * answer no matter why it was invalid, so the endpoint cannot be used to discover
 * which accounts exist.
 */
final class AuthApiTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * @var list<string>
     */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) DB::connection()->getDatabaseName();

        if (! in_array($database, self::ALLOWED_DATABASES, true)
            && ! in_array(basename($database), self::ALLOWED_DATABASES, true)) {
            $this->fail(sprintf('ABORTED: this suite may only run against a named test database, not "%s".', $database));
        }
    }

    #[Test]
    public function a_valid_email_and_password_issues_a_usable_token(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.token_type', 'Bearer');
        $response->assertJsonPath('data.user.id', (int) $user->getKey());

        $token = (string) $response->json('data.token');
        $this->assertNotSame('', $token);

        // The token actually opens the surface it was issued for.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.username', (string) $user->username);
    }

    #[Test]
    public function the_username_column_is_accepted_as_an_identifier(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->username,
            'password' => 'password',
        ])->assertStatus(201);
    }

    #[Test]
    public function the_response_never_reveals_the_password_or_the_hash(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('$2y$', $body);
    }

    #[Test]
    public function an_unknown_account_a_wrong_password_and_a_suspended_account_are_indistinguishable(): void
    {
        $active = User::factory()->create();
        $suspended = User::factory()->suspended()->create();

        $unknown = $this->postJson('/api/v1/auth/login', [
            'login' => 'nobody@example.test',
            'password' => 'password',
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'login' => $active->email,
            'password' => 'not-the-password',
        ]);

        $inactive = $this->postJson('/api/v1/auth/login', [
            'login' => $suspended->email,
            'password' => 'password',
        ]);

        foreach ([$unknown, $wrongPassword, $inactive] as $response) {
            $response->assertStatus(401);
            $response->assertJsonPath('success', false);
            $response->assertJsonPath('error.code', 'unauthenticated');
        }

        // Byte-for-byte identical bodies: no field, wording or ordering differs.
        $this->assertSame((string) $unknown->getContent(), (string) $wrongPassword->getContent());
        $this->assertSame((string) $unknown->getContent(), (string) $inactive->getContent());
    }

    #[Test]
    public function a_refused_attempt_is_recorded_with_its_real_reason_in_the_audit_trail(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(401);

        $row = DB::table('audit_logs')
            ->where('action', AuditAction::LoginFailed->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'A refused sign-in must leave an audit record.');
        $this->assertSame((int) $user->getKey(), (int) $row->user_id);

        $metadata = json_decode((string) $row->metadata, true);
        $this->assertSame('bad_password', $metadata['reason'] ?? null);
        // The audit row may name the account; it may never carry the submitted secret.
        $this->assertStringNotContainsString('not-the-password', (string) $row->metadata);
    }

    #[Test]
    public function a_successful_sign_in_is_audited_and_stamps_login_telemetry(): void
    {
        $user = User::factory()->create(['name' => 'Telemetry Probe']);
        $this->assertNull($user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertStatus(201);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_login_ip);

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('action', AuditAction::Login->value)
                ->where('user_id', (int) $user->getKey())
                ->count(),
        );
    }

    #[Test]
    public function logout_revokes_only_the_presented_token(): void
    {
        $user = User::factory()->create();

        $phone = (string) $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'phone',
        ])->json('data.token');

        $laptop = (string) $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'laptop',
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->count(),
            'Exactly the presented token must be gone, and the other one must remain.',
        );

        $this->forgetResolvedUser();

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->forgetResolvedUser();

        $this->withHeader('Authorization', 'Bearer '.$laptop)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    #[Test]
    public function issuing_a_second_token_for_the_same_device_retires_the_first(): void
    {
        $user = User::factory()->create();

        $first = (string) $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'phone',
        ])->json('data.token');

        $second = (string) $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'phone',
        ])->json('data.token');

        $this->assertNotSame($first, $second);

        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $this->forgetResolvedUser();

        $this->withHeader('Authorization', 'Bearer '.$first)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->forgetResolvedUser();

        $this->withHeader('Authorization', 'Bearer '.$second)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    #[Test]
    public function a_suspended_account_holding_a_valid_token_cannot_read_its_identity(): void
    {
        $user = User::factory()->create();

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        $user->forceFill(['status' => \App\Enums\UserStatus::Suspended])->save();

        $this->forgetResolvedUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403);
    }

    #[Test]
    public function the_identity_endpoint_refuses_an_unauthenticated_caller_in_the_project_envelope(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonStructure(['success', 'error' => ['code', 'message', 'details']]);
    }

    #[Test]
    public function a_malformed_login_payload_is_rejected_by_validation_not_by_the_query(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'login' => 'a',
            'password' => 'short',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function the_identity_payload_exposes_only_whitelisted_fields(): void
    {
        $user = User::factory()->create();

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        $payload = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->json('data.user');

        $this->assertSame(
            ['email', 'email_verified', 'id', 'name', 'roles', 'status', 'username'],
            collect(array_keys($payload))->sort()->values()->all(),
        );
    }

    /**
     * Forget the resolved guard between two requests in the same test.
     *
     * Illuminate\Auth\RequestGuard memoises the user it resolved, and the whole test
     * makes several requests through ONE application instance - so without this, a request
     * made after a token was revoked would still be answered from the cached identity and
     * the test would pass, or fail, for a reason that has nothing to do with the token.
     * Over real HTTP every request resolves from scratch; this reproduces that.
     */
    private function forgetResolvedUser(): void
    {
        $this->app->make('auth')->forgetGuards();
    }
}
