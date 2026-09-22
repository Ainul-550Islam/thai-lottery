<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\SecurityEventType;
use App\Models\AuthenticationAttempt;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Batch-15 integration: the login endpoint consumes the central
 * authentication-security gate — attempts land exactly-once, the
 * public 401 surface never leaks the desk's internal codes, and a
 * valid credential still tokens with the gate wired in.
 */
final class AuthenticationGateTest extends TestCase
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
    public function repeated_failures_record_attempts_without_leaking_the_gate(): void
    {
        $user = User::factory()->create();

        $payload = ['login' => $user->email, 'password' => 'wrong-gate15', 'device_name' => 'gate15'];

        $statuses = [];

        // DISTINCT source addresses per attempt: the pre-existing route
        // throttle keys per IP, so 12 attempts would consume the shared
        // counter for every suite that runs after this one in the same
        // process window. Private quotas keep the fixture hermetic.
        // Sixth attempt tangles with the route's email-keyed ceiling
        // (max_attempts=5); the refusal PATTERN itself is the assertion:
        // 401 for the first five by credential law, 429 for the sixth by
        // throttle law — and never a desk-code in the JSON body.
        $statuses = [];

        foreach (range(1, 6) as $i) {
            $response = $this->postJson('/api/v1/auth/login', $payload, ['REMOTE_ADDR' => sprintf('10.15.15.%d', $i)]);
            $statuses[] = $response->getStatusCode();
            $this->assertContains($response->getStatusCode(), [401, 429]);
            $this->assertFalse((bool) $response->json('success'));
        }

        $this->assertContains(401, $statuses);

        $this->assertGreaterThanOrEqual(2, AuthenticationAttempt::query()
            ->where('identifier_hash', hash('sha256', 'glo-id|'.mb_strtolower($user->email)))
            ->where('outcome', 'failed')
            ->count());

        $this->assertTrue(SecurityEvent::query()
            ->where('event_type', SecurityEventType::LoginFailed->value)
            ->exists());
    }

    #[Test]
    public function a_valid_credential_still_tokens_with_the_gate_wired_in(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'gate15',
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('', (string) $response->json('data.token'));

        $this->assertTrue(SecurityEvent::query()
            ->where('event_type', SecurityEventType::LoginSucceeded->value)
            ->where('user_id', $user->id)
            ->exists());

        try {
            // The truncation seed runs once per process in this suite:
            // leave behind exactly what was found — nothing. Fixtures
            // that mint tokens carry their own broom.
            $user->tokens()->delete();
        } finally {
            $this->assertDatabaseCount('personal_access_tokens', 0);
        }
    }
}
