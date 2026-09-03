<?php

use App\Enums\LimitStatus;
use App\Enums\RiskLevel;

/*
|--------------------------------------------------------------------------
| Risk Management Configuration
|--------------------------------------------------------------------------
|
| Configuration only. The Phase 3 risk engine and number-limit service are not
| implemented yet; this file declares the thresholds they will enforce.
|
| Monetary exposure values are decimal strings for bcmath. Score thresholds are
| floats in the inclusive range 0.0 - 1.0 and mirror App\Enums\RiskLevel.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Engine
    |--------------------------------------------------------------------------
    |
    | When 'enabled' is false, no risk evaluation runs and 'fail_open' decides
    | whether bets are still accepted if the evaluator itself errors.
    |
    */

    'enabled' => true,
    'fail_open' => false,
    'evaluate_before_bet' => true,
    'evaluate_after_draw_close' => true,

    /*
    |--------------------------------------------------------------------------
    | Number Exposure
    |--------------------------------------------------------------------------
    |
    | Exposure is the total potential payout accepted on a single number for a
    | single draw. When 'block_on_exceeded' is true the number stops selling
    | instead of silently accepting more liability.
    |
    */

    'exposure' => [
        'max_per_number' => (string) env('RISK_MAX_EXPOSURE_PER_NUMBER', '100000.00'),
        'max_per_draw' => (string) env('RISK_MAX_BET_PER_DRAW', '50000.00'),
        'warning_percentage' => 80,
        'block_on_exceeded' => true,
        'limit_statuses' => array_column(LimitStatus::cases(), 'value'),
        'default_limit_status' => LimitStatus::Active->value,
        'recalculate_on_each_bet' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-User Betting Limits
    |--------------------------------------------------------------------------
    */

    'user_limits' => [
        'max_stake_per_bet' => (string) env('LOTTERY_MAX_BET_AMOUNT', '100000.00'),
        'max_stake_per_draw' => (string) env('RISK_MAX_BET_PER_DRAW', '50000.00'),
        'daily_loss_limit' => (string) env('RISK_DAILY_LOSS_LIMIT', '100000.00'),
        'max_open_bets' => 200,
        'max_bets_per_draw' => 100,
        'apply_to_agents' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Betting Velocity
    |--------------------------------------------------------------------------
    |
    | Velocity guards catch automated or coordinated stake dumping just before a
    | draw closes. Hard request throttling lives in config/security.php.
    |
    */

    'velocity' => [
        'enabled' => true,
        'max_bets_per_minute' => (int) env('RATE_LIMIT_BET_PER_MINUTE', 10),
        'max_stake_per_minute' => '50000.00',
        'window_seconds' => 60,
        'cache_prefix' => 'risk:velocity:',
        'flag_on_breach' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Scoring Thresholds
    |--------------------------------------------------------------------------
    |
    | Boundaries match App\Enums\RiskLevel::fromScore() so the enum and this
    | configuration cannot disagree about what "high risk" means.
    |
    */

    'thresholds' => [
        'medium' => 0.3,
        'high' => 0.6,
        'suspicious' => (float) env('RISK_SUSPICIOUS_THRESHOLD', 0.8),
        'critical' => 0.95,
        'block' => 1.0,
    ],

    'levels' => array_column(RiskLevel::cases(), 'value'),

    /*
    |--------------------------------------------------------------------------
    | Automatic Blocking / Administrative Override
    |--------------------------------------------------------------------------
    |
    | Every override is recorded in audit_logs, which is append only, so an
    | administrator lifting a block always leaves a permanent trace.
    |
    */

    'auto_block' => [
        'enabled' => (bool) env('RISK_AUTO_BLOCK_ENABLED', true),
        'block_at_level' => RiskLevel::Critical->value,
        'block_bet' => true,
        'block_withdrawal' => true,
        'block_user' => false,
    ],

    'override' => [
        'allowed' => true,
        'required_permission' => 'override risk limits',
        'require_reason' => true,
        'max_duration_minutes' => 120,
        'audit_every_override' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked Numbers
    |--------------------------------------------------------------------------
    |
    | Numbers are compared as zero-padded strings. Entries listed here are
    | rejected outright; per-draw blocks belong in the number_limits table.
    |
    */

    'blocked_numbers' => [
        'enabled' => true,
        'global' => [],
        'compare_as_string' => true,
        'reject_message' => 'This number is not available for the selected draw.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    */

    'alerts' => [
        'enabled' => true,
        'channel' => env('RISK_ALERT_CHANNEL', 'log'),
        'notify_admin' => true,
        'notify_agent' => false,
        'notify_at_level' => RiskLevel::High->value,
        'throttle_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Event Retention
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'risk_event_days' => 180,
        'exposure_snapshot_days' => 90,
        'keep_blocked_bet_records' => true,
        'prune_command_enabled' => false,
    ],

];
