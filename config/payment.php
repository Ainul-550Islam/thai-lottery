<?php

use App\Enums\PaymentMethod;

/*
|--------------------------------------------------------------------------
| Payment Configuration
|--------------------------------------------------------------------------
|
| Configuration only. No gateway client, HTTP call or signing implementation
| lives in this file, and no payment package is installed yet. Phase 9 binds
| concrete gateway drivers against these settings.
|
| SECRETS
| Every credential is read from the environment and defaults to null. Never
| write a real key, secret, password or private key into this file.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------------
    */

    'default_gateway' => env('PAYMENT_DEFAULT_GATEWAY', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Supported Currencies
    |--------------------------------------------------------------------------
    */

    'currency' => [
        'default' => env('FINANCE_DEFAULT_CURRENCY', 'THB'),
        'supported' => ['THB', 'USD', 'BDT'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    |
    | Backing values of App\Enums\PaymentMethod, used to validate an inbound
    | deposit or withdrawal request before a gateway is resolved.
    |
    */

    'methods' => array_column(PaymentMethod::cases(), 'value'),

    /*
    |--------------------------------------------------------------------------
    | Deposits
    |--------------------------------------------------------------------------
    |
    | Monetary bounds are decimal strings and mirror config/finance.php so the
    | wallet engine and the payment layer cannot disagree.
    |
    */

    'deposit' => [
        'enabled' => true,
        'min' => (string) env('FINANCE_MIN_DEPOSIT', '50.00'),
        'max' => (string) env('FINANCE_MAX_DEPOSIT', '500000.00'),
        'auto_confirm' => (bool) env('FINANCE_DEPOSIT_AUTO_CONFIRM', false),
        'reference_prefix' => 'DP',
        'expire_unpaid_after_minutes' => 30,
        'allowed_methods' => [
            PaymentMethod::Stripe->value,
            PaymentMethod::Bkash->value,
            PaymentMethod::Nagad->value,
            PaymentMethod::Crypto->value,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    */

    'withdrawal' => [
        'enabled' => true,
        'min' => (string) env('FINANCE_MIN_WITHDRAWAL', '100.00'),
        'max' => (string) env('FINANCE_MAX_WITHDRAWAL', '500000.00'),
        'require_manual_approval' => true,
        'processing_hours' => (int) env('FINANCE_WITHDRAWAL_PROCESSING_HOURS', 24),
        'reference_prefix' => 'WD',
        'encrypt_payout_details' => true,
        'allowed_methods' => [
            PaymentMethod::Bkash->value,
            PaymentMethod::Nagad->value,
            PaymentMethod::BankTransfer->value,
            PaymentMethod::Crypto->value,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Callback / Redirect URLs
    |--------------------------------------------------------------------------
    |
    | Relative paths are resolved against APP_URL when a gateway session is
    | created. Routes for these paths are added in a later phase.
    |
    */

    'callback' => [
        'success_url' => env('PAYMENT_SUCCESS_URL', '/payment/success'),
        'failure_url' => env('PAYMENT_FAILURE_URL', '/payment/failure'),
        'cancel_url' => env('PAYMENT_CANCEL_URL', '/payment/cancel'),
        'pending_url' => '/payment/pending',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Gateway callbacks and webhooks are retried by providers, so every inbound
    | notification is keyed and deduplicated before it can touch the ledger.
    |
    */

    'idempotency' => [
        'enabled' => true,
        'header' => env('IDEMPOTENCY_KEY_HEADER', 'X-Idempotency-Key'),
        'cache_ttl' => (int) env('IDEMPOTENCY_CACHE_TTL', 86400),
        'cache_prefix' => 'payment:idempotency:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Validation
    |--------------------------------------------------------------------------
    |
    | Consumed by App\Http\Middleware\VerifyWebhookSignature, which reads the
    | per-gateway 'webhook_secret' and 'signature_header' values below.
    |
    */

    'webhook' => [
        'verify_signature' => true,
        'algorithm' => 'sha256',
        'max_age_seconds' => 300,
        'replay_protection' => true,
        'replay_cache_prefix' => 'payment:webhook:seen:',
        'require_https' => true,
        'log_rejections' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | 'enabled' defaults to false everywhere because no driver is implemented.
    | A gateway must not be switched on until Phase 9 provides its driver.
    |
    */

    'gateways' => [

        'stripe' => [
            'enabled' => (bool) env('STRIPE_ENABLED', false),
            'driver' => 'stripe',
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'signature_header' => 'Stripe-Signature',
            'currency' => env('FINANCE_DEFAULT_CURRENCY', 'THB'),
            'supported_currencies' => ['THB', 'USD'],
            'supports_deposit' => true,
            'supports_withdrawal' => false,
        ],

        'bkash' => [
            'enabled' => (bool) env('BKASH_ENABLED', false),
            'driver' => 'bkash',
            'app_key' => env('BKASH_APP_KEY'),
            'app_secret' => env('BKASH_APP_SECRET'),
            'username' => env('BKASH_USERNAME'),
            'password' => env('BKASH_PASSWORD'),
            'base_url' => env('BKASH_BASE_URL'),
            'sandbox' => (bool) env('BKASH_SANDBOX', true),
            'webhook_secret' => env('BKASH_APP_SECRET'),
            'signature_header' => 'X-Bkash-Signature',
            'currency' => 'BDT',
            'supported_currencies' => ['BDT'],
            'supports_deposit' => true,
            'supports_withdrawal' => true,
        ],

        'nagad' => [
            'enabled' => (bool) env('NAGAD_ENABLED', false),
            'driver' => 'nagad',
            'merchant_id' => env('NAGAD_MERCHANT_ID'),
            'merchant_number' => env('NAGAD_MERCHANT_NUMBER'),
            'app_key' => env('NAGAD_APP_KEY'),
            'app_secret' => env('NAGAD_APP_SECRET'),
            'public_key' => env('NAGAD_PUBLIC_KEY'),
            'private_key' => env('NAGAD_PRIVATE_KEY'),
            'base_url' => env('NAGAD_BASE_URL'),
            'sandbox' => (bool) env('NAGAD_SANDBOX', true),
            'webhook_secret' => env('NAGAD_APP_SECRET'),
            'signature_header' => 'X-Nagad-Signature',
            'currency' => 'BDT',
            'supported_currencies' => ['BDT'],
            'supports_deposit' => true,
            'supports_withdrawal' => true,
        ],

        'crypto' => [
            'enabled' => (bool) env('CRYPTO_ENABLED', false),
            'driver' => 'crypto',
            'provider' => env('CRYPTO_PROVIDER'),
            'api_key' => env('CRYPTO_GATEWAY_API_KEY'),
            'api_secret' => env('CRYPTO_GATEWAY_API_SECRET'),
            'webhook_secret' => env('CRYPTO_GATEWAY_WEBHOOK_SECRET'),
            'signature_header' => 'X-Crypto-Signature',
            'currency' => 'USD',
            'supported_currencies' => ['USD'],
            'confirmations_required' => 3,
            'supports_deposit' => true,
            'supports_withdrawal' => true,
        ],

        'promptpay' => [
            'enabled' => (bool) env('PROMPTPAY_ENABLED', false),
            'driver' => 'promptpay',
            // National ID (13 digits), phone (starts with 0, national) or
            // tax id the merchant registered with a PromptPay bank.
            'target' => env('PROMPTPAY_TARGET'),
            'webhook_secret' => env('PROMPTPAY_WEBHOOK_SECRET'),
            'signature_header' => 'X-PromptPay-Signature',
            'currency' => 'THB',
            'supported_currencies' => ['THB'],
            // A dynamic PromptPay QR cannot lock a chipper for longer than
            // this; the payer must scan inside the window or get a fresh one.
            'qr_expiry_minutes' => (int) env('PROMPTPAY_QR_EXPIRY_MINUTES', 15),
            'supports_deposit' => true,
            'supports_withdrawal' => false,
        ],

    ],

];
