<?php

use App\Enums\LedgerAccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletType;

/*
|--------------------------------------------------------------------------
| Financial Engine Configuration
|--------------------------------------------------------------------------
|
| Configuration only. No financial logic lives in this file.
| The Phase 2 wallet + double-entry ledger engine consumes these values.
|
| MONEY REPRESENTATION: every monetary value below is a decimal *string*.
| The engine performs arithmetic with bcmath. Floats must never be used for
| money, so do not convert these values to float in consuming code.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | THB is the primary lottery currency. Supported codes are the backing
    | values of App\Enums\Currency, keeping config and enum from drifting.
    |
    */

    'currency' => [
        'default' => env('FINANCE_DEFAULT_CURRENCY', 'THB'),
        'supported' => ['THB', 'USD', 'BDT'],
        'conversion_enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Money Precision / Decimal Scale
    |--------------------------------------------------------------------------
    |
    | 'amount' is the decimal scale used for every stored monetary column and
    | for every bcmath operation. 'rate' is the scale used for commission and
    | multiplier calculations before the result is rounded back to 'amount'.
    |
    */

    'precision' => [
        'amount' => (int) env('FINANCE_LEDGER_PRECISION', 2),
        'rate' => 6,
        'rounding_mode' => 'half_up',
        'storage_scale' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet
    |--------------------------------------------------------------------------
    */

    'wallet' => [
        'default_type' => WalletType::Primary->value,
        'types' => array_column(WalletType::cases(), 'value'),
        'auto_create_on_registration' => true,
        'max_balance' => (string) env('FINANCE_MAX_WALLET_BALANCE', '1000000.00'),
        'min_balance' => '0.00',
        'allow_negative_balance' => false,
        'lock_timeout' => (int) env('FINANCE_LOCK_TIMEOUT_SECONDS', 30),
        'balance_columns' => [
            'balance',
            'locked_balance',
            'bonus_balance',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Balance Locking
    |--------------------------------------------------------------------------
    |
    | Every balance mutation must run inside a database transaction that holds
    | a pessimistic row lock on the wallet. These values tune that behaviour.
    |
    */

    'locking' => [
        'strategy' => 'pessimistic',
        'timeout_seconds' => (int) env('FINANCE_LOCK_TIMEOUT_SECONDS', 30),
        'max_retries' => 3,
        'retry_delay_milliseconds' => 150,
        'cache_lock_prefix' => 'finance:wallet:lock:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledger
    |--------------------------------------------------------------------------
    |
    | The ledger is strictly double entry: every financial transaction must
    | produce balanced debit and credit entries, and entries are append only.
    |
    */

    'ledger' => [
        'double_entry' => true,
        'immutable_entries' => true,
        'require_balanced_entries' => true,
        'balance_tolerance' => '0.00',
        'account_types' => array_column(LedgerAccountType::cases(), 'value'),
        'auto_reconcile' => (bool) env('FINANCE_LEDGER_AUTO_RECONCILE', true),
        'reconciliation_interval' => (int) env('FINANCE_LEDGER_RECONCILE_INTERVAL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    */

    'reconciliation' => [
        'enabled' => (bool) env('FINANCE_LEDGER_AUTO_RECONCILE', true),
        'interval_seconds' => (int) env('FINANCE_LEDGER_RECONCILE_INTERVAL', 3600),
        'compare' => [
            'wallet_balance_vs_ledger',
            'ledger_debits_vs_credits',
            'transaction_totals_vs_entries',
        ],
        'halt_on_mismatch' => true,
        'mismatch_alert_channel' => 'log',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transactions
    |--------------------------------------------------------------------------
    */

    'transaction' => [
        'types' => array_column(TransactionType::cases(), 'value'),
        'statuses' => array_column(TransactionStatus::cases(), 'value'),
        'reference_prefix' => 'TX',
        'reversal_allowed' => true,
        'max_age_hours_reversible' => 72,
        'require_processed_at_on_completion' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Financial writes are keyed by financial_transactions.idempotency_key so a
    | retried request can never create a second transaction.
    |
    */

    'idempotency' => [
        'enabled' => true,
        'header' => env('IDEMPOTENCY_KEY_HEADER', 'X-Idempotency-Key'),
        'cache_ttl' => (int) env('IDEMPOTENCY_CACHE_TTL', 86400),
        'cache_prefix' => 'finance:idempotency:',
        'required_for' => [
            TransactionType::Deposit->value,
            TransactionType::Withdrawal->value,
            TransactionType::BetPlacement->value,
            TransactionType::Payout->value,
            TransactionType::Commission->value,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deposits
    |--------------------------------------------------------------------------
    */

    'deposit' => [
        'min' => (string) env('FINANCE_MIN_DEPOSIT', '50.00'),
        'max' => (string) env('FINANCE_MAX_DEPOSIT', '500000.00'),
        'auto_confirm' => (bool) env('FINANCE_DEPOSIT_AUTO_CONFIRM', false),
        'fee_percentage' => '0.00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    */

    'withdrawal' => [
        'min' => (string) env('FINANCE_MIN_WITHDRAWAL', '100.00'),
        'max' => (string) env('FINANCE_MAX_WITHDRAWAL', '500000.00'),
        'processing_hours' => (int) env('FINANCE_WITHDRAWAL_PROCESSING_HOURS', 24),
        'fee_percentage' => '0.00',
        'require_manual_approval' => true,
        'daily_request_limit' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Financial Audit
    |--------------------------------------------------------------------------
    |
    | Financial audit rows are append only; retention is enforced by a
    | scheduled prune command added in a later phase.
    |
    */

    'audit' => [
        'enabled' => (bool) env('AUDIT_LOG_ENABLED', true),
        'append_only' => true,
        'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 365),
        'log_balance_snapshots' => true,
        'audited_events' => [
            'wallet.credited',
            'wallet.debited',
            'wallet.locked',
            'wallet.unlocked',
            'transaction.completed',
            'transaction.failed',
            'transaction.reversed',
            'ledger.entry.posted',
            'reconciliation.mismatch',
        ],
    ],

];
