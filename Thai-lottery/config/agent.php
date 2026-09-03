<?php

use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;

/*
|--------------------------------------------------------------------------
| Agent / Affiliate Configuration
|--------------------------------------------------------------------------
|
| Configuration only. Agent models exist as data holders, but no agent or
| commission service is implemented; Phase 9 builds those against this file.
|
| RATES LIVE IN THE DATABASE
| The commission rate of an individual agent is stored on agents.commission_rate
| and is never read from this file. Only system-wide behaviour and safety bounds
| are configured here.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Switches
    |--------------------------------------------------------------------------
    */

    'enabled' => false,
    'commission_enabled' => (bool) env('AGENT_COMMISSION_ENABLED', false),
    'registration_open' => false,
    'require_admin_approval' => true,

    /*
    |--------------------------------------------------------------------------
    | Agent Lifecycle
    |--------------------------------------------------------------------------
    */

    'lifecycle' => [
        'statuses' => array_column(AgentStatus::cases(), 'value'),
        'initial_status' => AgentStatus::Inactive->value,
        'operating_statuses' => [
            AgentStatus::Active->value,
        ],
        'code_prefix' => 'AG',
        'code_length' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hierarchy
    |--------------------------------------------------------------------------
    |
    | 'max_depth' bounds the parent chain (master agent -> sub agent -> ...).
    | A depth of 2 means one level of sub agents and no deeper nesting.
    |
    */

    'hierarchy' => [
        'enabled' => true,
        'max_depth' => 2,
        'allow_self_referral' => false,
        'inherit_parent_limits' => true,
        'cascade_suspension_to_children' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Commission Calculation
    |--------------------------------------------------------------------------
    |
    | 'mode' selects the base amount a commission is computed from:
    |   turnover     - percentage of the stake the referred player placed
    |   net_revenue  - percentage of (stake - payout) for the referred player
    |
    | Rates come from the agent record; 'max_rate' is only a safety ceiling that
    | administrative screens validate against.
    |
    */

    'commission' => [
        'mode' => 'turnover',
        'supported_modes' => ['turnover', 'net_revenue'],
        'basis' => 'per_bet',
        'currency' => env('FINANCE_DEFAULT_CURRENCY', 'THB'),
        'rate_scale' => 4,
        'max_rate' => '0.1000',
        'min_payable_amount' => '50.00',
        'rounding_mode' => 'half_up',
        'exclude_cancelled_bets' => true,
        'exclude_refunded_bets' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pending Commission Behaviour
    |--------------------------------------------------------------------------
    |
    | Commission is accrued when a bet is placed, becomes payable only after the
    | related draw is settled, and is reversed if the bet is voided. Accrued rows
    | are never deleted so the ledger and the commission trail stay consistent.
    |
    */

    'pending' => [
        'statuses' => array_column(CommissionStatus::cases(), 'value'),
        'initial_status' => CommissionStatus::Accrued->value,
        'payable_after_draw_settled' => true,
        'hold_hours_before_payable' => 24,
        'reverse_on_bet_void' => true,
        'reversal_status' => CommissionStatus::Reversed->value,
        'delete_on_reversal' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Settlement
    |--------------------------------------------------------------------------
    |
    | Settlement credits the agent's commission wallet through the same
    | double-entry ledger used by every other financial movement.
    |
    */

    'settlement' => [
        'enabled' => false,
        'frequency' => 'weekly',
        'supported_frequencies' => ['daily', 'weekly', 'monthly', 'manual'],
        'day_of_week' => 1,
        'time' => '02:00',
        'timezone' => env('APP_TIMEZONE', 'Asia/Bangkok'),
        'require_manual_approval' => true,
        'target_wallet_type' => 'commission',
        'reference_prefix' => 'AS',
        'batch_size' => 100,
        'idempotency_prefix' => 'agent:settlement:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    */

    'reporting' => [
        'enabled' => true,
        'timezone' => env('APP_TIMEZONE', 'Asia/Bangkok'),
        'default_period' => 'monthly',
        'available_periods' => ['daily', 'weekly', 'monthly'],
        'cache_ttl' => 300,
        'cache_prefix' => 'agent:report:',
        'metrics' => [
            'referred_players',
            'active_players',
            'total_turnover',
            'total_payout',
            'net_revenue',
            'commission_accrued',
            'commission_paid',
        ],
        'agent_can_view_own_only' => true,
    ],

];
