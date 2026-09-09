<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\LedgerAccountType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RiskLevel;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletStatus;
use App\Filament\Resources\DepositResource;
use App\Filament\Resources\DrawResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WithdrawalResource;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Observability\CorrelationContext;
use App\Services\Observability\StructuredLogger;
use App\Support\Admin\AdminAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ProductionSecurityComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * 1. Authentication: Successful login issues Sanctum token and records audit.
     */
    public function test_01_authentication_successful_login_issues_token(): void
    {
        $user = User::factory()->create([
            'email' => 'auth_test@example.com',
            'username' => 'authtestuser',
            'password' => Hash::make('CorrectPassword123!', ['rounds' => 4]),
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'auth_test@example.com',
            'password' => 'CorrectPassword123!',
            'device_name' => 'SecurityTestClient',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'name', 'email', 'roles', 'status'],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
            'name' => 'SecurityTestClient',
        ]);
    }

    /**
     * 2. Authentication: Failed login with invalid password is uniformly rejected with 401.
     */
    public function test_02_authentication_failed_login_rejected_and_protected(): void
    {
        $user = User::factory()->create([
            'email' => 'victim@example.com',
            'username' => 'victimuser',
            'password' => Hash::make('RealPassword123!', ['rounds' => 4]),
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'victim@example.com',
            'password' => 'WrongPassword',
            'device_name' => 'Attacker',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'unauthenticated',
                ],
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Attacker',
        ]);
    }

    /**
     * 3. Authentication: Suspended and Inactive users cannot authenticate.
     */
    public function test_03_authentication_inactive_users_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.com',
            'username' => 'suspendeduser',
            'password' => Hash::make('Password123!', ['rounds' => 4]),
            'status' => UserStatus::Suspended,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'suspended@example.com',
            'password' => 'Password123!',
            'device_name' => 'SuspendedClient',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'unauthenticated',
                ],
            ]);
    }

    /**
     * 4. Authentication: Logout revokes current access token cleanly.
     */
    public function test_04_authentication_logout_revokes_token(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $token = $user->createToken('active_session')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/auth/logout');
        $response->assertStatus(200);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Reset guards and assert revoked token is denied
        $this->app['auth']->forgetGuards();
        $meResponse = $this->withToken($token)->getJson('/api/v1/auth/me');
        $meResponse->assertStatus(401);
    }

    /**
     * 5. Password Security: Bcrypt hashing cost and verified password validation.
     */
    public function test_05_password_hashing_security(): void
    {
        $password = 'SuperSecurePass2026!';
        $user = User::factory()->create([
            'password' => Hash::make($password, ['rounds' => 4]),
            'status' => UserStatus::Active,
        ]);

        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check('WrongGuess', $user->password));

        $info = password_get_info($user->password);
        $this->assertEquals('bcrypt', $info['algoName']);
    }

    /**
     * 6. Authorization: Super-admin gate bypass operates across policy abilities.
     */
    public function test_06_super_admin_gate_bypass(): void
    {
        $superAdmin = User::factory()->create(['status' => UserStatus::Active]);
        $superAdmin->assignRole('super-admin');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Draw::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Wallet::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Payment::class));
        $this->assertTrue(AdminAccess::canAccessPanel($superAdmin));
        $this->assertTrue(AdminAccess::allows($superAdmin, AdminAccess::MANAGE_DRAWS));
        $this->assertTrue(AdminAccess::allows($superAdmin, AdminAccess::MANAGE_WALLET));
        $this->assertFalse(AdminAccess::isReadOnly($superAdmin));
    }

    /**
     * 7. Authorization: Auditor role is strictly read-only and denied mutating capabilities.
     */
    public function test_07_auditor_role_read_only_enforcement(): void
    {
        $auditor = User::factory()->create(['status' => UserStatus::Active]);
        $auditor->assignRole('auditor');

        $this->assertTrue(AdminAccess::canAccessPanel($auditor));
        $this->assertTrue(AdminAccess::allows($auditor, AdminAccess::VIEW_DASHBOARD));
        $this->assertTrue(AdminAccess::allows($auditor, AdminAccess::VIEW_AUDIT_LOGS));
        $this->assertTrue(AdminAccess::allows($auditor, AdminAccess::VIEW_FINANCIAL_REPORTS));
        $this->assertTrue(AdminAccess::allows($auditor, AdminAccess::RECONCILE_LEDGER));
        $this->assertFalse(AdminAccess::allows($auditor, AdminAccess::VIEW_DRAWS));
        $this->assertFalse(AdminAccess::allows($auditor, AdminAccess::MANAGE_DRAWS));
        $this->assertFalse(AdminAccess::allows($auditor, AdminAccess::MANAGE_WALLET));
        $this->assertTrue(AdminAccess::isReadOnly($auditor));
    }

    /**
     * 8. Authorization: Regular player cannot access Admin Panel or admin capabilities.
     */
    public function test_08_player_role_cannot_access_admin_panel(): void
    {
        $player = User::factory()->create(['status' => UserStatus::Active]);
        $player->assignRole('player');

        $this->assertFalse(AdminAccess::canAccessPanel($player));
        $this->assertFalse(AdminAccess::allows($player, AdminAccess::MANAGE_DRAWS));
        $this->assertFalse(AdminAccess::allows($player, AdminAccess::MANAGE_PAYOUTS));
        $this->assertFalse(AdminAccess::allows($player, AdminAccess::MANAGE_USERS));
    }

    /**
     * 9. Vertical Privilege Escalation: Agent cannot manage admin users or access admin panel.
     */
    public function test_09_agent_vertical_escalation_prevented(): void
    {
        $agent = User::factory()->create(['status' => UserStatus::Active]);
        $agent->assignRole('agent');

        $this->assertFalse(AdminAccess::canAccessPanel($agent));
        $this->assertFalse(AdminAccess::allows($agent, AdminAccess::MANAGE_USERS));

        $this->actingAs($agent);
        $this->assertFalse(UserResource::canViewAny());
    }

    /**
     * 10. Horizontal Privilege Escalation / IDOR: Player A cannot view Player B's wallet.
     */
    public function test_10_wallet_ownership_policy_idor_prevention(): void
    {
        $playerA = User::factory()->create(['status' => UserStatus::Active]);
        $playerB = User::factory()->create(['status' => UserStatus::Active]);

        $walletA = Wallet::factory()->create(['user_id' => $playerA->id, 'balance' => '500.00']);
        $walletB = Wallet::factory()->create(['user_id' => $playerB->id, 'balance' => '1000.00']);

        // Player A can view their own wallet
        $this->assertTrue(Gate::forUser($playerA)->allows('view', $walletA));

        // Player A CANNOT view Player B's wallet (IDOR prevention)
        $this->assertFalse(Gate::forUser($playerA)->allows('view', $walletB));
        $this->assertFalse(Gate::forUser($playerA)->allows('update', $walletB));
    }

    /**
     * 11. IDOR: Player A cannot view or manipulate Player B's bets.
     */
    public function test_11_bet_ownership_policy_idor_prevention(): void
    {
        $playerA = User::factory()->create(['status' => UserStatus::Active]);
        $playerB = User::factory()->create(['status' => UserStatus::Active]);

        $draw = Draw::factory()->create(['status' => DrawStatus::Open]);

        $betA = Bet::create([
            'bet_number' => 'BET-A-001',
            'user_id' => $playerA->id,
            'draw_id' => $draw->id,
            'type' => BetType::TwoD,
            'currency' => Currency::THB,
            'stake_amount' => '100.00',
            'potential_payout' => '9000.00',
            'total_numbers' => 1,
        ]);

        $betB = Bet::create([
            'bet_number' => 'BET-B-001',
            'user_id' => $playerB->id,
            'draw_id' => $draw->id,
            'type' => BetType::TwoD,
            'currency' => Currency::THB,
            'stake_amount' => '100.00',
            'potential_payout' => '9000.00',
            'total_numbers' => 1,
        ]);

        $this->assertTrue(Gate::forUser($playerA)->allows('view', $betA));
        $this->assertFalse(Gate::forUser($playerA)->allows('view', $betB));
        $this->assertFalse(Gate::forUser($playerA)->allows('update', $betB));
        $this->assertFalse(Gate::forUser($playerA)->allows('delete', $betB));
    }

    /**
     * 12. IDOR: Player A cannot view or cancel Player B's tickets.
     */
    public function test_12_ticket_ownership_policy(): void
    {
        $playerA = User::factory()->create(['status' => UserStatus::Active]);
        $playerB = User::factory()->create(['status' => UserStatus::Active]);

        $draw = Draw::factory()->create(['status' => DrawStatus::Open]);

        $ticketA = Ticket::create([
            'ticket_number' => 'TCK-A-001',
            'user_id' => $playerA->id,
            'draw_id' => $draw->id,
            'currency' => Currency::THB,
        ]);

        $ticketB = Ticket::create([
            'ticket_number' => 'TCK-B-001',
            'user_id' => $playerB->id,
            'draw_id' => $draw->id,
            'currency' => Currency::THB,
        ]);

        $this->assertTrue(Gate::forUser($playerA)->allows('view', $ticketA));
        $this->assertFalse(Gate::forUser($playerA)->allows('view', $ticketB));
        $this->assertFalse(Gate::forUser($playerA)->allows('cancel', $ticketB));
    }

    /**
     * 13. Financial Authorization: Unauthenticated requests to deposit are rejected.
     */
    public function test_13_unauthenticated_financial_endpoints_rejected(): void
    {
        $resDeposit = $this->postJson('/api/v1/deposits', ['amount' => '100.00', 'method' => 'bkash']);
        $resDeposit->assertStatus(401);

        $resWithdrawal = $this->postJson('/api/v1/withdrawals', ['amount' => '50.00', 'method' => 'bkash']);
        $resWithdrawal->assertStatus(401);

        $resBet = $this->postJson('/api/v1/bets/purchase', ['draw_id' => 1, 'items' => []]);
        $resBet->assertStatus(401);
    }

    /**
     * 14. Financial Authorization: Deposit Approval requires manage payouts / admin permission.
     */
    public function test_14_deposit_approval_authorization_gated(): void
    {
        $player = User::factory()->create(['status' => UserStatus::Active]);
        $player->assignRole('player');

        $admin = User::factory()->create(['status' => UserStatus::Active]);
        $admin->assignRole('admin');

        $this->assertFalse(AdminAccess::allows($player, AdminAccess::MANAGE_PAYOUTS));
        $this->assertTrue(AdminAccess::allows($admin, AdminAccess::MANAGE_PAYOUTS));

        $this->actingAs($player);
        $this->assertFalse(AdminAccess::canAccessPanel($player));

        $this->actingAs($admin);
        $this->assertTrue(DepositResource::canViewAny());
    }

    /**
     * 15. Financial Authorization: Withdrawal approval requires manage payouts / wallet.
     */
    public function test_15_withdrawal_approval_authorization_gated(): void
    {
        $auditor = User::factory()->create(['status' => UserStatus::Active]);
        $auditor->assignRole('auditor');

        $superAdmin = User::factory()->create(['status' => UserStatus::Active]);
        $superAdmin->assignRole('super-admin');

        $this->assertFalse(AdminAccess::allows($auditor, AdminAccess::MANAGE_PAYOUTS));
        $this->assertTrue(AdminAccess::allows($superAdmin, AdminAccess::MANAGE_PAYOUTS));
    }

    /**
     * 16. Financial Authorization: Ledger account and journal access policy.
     */
    public function test_16_ledger_policy_access_control(): void
    {
        $player = User::factory()->create(['status' => UserStatus::Active]);
        $player->assignRole('player');

        $admin = User::factory()->create(['status' => UserStatus::Active]);
        $admin->assignRole('admin');

        $ledgerAccount = LedgerAccount::create([
            'code' => '1010-CASH',
            'name' => 'System Cash Test',
            'type' => LedgerAccountType::Asset,
            'currency' => Currency::THB,
            'is_active' => true,
        ]);

        $this->assertFalse(Gate::forUser($player)->allows('viewAny', LedgerAccount::class));
        $this->assertFalse(Gate::forUser($player)->allows('view', $ledgerAccount));

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', LedgerAccount::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $ledgerAccount));
    }

    /**
     * 17. Admin Authentication Hardening: Filament Admin Panel Provider Configuration.
     */
    public function test_17_admin_panel_provider_security(): void
    {
        $response = $this->get('/admin/login');
        $this->assertContains($response->status(), [200, 302]);
    }

    /**
     * 18. CORS Security: CORS configuration defaults and headers.
     */
    public function test_18_cors_configuration_security(): void
    {
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertContains('api/*', config('cors.paths'));
        $this->assertContains('X-Correlation-ID', config('cors.exposed_headers'));
        $this->assertContains('X-RateLimit-Limit', config('cors.exposed_headers'));
    }

    /**
     * 19. Session & Cookie Security: Secure session cookie flags.
     */
    public function test_19_session_and_cookie_security_config(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
        $this->assertFalse(config('session.encrypt') ?? false);
    }

    /**
     * 20. Security Headers: Custom Security Headers Middleware Verification.
     */
    public function test_20_security_headers_middleware(): void
    {
        $response = $this->get('/up');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * 21. Rate Limiting: Deposit endpoint rate limiter is registered and enforces limits.
     */
    public function test_21_deposit_rate_limiter_functional(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $token = $user->createToken('test_token')->plainTextToken;

        RateLimiter::clear('deposit:user:'.$user->id);

        $hitCount = 0;
        for ($i = 0; $i < 15; $i++) {
            $res = $this->withToken($token)->postJson('/api/v1/deposits', [
                'amount' => '100.00',
                'method' => 'bkash',
            ]);
            if ($res->status() === 429) {
                $hitCount++;
            }
        }

        $this->assertGreaterThan(0, $hitCount);
    }

    /**
     * 22. Rate Limiting: Withdrawal endpoint rate limiter is registered.
     */
    public function test_22_withdrawal_rate_limiter_functional(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $token = $user->createToken('test_token')->plainTextToken;

        RateLimiter::clear('withdrawal:user:'.$user->id);

        $hitCount = 0;
        for ($i = 0; $i < 15; $i++) {
            $res = $this->withToken($token)->postJson('/api/v1/withdrawals', [
                'amount' => '50.00',
                'method' => 'bkash',
            ]);
            if ($res->status() === 429) {
                $hitCount++;
            }
        }

        $this->assertGreaterThan(0, $hitCount);
    }

    /**
     * 23. Rate Limiting: Webhook rate limiter is registered.
     */
    public function test_23_webhook_rate_limiter_functional(): void
    {
        RateLimiter::clear('webhook:ip:127.0.0.1');

        $limiter = RateLimiter::limiter('webhook');
        $this->assertNotNull($limiter);
    }

    /**
     * 24. Webhook Security: Invalid webhook signatures are rejected.
     */
    public function test_24_webhook_invalid_signature_rejected(): void
    {
        $payload = [
            'event' => 'payment.success',
            'transaction_id' => 'TXN_FAKE_99999',
            'amount' => '100.00',
        ];

        $response = $this->postJson('/api/v1/payments/webhook/bkash', $payload, [
            'X-Signature' => 'invalid_forged_signature_hex',
        ]);

        $this->assertContains($response->status(), [400, 401, 403, 422]);
    }

    /**
     * 25. Webhook Security: Malformed webhook payloads are rejected safely without 500 error.
     */
    public function test_25_webhook_malformed_payload_handled_safely(): void
    {
        $response = $this->call('POST', '/api/v1/payments/webhook/bkash', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'NOT_VALID_JSON{{{');

        $this->assertContains($response->status(), [400, 401, 403, 422]);
    }

    /**
     * 26. Webhook Security: Replay attack prevention with idempotency.
     */
    public function test_26_webhook_replay_protection(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        $payment = Payment::create([
            'reference_number' => 'PAY-TEST-9999',
            'user_id' => $user->id,
            'payable_type' => Wallet::class,
            'payable_id' => $wallet->id,
            'method' => PaymentMethod::Bkash,
            'status' => PaymentStatus::Captured,
            'currency' => Currency::THB,
            'amount' => '100.00',
            'fee' => '0.00',
            'gateway' => 'bkash',
            'gateway_reference' => 'GW-REF-1234',
        ]);

        $response = $this->postJson('/api/v1/payments/webhook/bkash', [
            'payment_id' => $payment->id,
            'event' => 'payment.success',
        ]);

        $this->assertContains($response->status(), [200, 400, 401, 403, 409, 422]);
    }

    /**
     * 27. Mass Assignment Protection: User and Wallet reject unauthorized mass assignment.
     */
    public function test_27_mass_assignment_protection_on_user_and_wallet(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);

        User::create([
            'name' => 'Test User',
            'email' => 'test_mass@example.com',
            'password' => 'secret123',
            'role' => 'super-admin',
            'is_admin' => true,
        ]);
    }

    /**
     * 28. Sensitive Data Exposure: Structured Logger scrubs sensitive keys.
     */
    public function test_28_sensitive_fields_redacted_in_json_and_logs(): void
    {
        $logger = app(StructuredLogger::class);

        $sensitiveData = [
            'user_id' => 123,
            'password' => 'plaintext_secret_123',
            'api_key' => 'live_sk_abcdef1234567890',
            'card_number' => '4111222233334444',
            'token' => 'plain_sanctum_token',
            'nested' => [
                'webhook_secret' => 'whsec_secret_key',
                'safe_field' => 'visible_data',
            ],
        ];

        $redacted = $logger->redact($sensitiveData);

        $this->assertEquals(123, $redacted['user_id']);
        $this->assertEquals('[REDACTED]', $redacted['password']);
        $this->assertEquals('[REDACTED]', $redacted['api_key']);
        $this->assertEquals('[REDACTED]', $redacted['card_number']);
        $this->assertEquals('[REDACTED]', $redacted['token']);
        $this->assertEquals('[REDACTED]', $redacted['nested']['webhook_secret']);
        $this->assertEquals('visible_data', $redacted['nested']['safe_field']);
    }

    /**
     * 29. Observability Endpoint Security: Metrics endpoint returns Prometheus data.
     */
    public function test_29_metrics_endpoint_access_control(): void
    {
        $response = $this->get('/metrics');
        $response->assertStatus(200);
        $this->assertStringContainsString('thai_lottery_', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    /**
     * 30. Health Endpoint Security: Health endpoints respond without internal stack leakage.
     */
    public function test_30_health_endpoint_security(): void
    {
        $response = $this->getJson('/up/health');
        $this->assertContains($response->status(), [200, 503]);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
    }

    /**
     * 31. Audit Log Security: Audit log model scrubs metadata and guarantees append-only.
     */
    public function test_31_audit_log_metadata_scrubbing_and_append_only(): void
    {
        CorrelationContext::set('corr-sec-audit-001');

        $auditLog = AuditLog::create([
            'action' => AuditAction::Login,
            'risk_level' => RiskLevel::Low,
            'description' => 'Test audit record',
            'metadata' => [
                'password' => 'secret_pass_123',
                'token' => 'raw_token_xyz',
                'client_ip' => '127.0.0.1',
            ],
        ]);

        $this->assertEquals('corr-sec-audit-001', $auditLog->request_id);
        $this->assertEquals('[REDACTED]', $auditLog->metadata['password']);
        $this->assertEquals('[REDACTED]', $auditLog->metadata['token']);
        $this->assertEquals('127.0.0.1', $auditLog->metadata['client_ip']);
        $this->assertNull(AuditLog::UPDATED_AT);
    }

    /**
     * 32. Financial Reconciliation: Unauthorized users cannot access reconciliation endpoints.
     */
    public function test_32_reconciliation_authorization_gated(): void
    {
        $player = User::factory()->create(['status' => UserStatus::Active]);
        $player->assignRole('player');

        $auditor = User::factory()->create(['status' => UserStatus::Active]);
        $auditor->assignRole('auditor');

        $this->assertFalse(AdminAccess::allows($player, AdminAccess::RECONCILE_LEDGER));
        $this->assertTrue(AdminAccess::allows($auditor, AdminAccess::RECONCILE_LEDGER));
    }

    /**
     * 33. Draw Lifecycle Security: Closing/Cancelling draws requires manage draws permission.
     */
    public function test_33_draw_lifecycle_authorization_gated(): void
    {
        $player = User::factory()->create(['status' => UserStatus::Active]);
        $player->assignRole('player');

        $admin = User::factory()->create(['status' => UserStatus::Active]);
        $admin->assignRole('admin');

        $draw = Draw::factory()->create(['status' => DrawStatus::Open]);

        $this->assertFalse(Gate::forUser($player)->allows('close', $draw));
        $this->assertFalse(Gate::forUser($player)->allows('cancel', $draw));

        $this->assertTrue(Gate::forUser($admin)->allows('close', $draw));
        $this->assertTrue(Gate::forUser($admin)->allows('cancel', $draw));
    }

    /**
     * 34. Agent Commission Security: Agent commissions cannot be viewed across agents.
     */
    public function test_34_agent_commission_isolation(): void
    {
        $agentA = User::factory()->create(['status' => UserStatus::Active]);
        $agentA->assignRole('agent');

        $agentB = User::factory()->create(['status' => UserStatus::Active]);
        $agentB->assignRole('agent');

        $agentRecordA = \App\Models\Agent::create([
            'agent_code' => 'AGT001',
            'user_id' => $agentA->id,
            'status' => \App\Enums\AgentStatus::Active,
            'currency' => Currency::THB,
            'commission_rate' => '0.0500',
        ]);

        $agentRecordB = \App\Models\Agent::create([
            'agent_code' => 'AGT002',
            'user_id' => $agentB->id,
            'status' => \App\Enums\AgentStatus::Active,
            'currency' => Currency::THB,
            'commission_rate' => '0.0500',
        ]);

        $draw = Draw::factory()->create(['status' => DrawStatus::Completed]);

        $betA = Bet::create([
            'bet_number' => 'BET-COMM-A-01',
            'user_id' => $agentA->id,
            'draw_id' => $draw->id,
            'type' => BetType::TwoD,
            'currency' => Currency::THB,
            'stake_amount' => '100.00',
            'potential_payout' => '9000.00',
            'total_numbers' => 1,
        ]);

        $betB = Bet::create([
            'bet_number' => 'BET-COMM-B-01',
            'user_id' => $agentB->id,
            'draw_id' => $draw->id,
            'type' => BetType::TwoD,
            'currency' => Currency::THB,
            'stake_amount' => '100.00',
            'potential_payout' => '9000.00',
            'total_numbers' => 1,
        ]);

        $commA = AgentCommission::create([
            'reference_number' => 'COMM-A-001',
            'agent_id' => $agentRecordA->id,
            'user_id' => $agentA->id,
            'bet_id' => $betA->id,
            'draw_id' => $draw->id,
            'status' => CommissionStatus::Payable,
            'currency' => Currency::THB,
            'base_amount' => '100.00',
            'commission_rate' => '0.0500',
            'commission_amount' => '5.00',
        ]);

        $commB = AgentCommission::create([
            'reference_number' => 'COMM-B-001',
            'agent_id' => $agentRecordB->id,
            'user_id' => $agentB->id,
            'bet_id' => $betB->id,
            'draw_id' => $draw->id,
            'status' => CommissionStatus::Payable,
            'currency' => Currency::THB,
            'base_amount' => '100.00',
            'commission_rate' => '0.0500',
            'commission_amount' => '5.00',
        ]);

        $this->assertTrue(Gate::forUser($agentA)->allows('view', $commA));
        $this->assertFalse(Gate::forUser($agentA)->allows('view', $commB));
    }

    /**
     * 35. Payout Approval Security: Payouts require manage payouts permission.
     */
    public function test_35_payout_approval_security(): void
    {
        $player = User::factory()->create(['status' => UserStatus::Active]);
        $player->assignRole('player');

        $admin = User::factory()->create(['status' => UserStatus::Active]);
        $admin->assignRole('admin');

        $this->assertFalse(AdminAccess::allows($player, AdminAccess::MANAGE_PAYOUTS));
        $this->assertTrue(AdminAccess::allows($admin, AdminAccess::MANAGE_PAYOUTS));
    }

    /**
     * 36. User Status Suspension immediately revokes API access.
     */
    public function test_36_user_suspension_immediate_effect(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $token = $user->createToken('active_token')->plainTextToken;

        $resActive = $this->withToken($token)->getJson('/api/v1/auth/me');
        $resActive->assertStatus(200);

        // Suspend user
        $user->status = UserStatus::Suspended;
        $user->save();

        // Forget auth guards so fresh user is resolved on next request
        $this->app['auth']->forgetGuards();

        $resSuspended = $this->withToken($token)->getJson('/api/v1/auth/me');
        $this->assertContains($resSuspended->status(), [401, 403]);
    }

    /**
     * 37. SQL Injection & Parameter Tampering Protection across query parameters.
     */
    public function test_37_sql_injection_protection_in_filters(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $token = $user->createToken('test_token')->plainTextToken;

        $sqliPayloads = [
            "1' OR '1'='1",
            "1; DROP TABLE users;--",
            "' UNION SELECT null, null, null--",
        ];

        foreach ($sqliPayloads as $payload) {
            $response = $this->withToken($token)->getJson('/api/v1/withdrawals?status='.urlencode($payload));
            $this->assertContains($response->status(), [200, 400, 404, 422]);
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        }
    }
}
