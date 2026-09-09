<?php

use App\Enums\AuditAction;

/*
|--------------------------------------------------------------------------
| Application Security Configuration
|--------------------------------------------------------------------------
|
| Configuration only. No rate limiter class and no additional middleware are
| created in this phase; App\Http\Middleware\SecurityHeaders is the single
| existing consumer and it reads the 'security_headers' section below.
|
| SCOPE
| These settings harden application-level behaviour: throttling, auditing,
| idempotency and response headers. They do not provide network-layer or
| volumetric DDoS protection, which must be handled by a CDN, reverse proxy or
| firewall in front of the application.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Named limiters are registered from these values in a later phase. Keys are
    | kept flat and explicit so a limiter definition maps to exactly one entry.
    |
    */

    'rate_limits' => [

        'login' => [
            'max_attempts' => (int) env('RATE_LIMIT_LOGIN_ATTEMPTS', 5),
            'decay_minutes' => (int) env('RATE_LIMIT_LOGIN_DECAY_MINUTES', 15),
            'by' => 'email_and_ip',
        ],

        'api' => [
            'max_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 60),
            'decay_seconds' => 60,
            'by' => 'user_or_ip',
        ],

        'bet' => [
            'max_per_minute' => (int) env('RATE_LIMIT_BET_PER_MINUTE', 10),
            'decay_seconds' => 60,
            'by' => 'user',
        ],

        'deposit' => [
            'max_per_hour' => (int) env('RATE_LIMIT_DEPOSIT_PER_HOUR', 5),
            'decay_seconds' => 3600,
            'by' => 'user',
        ],

        'withdrawal' => [
            'max_per_day' => (int) env('RATE_LIMIT_WITHDRAWAL_PER_DAY', 3),
            'decay_seconds' => 86400,
            'by' => 'user',
        ],

        'webhook' => [
            'max_per_minute' => 120,
            'decay_seconds' => 60,
            'by' => 'ip',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Financial Operation Protection
    |--------------------------------------------------------------------------
    |
    | Extra requirements applied to any request that can move money, on top of
    | the ordinary rate limits above.
    |
    */

    'financial_operations' => [
        'require_authentication' => true,
        'require_active_user' => true,
        'require_active_wallet' => true,
        'require_idempotency_key' => true,
        'require_https' => true,
        'operations' => [
            'deposit',
            'withdrawal',
            'bet',
            'payout',
            'transfer',
            'commission_settlement',
        ],
        'log_every_attempt' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Protection
    |--------------------------------------------------------------------------
    */

    'authentication' => [
        'lockout_enabled' => true,
        'max_failed_attempts' => (int) env('RATE_LIMIT_LOGIN_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('RATE_LIMIT_LOGIN_DECAY_MINUTES', 15),
        'password_min_length' => 10,
        'require_mixed_case' => true,
        'require_number' => true,
        'require_symbol' => false,
        'log_failed_attempts' => true,
        'logout_other_devices_on_password_change' => true,
        'verify_email' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | audit_logs is append only: rows are never updated or soft deleted, so the
    | trail cannot be rewritten after the fact.
    |
    */

    'audit' => [
        'enabled' => (bool) env('AUDIT_LOG_ENABLED', true),
        'append_only' => true,
        'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 365),
        'actions' => array_column(AuditAction::cases(), 'value'),
        'log_ip_address' => true,
        'log_user_agent' => true,
        'log_request_url' => true,
        'sensitive_fields' => [
            'password',
            'password_confirmation',
            'current_password',
            'secret',
            'api_key',
            'api_secret',
            'private_key',
            'token',
            'access_token',
            'refresh_token',
            'webhook_secret',
            'payout_details',
            'card_number',
            'cvv',
            'pin',
            'bank_account',
            'account_number',
        ],
        'redaction_placeholder' => '[REDACTED]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */

    'idempotency' => [
        'enabled' => true,
        'header' => env('IDEMPOTENCY_KEY_HEADER', 'X-Idempotency-Key'),
        'cache_ttl' => (int) env('IDEMPOTENCY_CACHE_TTL', 86400),
        'cache_prefix' => 'security:idempotency:',
        'min_key_length' => 16,
        'max_key_length' => 128,
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspicious Activity Detection
    |--------------------------------------------------------------------------
    |
    | Signals are recorded for review. Automatic blocking of betting activity is
    | governed by config/risk.php, not by this section.
    |
    */

    'suspicious_activity' => [
        'enabled' => true,
        'signals' => [
            'repeated_failed_logins',
            'login_from_new_country',
            'rapid_deposit_withdrawal_cycle',
            'multiple_accounts_same_device',
            'stake_spike_before_draw_close',
            'withdrawal_details_changed_recently',
        ],
        'failed_login_window_minutes' => 15,
        'deposit_withdrawal_cycle_minutes' => 30,
        'withdrawal_detail_change_hold_hours' => 24,
        'notify_admin' => true,
        'record_audit_entry' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Security
    |--------------------------------------------------------------------------
    |
    | Per-gateway secrets and signature headers are declared in
    | config/payment.php and validated by VerifyWebhookSignature.
    |
    */

    'webhook' => [
        'verify_signature' => true,
        'algorithm' => 'sha256',
        'timestamp_tolerance_seconds' => 300,
        'reject_missing_signature' => true,
        'replay_protection' => true,
        'allowed_ips' => [],
        'log_rejections' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | Keys here are mapped to real HTTP headers by SecurityHeaders middleware.
    | HSTS is only emitted on secure requests.
    |
    */

    'security_headers' => [
        'x_frame_options' => 'DENY',
        'x_content_type_options' => 'nosniff',
        'x_xss_protection' => '1; mode=block',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
        'hsts_max_age' => 31536000,
        'hsts_include_subdomains' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Security
    |--------------------------------------------------------------------------
    |
    | These are the security expectations for a production deployment. The
    | effective runtime values are read by Laravel from config/session.php, so
    | this section documents and validates intent rather than replacing it.
    |
    */

    'session' => [
        'expected_driver' => 'database',
        'lifetime_minutes' => (int) env('SESSION_LIFETIME', 120),
        'require_encryption_in_production' => true,
        'require_secure_cookie_in_production' => true,
        'require_http_only_cookie' => true,
        'same_site' => 'lax',
        'regenerate_on_login' => true,
        'invalidate_on_logout' => true,
    ],

];
