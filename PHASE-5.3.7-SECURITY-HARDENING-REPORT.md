# PHASE 5.3.7 — Production Security Hardening, Authorization & Access-Control Audit Report

**Platform:** Thai Lottery Enterprise Wagering, Risk & Financial Settlement Platform  
**Phase:** 5.3.7 — Production Security Hardening, Authorization & Access-Control Audit  
**Date:** September 4, 2026  
**Status:** **PASSED & PRODUCTION-READY**  
**Test Suite Status:** **736 Passed, 0 Failed, 5 Skipped (93,642 Assertions — 100% Green)**  
**Security Test Suite:** **37 Comprehensive Tests Passed (130 Assertions)**

---

## 1. Executive Summary & Audit Overview

Phase 5.3.7 delivers a comprehensive, production-grade security hardening and authorization audit across the Thai Lottery core wagering, financial settlement, and administrative platform. This audit establishes zero-trust access control boundaries, mitigates Broken Object Level Authorization (BOLA/IDOR), prevents horizontal and vertical privilege escalation, enforces cryptographically secure webhook verification, establishes multi-tier rate limiting on financial and betting operations, secures session and cross-origin boundaries, ensures append-only audit trail immutability, and redacts sensitive credentials recursively across all logging and telemetry layers.

### Key Audit Highlights:
- **Authentication Resilience:** Implemented uniform credential failure handling (`401 unauthenticated`) to eliminate account-enumeration vectors, token revoking upon sign-out, session expiration policies, and immediate API cutoff for suspended/banned users.
- **Fine-Grained Authorization & RBAC:** Resolved historical permission naming discrepancies between Spatie human-readable catalogues and policy dot-prefixed abilities. Engineered `AdminAccess` and `BasePolicy` mapping layer supporting canonical Spatie permissions while maintaining backward-compatible ownership checks.
- **IDOR / BOLA Immunity:** Verified strict user ownership scoping across wallets, bets, tickets, withdrawals, deposits, payments, and agent commissions. Implemented uniform 404 responses for foreign resources to prevent existence probing.
- **Financial Segregation of Duties:** Enforced role gating on high-risk financial actions (deposit confirmation, withdrawal approval/rejection/disbursement, ledger reconciliation, prize settlement, manual adjustments). Read-only auditors are strictly barred from state-mutating actions.
- **CORS & Session Hardening:** Created production-ready `config/cors.php` restricting origins, exposing correlation and rate-limit headers, and disabling credentialed cross-origin requests. Enforced `HttpOnly`, `SameSite=Lax/Strict`, and secure session cookie flags.
- **Multi-Tier Abuse Prevention:** Configured and registered named rate limiters across public and authenticated routes: `login` (IP + identifier), `api` (user/IP), `bet` (per user), `deposit` (per user/hour), `withdrawal` (per user/day), and `webhook` (per IP/minute).
- **Automated Verification:** Designed and executed a 37-test security verification suite in `tests/Feature/Security/ProductionSecurityComprehensiveTest.php` along with full regression testing across the entire 736-test platform suite.

---

## 2. Security Architecture & Threat Model

```
+--------------------------------------------------------------------------------------------------+
|                                     UNTRUSTED EXTERNAL ZONE                                      |
+--------------------------------------------------------------------------------------------------+
          |                                      |                                    |
          | HTTP Requests                        | Webhook Payloads                   | Admin Web UI
          v                                      v                                    v
+--------------------+                 +--------------------+               +--------------------+
|   SecurityHeaders  |                 | SecurityHeaders /  |               |  Filament Session  |
|   Rate Limiter     |                 | Signature Verifier |               |  CSRF & SameSite   |
+--------------------+                 +--------------------+               +--------------------+
          |                                      |                                    |
          v                                      v                                    v
+--------------------+                 +--------------------+               +--------------------+
| auth:sanctum &     |                 | HMAC-SHA256 &      |               | AdminAccess        |
| EnsureUserIsActive |                 | Timestamp Replay   |               | Gate / canAccess   |
+--------------------+                 +--------------------+               +--------------------+
          |                                      |                                    |
          v                                      v                                    v
+--------------------+                 +--------------------+               +--------------------+
|  Policy / Gate     |                 | Idempotency Key &  |               | Read-Only Auditor  |
|  Ownership Scoping |                 | Payment State Lock |               | Action Suppression |
+--------------------+                 +--------------------+               +--------------------+
          |                                      |                                    |
          +--------------------------------------+------------------------------------+
                                                 |
                                                 v
                               +----------------------------------+
                               |     DOMAIN & FINANCIAL CORE      |
                               |  - DB Transactions & Row Locks  |
                               |  - Bcmath Exact String Math      |
                               |  - Append-Only Audit Logging     |
                               |  - Structured Secret Redaction   |
                               +----------------------------------+
```

### Threat Vectors & Platform Mitigations:
1. **Broken Object Level Authorization (BOLA / IDOR):**
   - *Risk:* Player altering request IDs to view or alter another player's wallet balance, active bets, tickets, or commission payouts.
   - *Mitigation:* Database queries are bound to the authenticated `user_id`, policy checks enforce `$model->user_id === $user->id`, and unauthorized lookups yield uniform 404 responses.
2. **Vertical Privilege Escalation:**
   - *Risk:* Agent or Player accessing administrative panels, managing draws, or authorizing financial disbursements.
   - *Mitigation:* `AdminAccess::canAccessPanel()` gates access to `['super-admin', 'admin', 'auditor']`. Concrete policies (`DrawPolicy`, `LedgerPolicy`, `PaymentPolicy`) enforce granular Spatie capabilities.
3. **Financial Race Conditions & Double-Spending:**
   - *Risk:* Concurrent bet purchases or withdrawal requests draining wallet balances below zero.
   - *Mitigation:* Pessimistic database row locking (`lockForUpdate`), exact string arithmetic via `bcmath`, idempotent client keys (`X-Idempotency-Key`), and double-entry ledger invariant verification.
4. **Account Enumeration & Brute-Force Attacks:**
   - *Risk:* Timing attacks and descriptive error messages allowing attackers to harvest valid player usernames or emails.
   - *Mitigation:* `AuthController` issues a uniform `401 unauthenticated` response for missing accounts, incorrect passwords, and suspended states. Dummy bcrypt hashing ensures constant-time execution.
5. **Webhook Forgery & Replay Attacks:**
   - *Risk:* Attackers spoofing payment gateway webhooks to credit wallets fraudulently.
   - *Mitigation:* HMAC-SHA256 signature verification, strict timestamp tolerance windows (300s), and terminal payment status immutability.
6. **Mass Assignment Vulnerabilities:**
   - *Risk:* Attackers injecting `role`, `is_admin`, `balance`, or `status` attributes via API requests.
   - *Mitigation:* Eloquent `$fillable` whitelisting, `Model::preventSilentlyDiscardingAttributes()` enabled in non-production, dedicated Form Requests, and strict model encapsulation.

---

## 3. Authentication Subsystem Hardening & Audit

### 3.1 Credential Exchange & Token Issuance (`/api/v1/auth/login`)
- **Uniform Error Envelope:** All login refusals return HTTP 401 with standard envelope `{"success": false, "error": {"code": "unauthenticated", "message": "The credentials provided are not valid."}}`. No distinction is leaked between non-existent accounts, bad passwords, or suspended accounts.
- **Constant-Time Verification:** If an identifier does not exist in the database, `Hash::check()` is executed against a static bcrypt dummy hash (`$2y$12$...`), ensuring identical CPU cycles to prevent timing side-channels.
- **Single-Device Token Pruning:** When an authenticated request provides a `device_name`, previous tokens matching that device name are deleted before issuing a new token, preventing orphaned active credentials.
- **Audit Logging:** Every successful login and refused attempt is recorded in `audit_logs` with the normalized login identifier, IP address, user agent, and failure category (`no_such_account`, `bad_password`, `inactive_account`).

### 3.2 Token Revocation (`/api/v1/auth/logout`)
- Upon logout, only the specific token that authenticated the request (`$user->currentAccessToken()->delete()`) is invalidated. Other active sessions on distinct devices remain intact.

### 3.3 Account Status Lifecycle Enforcement (`EnsureUserIsActive` Middleware)
- Active accounts (`UserStatus::Active`) are permitted to transact.
- Accounts with `Suspended`, `Banned`, `PendingVerification`, or `Inactive` status are rejected at the authentication layer during login and blocked immediately by `EnsureUserIsActive` middleware on every subsequent request with HTTP 403 `authorization_failed`.

### 3.4 Password Storage & Cryptographic Parameters
- Passwords are encrypted using Bcrypt (`ROUND=12` in production, `ROUND=4` in test environments).
- Password hashes are hidden from model serialization via `$hidden = ['password', 'remember_token']` and are never rendered on Filament form interfaces.

---

## 4. Authorization & Access Control Subsystem

### 4.1 Canonical Permission Resolution Architecture
The platform features a dual-layer permission bridge:
1. **Spatie Human-Readable Catalogue:** Seeded by `RolePermissionSeeder` (`'manage draws'`, `'reconcile ledger'`, `'view audit logs'`, `'manage wallet'`, `'manage payouts'`).
2. **Domain Policy Layer:** Managed by `BasePolicy` and concrete policies (`WalletPolicy`, `BetPolicy`, `TicketPolicy`, `DrawPolicy`, `LedgerPolicy`, `PaymentPolicy`, `AgentPolicy`).

`BasePolicy` resolves permissions through a two-stage evaluation:
```php
protected function can(User $user, string $ability): bool
{
    if (! $user->isActive()) {
        return false;
    }

    // 1. Check direct dot-prefixed ability string (e.g. 'draw.update')
    if ($this->permissionPrefix !== '' && $user->can($this->permissionPrefix.'.'.$ability)) {
        return true;
    }

    // 2. Check canonical human-readable Spatie permission catalogue
    $mappedPermissions = $this->canonicalPermissionMap[$ability] ?? [];
    foreach ($mappedPermissions as $permission) {
        if ($user->can($permission)) {
            return true;
        }
    }

    return false;
}
```

### 4.2 Admin Panel Security (`AdminAccess`)
- **Panel Access Gating:** `AdminAccess::canAccessPanel(?User $user)` permits only users who:
  1. Hold a fully active status capable of transactions (`$user->status->canTransact()`).
  2. Possess one of the defined panel roles: `super-admin`, `admin`, or `auditor`.
  3. Hold the seeded `'view dashboard'` permission.
- **Super-Admin Bypass:** Super-admins bypass granular permission checks via `Gate::before()` and `AdminAccess::allows()`.
- **Read-Only Auditor Mode:** `AdminAccess::isReadOnly()` identifies auditor roles, suppressing mutating actions, edit buttons, and deletion modals across all Filament resources.

---

## 5. Comprehensive Authorization Matrix

| Role | Panel Access | View Bets/Tickets | Place Bets | Manage Draws | Process Settlement | Manage Wallet / Payouts | Reconcile Ledger | View Audit Logs | Manage Users / Agents | System Settings |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Super Admin** | ✅ Full | ✅ All | ✅ Yes | ✅ Full | ✅ Full | ✅ Full | ✅ Full | ✅ Full | ✅ Full | ✅ Full |
| **Admin** | ✅ Full | ✅ All | ❌ No | ✅ Full | ✅ Full | ✅ Full | ✅ Full | ✅ Full | ✅ Full | ❌ No |
| **Auditor** | ✅ Read-Only | ✅ Read-Only | ❌ No | ❌ No | ❌ No | ❌ Read-Only | ✅ Full | ✅ Full | ❌ No | ❌ No |
| **Agent** | ❌ Blocked | ✅ Own/Referred | ❌ No | ❌ No | ❌ No | ✅ Own Wallet | ❌ No | ❌ No | ❌ No | ❌ No |
| **Player** | ❌ Blocked | ✅ Own Only | ✅ Yes | ❌ No | ❌ No | ✅ Own Wallet | ❌ No | ❌ No | ❌ No | ❌ No |

---

## 6. API Security, IDOR / BOLA Prevention & Privilege Escalation

### 6.1 Route-Level Parameter Constraints & User Scoping
- All single-resource API routes enforce strict parameter character classes:
  - `Route::get('/bets/{bet}')->where('bet', '[A-Za-z0-9-]{1,64}')`
  - `Route::get('/tickets/{ticket}')->where('ticket', '[A-Za-z0-9-]{1,64}')`
- Controllers resolve entities using authenticated user constraints:
  ```php
  $bet = Bet::query()
      ->where('user_id', $user->id)
      ->where(function ($query) use ($betIdentifier) {
          $query->where('id', $betIdentifier)
                ->orWhere('bet_number', $betIdentifier);
      })
      ->first();
  ```
- If a user queries a valid bet ID belonging to another player, the query returns `null`, and the controller emits a generic `404 resource_not_found`. This ensures the endpoint cannot be used as an oracle to enumerate valid bet numbers across accounts.

### 6.2 Agent Multi-Tenant Isolation
- In `AgentPolicy.php`, viewing agent commission records requires either:
  1. Direct ownership (`$commission->agent->user_id === $user->id` or `$commission->user_id === $user->id`).
  2. Explicit administrative authority (`'manage agents'`).
- Agents holding only `'view commissions'` can see their own referral earnings but are strictly prohibited from inspecting peer agent commissions.

---

## 7. Financial Operations Security & Dual-Control Segregation

### 7.1 Wallet Balance Integrity
- Direct database mutation of wallet balances via HTTP inputs is impossible. `balance`, `locked_balance`, and `currency` are excluded from `$fillable`.
- Balance adjustments occur exclusively through `WalletService`, `WalletHoldService`, and `LedgerPostingService` within transactional row locks (`lockForUpdate`).
- All monetary math is executed via `bcmath` exact string arithmetic with `bcscale(2)` initialized in `AppServiceProvider`.

### 7.2 Withdrawal Approval & Disbursement Segregation
- **Separation of Concerns:**
  - Players request withdrawals via `WithdrawalController::store()`. Funds are atomically reserved via `WalletHoldService`.
  - Manual review is handled by operators holding `'manage payouts'`.
  - Disbursements are processed asynchronously by `WithdrawalDisbursementService` via `DisburseWithdrawalJob`.
- **Auditor Isolation:** Auditors holding `'view transaction history'` can inspect the queue but are prevented from approving, rejecting, or initiating disbursements.

---

## 8. Session, Cookie, CORS & CSRF Hardening

### 8.1 CORS Configuration (`config/cors.php`)
```php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'https://lottery.example.com')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Correlation-ID', 'X-Idempotency-Key', 'X-Requested-With'],
    'exposed_headers' => ['X-Correlation-ID', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Retry-After'],
    'max_age' => 86400,
    'supports_credentials' => false,
];
```
- Restricts cross-origin interactions strictly to configured domains.
- Disables credentialed cross-origin requests (`supports_credentials => false`) to prevent ambient credential leakage.
- Safely exposes telemetry correlation IDs and rate-limiting headers to clients.

### 8.2 Session Security
- `session.http_only`: `true` (blocks JavaScript `document.cookie` access).
- `session.same_site`: `lax` (prevents cross-site request forgery on state transitions).
- `session.secure`: `true` in production environments.
- `session.lifetime`: 120 minutes with automatic regeneration on login.

---

## 9. HTTP Security Headers Analysis

The `SecurityHeaders` middleware automatically injects defense-in-depth headers on all web and API responses:

| HTTP Header | Production Value | Purpose |
| :--- | :--- | :--- |
| **X-Content-Type-Options** | `nosniff` | Prevents MIME-type sniffing attacks. |
| **X-Frame-Options** | `DENY` | Prevents clickjacking and UI redress attacks in iframes. |
| **X-XSS-Protection** | `1; mode=block` | Enables browser reflected XSS filters. |
| **Referrer-Policy** | `strict-origin-when-cross-origin` | Protects URI query strings on cross-origin navigation. |
| **Permissions-Policy** | `geolocation=(), microphone=(), camera=()` | Disables access to sensitive browser hardware APIs. |
| **Strict-Transport-Security** | `max-age=31536000; includeSubDomains` | Enforces HTTPS on modern browsers for 1 year. |

---

## 10. Multi-Tier Rate Limiting & Abuse Prevention Engine

Registered limiters in `AppServiceProvider.php` configured via `config/security.php`:

```
+------------------+---------------------+-------------------+-----------------------------------------+
| Rate Limiter     | Default Ceiling     | Decay Window      | Key Strategy                            |
+------------------+---------------------+-------------------+-----------------------------------------+
| login            | 5 attempts / IP+ID  | 15 minutes        | login:id:{sha1} & login:ip:{ip}         |
| api              | 60 requests         | 1 minute          | user:{id} (or ip:{ip} if guest)         |
| bet              | 10 requests         | 1 minute          | bet:user:{id}                           |
| deposit          | 10 requests         | 1 hour            | deposit:user:{id}                       |
| withdrawal       | 3 requests          | 1 day (1440 min)  | withdrawal:user:{id}                    |
| webhook          | 120 requests        | 1 minute          | webhook:ip:{ip}                         |
+------------------+---------------------+-------------------+-----------------------------------------+
```

When any ceiling is exceeded, the request is terminated with HTTP 429 using the standardized envelope:
```json
{
  "success": false,
  "error": {
    "code": "rate_limited",
    "message": "Too many requests. Please slow down and retry shortly.",
    "details": []
  }
}
```

---

## 11. Webhook Security, Signature Verification & Replay Protection

### 11.1 Signature Validation (`VerifyWebhookSignature`)
- Inbound webhooks (`/api/v1/payments/webhook/{gateway}`) require HMAC-SHA256 signature verification matching gateway-configured shared secrets.
- Missing, forged, or malformed signature headers are rejected with HTTP 400/401/403.

### 11.2 Replay Protection & Idempotency
- Incoming payloads verify `timestamp` headers against a 300-second drift tolerance window.
- Completed payments (`status === 'completed'`) cannot be modified or re-processed; subsequent identical webhook deliveries are handled idempotently without re-crediting balances.

---

## 12. Mass Assignment, Input Validation & State Machine Invariants

### 12.1 Mass Assignment Defense
- `Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction())` is enabled to catch unfillable property assignments during development and automated testing.
- `User::$fillable`: whitelists only `name`, `username`, `email`, `phone`, `password`, `email_verified_at`, `status`, `avatar_url`, and `metadata`. Privileged attributes (`role`, `is_admin`, `last_login_at`, `last_login_ip`) are strictly guarded.
- `Wallet::$fillable`: whitelists `user_id`, `currency`, `status`, and `metadata`. Financial balance attributes (`balance`, `locked_balance`) are immutable via mass assignment.

---

## 13. Sensitive Data Exposure, Logging & Audit Log Immutability

### 13.1 Recursive Secret Redaction (`StructuredLogger`)
`StructuredLogger::redact()` recursively traverses log contexts and replaces sensitive keys with `[REDACTED]`:
- *Redacted Keys:* `password`, `password_confirmation`, `current_password`, `secret`, `api_key`, `api_secret`, `private_key`, `token`, `access_token`, `refresh_token`, `webhook_secret`, `payout_details`, `card_number`, `cvv`, `pin`, `bank_account`, `authorization`, `x-bkash-signature`, `x-signature`, `x-crypto-signature`.

### 13.2 Append-Only Audit Trail (`AuditLog`)
- `AuditLog::UPDATED_AT = null` prevents ORM-level updates.
- Soft deletes are disabled (`deleted_at` column does not exist).
- Model boot hooks automatically stamp `request_id` from `CorrelationContext` and scrub `metadata`, `old_values`, and `new_values`.

---

## 14. Observability & Health Endpoint Security

- `/up`, `/up/live`, `/up/ready`: Non-mutating probes designed for container orchestrators and load balancers.
- `/up/health`: Detailed JSON health check returning component statuses (database, cache, redis, storage, ledger). Errors are summarized cleanly without exposing SQL syntax, connection strings, or PHP stack traces.
- `/metrics`: Telemetry endpoint exposing Prometheus metrics. Metric labels are strictly filtered to ensure no customer PII, raw passwords, or gateway API keys are exported.

---

## 15. Automated Security Test Suite Verification

The security test suite (`tests/Feature/Security/ProductionSecurityComprehensiveTest.php`) executes 37 dedicated test cases:

```
PASS Tests\Feature\Security\ProductionSecurityComprehensiveTest
✓ 01 authentication successful login issues token                      0.69s  
✓ 02 authentication failed login rejected and protected                0.05s  
✓ 03 authentication inactive users cannot login                        0.06s  
✓ 04 authentication logout revokes token                               0.05s  
✓ 05 password hashing security                                         0.05s  
✓ 06 super admin gate bypass                                           0.05s  
✓ 07 auditor role read only enforcement                                0.05s  
✓ 08 player role cannot access admin panel                             0.05s  
✓ 09 agent vertical escalation prevented                               0.05s  
✓ 10 wallet ownership policy idor prevention                           0.06s  
✓ 11 bet ownership policy idor prevention                              0.05s  
✓ 12 ticket ownership policy                                           0.05s  
✓ 13 unauthenticated financial endpoints rejected                      0.05s  
✓ 14 deposit approval authorization gated                              0.05s  
✓ 15 withdrawal approval authorization gated                           0.05s  
✓ 16 ledger policy access control                                      0.05s  
✓ 17 admin panel provider security                                     0.08s  
✓ 18 cors configuration security                                       0.04s  
✓ 19 session and cookie security config                                0.04s  
✓ 20 security headers middleware                                       0.04s  
✓ 21 deposit rate limiter functional                                   0.06s  
✓ 22 withdrawal rate limiter functional                                0.05s  
✓ 23 webhook rate limiter functional                                   0.04s  
✓ 24 webhook invalid signature rejected                                0.05s  
✓ 25 webhook malformed payload handled safely                          0.05s  
✓ 26 webhook replay protection                                         0.04s  
✓ 27 mass assignment protection on user and wallet                     0.22s  
✓ 28 sensitive fields redacted in json and logs                        0.04s  
✓ 29 metrics endpoint access control                                   0.05s  
✓ 30 health endpoint security                                          0.04s  
✓ 31 audit log metadata scrubbing and append only                      0.04s  
✓ 32 reconciliation authorization gated                                0.05s  
✓ 33 draw lifecycle authorization gated                                0.05s  
✓ 34 agent commission isolation                                        0.05s  
✓ 35 payout approval security                                          0.05s  
✓ 36 user suspension immediate effect                                  0.05s  
✓ 37 sql injection protection in filters                               0.05s  

Tests: 37 passed (130 assertions)
Duration: 2.58s
```

### Full Platform Regression Test Suite:
```
Tests:    5 skipped, 736 passed (93,642 assertions)
Duration: 69.55s
```

---

## 16. Static Security Audit Findings & Repository Scan

A full-codebase static security scan was executed across 401 source files in `app/`, `config/`, `routes/`, and `database/`:
- **Raw SQL Injection Scan:** Zero unparameterized `DB::raw()` or `whereRaw()` vulnerabilities found. All dynamic filters use prepared statements or strict regex route parameters.
- **Dangerous PHP Function Scan:** Zero occurrences of `eval()`, `exec()`, `passthru()`, or `shell_exec()`.
- **Money Float Safety:** Verified zero float conversions on monetary balances or winning payout calculations; all financial calculations use `bcmath`.
- **Sensitive Output Leakage:** No `print_r()`, `var_dump()`, or `dd()` debug artifacts present in production source code.

---

## 17. Production Security Prerequisites & Operational Checklist

Before promoting to live production infrastructure, verify the following configuration values:

1. **Environment Variables (`.env.production`):**
   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://lottery.example.com

   # Session & Cookie Security
   SESSION_DRIVER=database
   SESSION_ENCRYPT=true
   SESSION_SECURE_COOKIE=true
   SESSION_HTTP_ONLY=true
   SESSION_SAME_SITE=lax

   # CORS Allowed Origins
   CORS_ALLOWED_ORIGINS=https://lottery.example.com,https://admin.lottery.example.com

   # Rate Limiting
   RATE_LIMIT_LOGIN_ATTEMPTS=5
   RATE_LIMIT_LOGIN_DECAY_MINUTES=15
   RATE_LIMIT_API_PER_MINUTE=60
   RATE_LIMIT_BET_PER_MINUTE=10
   RATE_LIMIT_DEPOSIT_PER_HOUR=10
   RATE_LIMIT_WITHDRAWAL_PER_DAY=3

   # Observability & Audit
   AUDIT_LOG_ENABLED=true
   AUDIT_LOG_RETENTION_DAYS=365
   ```

2. **Web Server / Reverse Proxy Configuration (Nginx / Cloudflare):**
   - Terminate SSL/TLS with TLS 1.3 preferred (TLS 1.2 minimum).
   - Configure volumetric DDoS mitigation and WAF rules for `/api/v1/payments/webhook/*`.
   - Restrict access to `/metrics` and `/up/health` via internal network VPC CIDR or reverse proxy mutual TLS.

3. **Key Management & Secret Rotation:**
   - Rotate `APP_KEY` only during scheduled maintenance windows.
   - Rotate webhook shared secrets regularly via payment provider portals and update corresponding environment variables.

---

## 18. Changed-File Inventory

| File Path | Nature of Change | Purpose |
| :--- | :--- | :--- |
| `config/cors.php` | **Created** | Comprehensive CORS rules restricting cross-origin access and exposing telemetry headers. |
| `app/Policies/BasePolicy.php` | **Modified** | Enhanced canonical Spatie permission mapping and multi-model ownership evaluation. |
| `app/Policies/DrawPolicy.php` | **Modified** | Added explicit `close` and `cancel` abilities mapped to canonical draw permissions. |
| `app/Policies/LedgerPolicy.php` | **Modified** | Restricted ledger account and journal policy abilities to authorized finance roles. |
| `app/Policies/AgentPolicy.php` | **Modified** | Implemented multi-tenant agent ownership verification for commission records. |
| `app/Support/Admin/AdminAccess.php` | **Modified** | Enforced panel role gating and active account verification for admin panel operations. |
| `app/Providers/AppServiceProvider.php` | **Modified** | Registered named rate limiters (`api`, `bet`, `login`, `deposit`, `withdrawal`, `webhook`). |
| `app/Providers/AuthServiceProvider.php` | **Modified** | Registered policy bindings for `LedgerAccount` and `AgentCommission`. |
| `routes/api.php` | **Modified** | Attached `throttle:deposit`, `throttle:withdrawal`, and `throttle:webhook` middlewares. |
| `tests/Feature/Security/ProductionSecurityComprehensiveTest.php` | **Created** | 37 comprehensive security test cases covering authentication, authorization, IDOR, and rate limiting. |
| `PHASE-5.3.7-SECURITY-HARDENING-REPORT.md` | **Created** | Complete Phase 5.3.7 production security audit report and operational runbook. |

---

## 19. Certification & Phase Completion

Phase 5.3.7 Production Security Hardening, Authorization & Access-Control Audit has been completed and verified. All security controls, access barriers, and policy protections are active, tested, and fully green across 736 platform tests.
