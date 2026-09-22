<?php

use App\Enums\BetType;
use App\Enums\DrawStatus;

/*
|--------------------------------------------------------------------------
| Lottery Domain Configuration
|--------------------------------------------------------------------------
|
| Configuration only. No betting, matching or payout calculation lives here.
| The Phase 4 betting engine and the Phase 5 draw/payout engine read these
| values; multipliers are declared once here and nowhere else.
|
| MARKET MODEL
| The six sellable markets are declared in 'markets'. Each market maps to one
| App\Enums\BetType and one number position:
|
|   3d_direct  -> BetType::ThreeD  exact 3 digits, order matters
|   3d_tod     -> BetType::Tod     same 3 digits in any order
|   2d_top     -> BetType::TwoD    last 2 digits of the first prize
|   2d_bottom  -> BetType::TwoD    the official bottom two-digit number
|   run_top    -> BetType::Run     single digit inside the top result
|   run_bottom -> BetType::Run     single digit inside the bottom result
|
| RESULT SOURCES
| 'top' markets are settled from draw_results.first_prize, which is a real
| column. 'bottom' markets are settled from the official bottom two-digit
| number, which the current schema stores inside draw_results.metadata under
| the key declared in 'results.bottom_two_metadata_key'. A later phase may
| promote it to a dedicated column; until then no code should assume one.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | All draw scheduling, cut-off and result publication times are evaluated
    | in this timezone regardless of the user's local timezone.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Asia/Bangkok'),

    /*
    |--------------------------------------------------------------------------
    | Draw Timing
    |--------------------------------------------------------------------------
    */

    'draw' => [
        'interval_minutes' => (int) env('LOTTERY_DRAW_INTERVAL_MINUTES', 60),
        'timezone' => env('APP_TIMEZONE', 'Asia/Bangkok'),
        'statuses' => array_column(DrawStatus::cases(), 'value'),
        'initial_status' => DrawStatus::Scheduled->value,
        'reference_prefix' => 'DR',
        'official_schedule' => [
            'days_of_month' => [1, 16],
            'time' => '15:00',
        ],

        // draws.type is a single DrawType, but nothing in the betting engine
        // filters selections by it: BetValidationService checks the draw's status
        // and betting window, never its type. An official draw sells all six
        // markets, so this column is descriptive and every generated draw carries
        // the headline market's type.
        'default_type' => (string) env('LOTTERY_DEFAULT_DRAW_TYPE', '3d'),

        // Fallback betting window used when there is no previous occurrence to
        // open from - the very first generated draw.
        'betting_window_days' => (int) env('LOTTERY_BETTING_WINDOW_DAYS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automation (scheduler)
    |--------------------------------------------------------------------------
    |
    | Phase 5.1 built the draw lifecycle services but nothing invoked them, so a
    | draw only moved when a developer called a service by hand. These values drive
    | the console commands in App\Console\Commands\Lottery and the schedule declared
    | in bootstrap/app.php.
    |
    | WHAT AUTOMATION DOES AND DOES NOT DO
    | It creates draws, opens betting, closes betting, moves a past-due closed draw
    | to result-pending, and settles a published draw. It NEVER publishes a result:
    | the official Thai numbers come from outside this system, and
    | 'results.require_admin_confirmation' says a human confirms them. Publication is
    | therefore an operator command (lottery:publish-result), never a scheduled job.
    |
    | 'enabled' is the single kill switch. With it false, the schedule registers no
    | task at all and every automation command refuses unless run with --force, so a
    | maintenance window is one env var, not a code change.
    |
    */

    'automation' => [

        'enabled' => (bool) env('LOTTERY_AUTOMATION_ENABLED', true),

        // How often the orchestrator runs. Every minute is deliberate: the close
        // cut-off is expressed in minutes, so a coarser tick could accept a bet
        // after the cut-off had passed.
        'tick_cron' => (string) env('LOTTERY_TICK_CRON', '* * * * *'),

        // How far ahead lottery:schedule-draws provisions draws. Long enough that a
        // failed run does not leave the system without a next draw, short enough
        // that a schedule change is not baked into years of rows.
        'horizon_days' => (int) env('LOTTERY_SCHEDULE_HORIZON_DAYS', 90),

        // Settlement here is the NON-MONETARY Phase 5.1 simulation. It moves no
        // money, so it is safe to run unattended; 'lottery.payouts.auto_process'
        // stays false and governs the real-money Phase 5.2 payout that does not
        // exist yet. These are two different switches on purpose.
        'auto_settle' => (bool) env('LOTTERY_AUTO_SETTLE', true),

        // Minutes to wait after result publication before settling, so an operator
        // has a window to spot a mistyped result before the draw becomes terminal.
        'settlement_delay_minutes' => (int) env('LOTTERY_SETTLEMENT_DELAY_MINUTES', 15),

        // A draw whose scheduled time has passed while still Closed is moved to
        // result-pending after this grace period, so 'awaiting numbers' is a real
        // state an operator can query rather than an inference.
        'result_pending_after_minutes' => (int) env('LOTTERY_RESULT_PENDING_AFTER_MINUTES', 0),

        // Cap on how many draws one command run touches, so a backlog cannot turn a
        // one-minute tick into an hour-long job holding a lock.
        'batch_size' => (int) env('LOTTERY_AUTOMATION_BATCH_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Draw Closing Behaviour
    |--------------------------------------------------------------------------
    |
    | Betting closes this many minutes before the scheduled draw time. Late
    | bets are rejected rather than queued so no bet can be accepted after the
    | numbers could be known.
    |
    */

    'closing' => [
        'auto_close' => true,
        'minutes_before_draw' => (int) env('LOTTERY_AUTO_CLOSE_MINUTES_BEFORE_DRAW', 5),
        'reject_late_bets' => true,
        'allow_manual_reopen' => false,
        'grace_seconds' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bet Types
    |--------------------------------------------------------------------------
    |
    | Keyed by the backing values of App\Enums\BetType. Digits and multipliers
    | mirror the enum so the engine can read either without drift.
    |
    */

    'types' => [

        BetType::TwoD->value => [
            'label' => '2D',
            'digits' => 2,
            'min_number' => '00',
            'max_number' => '99',
            'requires_exact_order' => true,
            'allows_permutation' => false,
            'payout_multiplier' => 90,
            'markets' => ['2d_top', '2d_bottom'],
            'enabled' => true,
        ],

        BetType::ThreeD->value => [
            'label' => '3D',
            'digits' => 3,
            'min_number' => '000',
            'max_number' => '999',
            'requires_exact_order' => true,
            'allows_permutation' => false,
            'payout_multiplier' => 900,
            'markets' => ['3d_direct'],
            'enabled' => true,
        ],

        BetType::Tod->value => [
            'label' => 'Tod',
            'digits' => 3,
            'min_number' => '000',
            'max_number' => '999',
            'requires_exact_order' => false,
            'allows_permutation' => true,
            'payout_multiplier' => 45,
            'markets' => ['3d_tod'],
            'enabled' => true,
        ],

        BetType::Run->value => [
            'label' => 'Run',
            'digits' => 1,
            'min_number' => '0',
            'max_number' => '9',
            'requires_exact_order' => false,
            'allows_permutation' => false,
            'payout_multiplier' => 12,
            'markets' => ['run_top', 'run_bottom'],
            'enabled' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Sellable Markets
    |--------------------------------------------------------------------------
    |
    | 'source' names the draw result field the market is settled against and is
    | consumed by the Phase 5 winner calculator.
    |
    */

    'markets' => [

        '3d_direct' => [
            'label' => '3D Direct',
            'bet_type' => BetType::ThreeD->value,
            'digits' => 3,
            'position' => 'top',
            'source' => 'first_prize_last_three',
            'match_mode' => 'exact',
            'payout_multiplier' => 900,
            'enabled' => true,
        ],

        '3d_tod' => [
            'label' => '3D Tod',
            'bet_type' => BetType::Tod->value,
            'digits' => 3,
            'position' => 'top',
            'source' => 'first_prize_last_three',
            'match_mode' => 'permutation',
            'payout_multiplier' => 45,
            'enabled' => true,
        ],

        '2d_top' => [
            'label' => '2D Top',
            'bet_type' => BetType::TwoD->value,
            'digits' => 2,
            'position' => 'top',
            'source' => 'first_prize_last_two',
            'match_mode' => 'exact',
            'payout_multiplier' => 90,
            'enabled' => true,
        ],

        '2d_bottom' => [
            'label' => '2D Bottom',
            'bet_type' => BetType::TwoD->value,
            'digits' => 2,
            'position' => 'bottom',
            'source' => 'bottom_two',
            'match_mode' => 'exact',
            'payout_multiplier' => 90,
            'enabled' => true,
        ],

        'run_top' => [
            'label' => 'Run Top',
            'bet_type' => BetType::Run->value,
            'digits' => 1,
            'position' => 'top',
            'source' => 'first_prize_last_three',
            'match_mode' => 'digit_contains',
            'payout_multiplier' => 3,
            'enabled' => true,
        ],

        'run_bottom' => [
            'label' => 'Run Bottom',
            'bet_type' => BetType::Run->value,
            'digits' => 1,
            'position' => 'bottom',
            'source' => 'bottom_two',
            'match_mode' => 'digit_contains',
            'payout_multiplier' => 4,
            'enabled' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Numbers
    |--------------------------------------------------------------------------
    |
    | Numbers are handled as zero-padded strings ("07", "042") so leading zeros
    | are never lost. Never cast a lottery number to int.
    |
    */

    'numbers' => [
        'store_as_string' => true,
        'zero_pad' => true,
        'min' => (string) env('LOTTERY_MIN_NUMBER', '00'),
        'max' => (string) env('LOTTERY_MAX_NUMBER', '99'),
        'distinct_numbers_per_draw' => (int) env('LOTTERY_NUMBER_LIMIT_PER_DRAW', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tickets
    |--------------------------------------------------------------------------
    */

    'ticket' => [
        'price' => (string) env('LOTTERY_TICKET_PRICE', '80.00'),
        'currency' => env('FINANCE_DEFAULT_CURRENCY', 'THB'),
        'reference_prefix' => 'TK',
        'max_bets_per_ticket' => 50,
        'max_numbers_per_bet' => 20,
        'allow_cancellation_before_close' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Betting Limits
    |--------------------------------------------------------------------------
    |
    | Per-user and per-number risk exposure limits live in config/risk.php.
    | These are the plain stake bounds applied to a single bet.
    |
    */

    'betting' => [
        'currency' => env('FINANCE_DEFAULT_CURRENCY', 'THB'),
        'min_amount' => (string) env('LOTTERY_MIN_BET_AMOUNT', '10.00'),
        'max_amount' => (string) env('LOTTERY_MAX_BET_AMOUNT', '100000.00'),
        'amount_step' => '1.00',
        'require_open_draw' => true,
        'require_active_wallet' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    |
    | Multipliers are not duplicated here; the winner calculator resolves them
    | from 'markets' so a rate is defined in exactly one place.
    |
    */

    'payouts' => [
        'multiplier_source' => 'lottery.markets',
        'auto_process' => (bool) env('LOTTERY_AUTO_PAYOUT', false),
        'processing_delay_minutes' => (int) env('LOTTERY_PAYOUT_DELAY_MINUTES', 15),
        'reference_prefix' => 'PO',
        'require_published_result' => true,
        'batch_size' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prize Claims
    |--------------------------------------------------------------------------
    |
    | window_days: how long a prize remains claimable after its draw (GLO
    | parity: 2 years ≈ 730 days). review_threshold: prizes BELOW this are
    | auto-approved on claim; at-or-above goes to the four-eyes review queue.
    | Default '999999999.00' auto-approves effectively everything digital
    | unless operations chooses to start reviewing floors.
    |
    */

    'claims' => [
        'window_days' => (int) env('LOTTERY_CLAIM_WINDOW_DAYS', 730),
        'review_threshold' => (string) env('LOTTERY_CLAIM_REVIEW_THRESHOLD', '999999999.00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prize Taxation
    |--------------------------------------------------------------------------
    |
    | Exact-decimal prize tax. 'rate' is a decimal string in [0,1] with at
    | most 6 places (5.5% = "0.055"); absent configuration is a loud stop,
    | never a guessed zero. 'threshold' waives tax for taxable bases at or
    | below it — a prize under the threshold carries an audited zero, not
    | a silent one. Per-currency overrides map currency codes to rates.
    |
    */

    'tax' => [
        'rate' => (string) env('LOTTERY_TAX_RATE', '0'),
        'threshold' => (string) env('LOTTERY_TAX_THRESHOLD', '0.00'),
        'currencies' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Result Publication
    |--------------------------------------------------------------------------
    */

    'results' => [
        'require_admin_confirmation' => true,
        'lock_after_publication' => true,
        'required_fields' => [
            'first_prize',
        ],
        'optional_fields' => [
            'second_prize',
            'third_prize',
            'consolation_prizes',
            'all_numbers',
        ],
        'bottom_two_metadata_key' => 'bottom_two',
        'first_prize_digits' => 6,
        'publish_delay_minutes' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bet Cancellation
    |--------------------------------------------------------------------------
    |
    | Player-initiated cancellation. A cancellation always refunds the FULL
    | stored stake through FinancialTransactionService (BetRefund) inside the
    | same transaction that marks the bet cancelled and releases the risk
    | reservation — a cancelled-and-unrefunded bet is unrepresentable.
    |
    */

    'cancellation' => [
        'enabled' => (bool) env('LOTTERY_CANCELLATION_ENABLED', true),
        // Minutes from placement during which a player may still cancel.
        // 0 means "no time limit while the draw is open".
        'window_minutes' => (int) env('LOTTERY_CANCELLATION_WINDOW_MINUTES', 60),
        // Cancellation stops exactly when selling stops.
        'require_draw_open' => (bool) env('LOTTERY_CANCELLATION_REQUIRE_OPEN_DRAW', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bet Amendment
    |--------------------------------------------------------------------------
    |
    | Replace a bet's number and/or stake. Mechanically a full-refund
    | cancellation followed by an ordinary purchase through BetPurchaseService,
    | ordered so the player's stake is back in the wallet BEFORE the
    | replacement is attempted — a refused replacement never strands money.
    |
    */

    'amendment' => [
        'enabled' => (bool) env('LOTTERY_AMENDMENT_ENABLED', true),
        'window_minutes' => (int) env('LOTTERY_AMENDMENT_WINDOW_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk Purchase (bet slip)
    |--------------------------------------------------------------------------
    |
    | Multi-selection purchasing as N sequential atomic purchases through the
    | single-bet pipeline. Partial success is reported per item, never hidden.
    |
    */

    'bulk' => [
        'max_items' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket Sharing
    |--------------------------------------------------------------------------
    |
    | Revocable bearer links exposing a ticket's coarse summary. The raw token
    | is 256-bit, returned once at creation; only its SHA-256 is stored.
    |
    */

    'sharing' => [
        'enabled' => (bool) env('LOTTERY_SHARING_ENABLED', true),
        'ttl_hours' => (int) env('LOTTERY_SHARE_TTL_HOURS', 72),
        'min_ttl_hours' => 1,
        'max_ttl_hours' => 720,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket Verification
    |--------------------------------------------------------------------------
    |
    | Read-only "does this ticket number exist and in what broad state". Public
    | verdicts are coarse by design; money detail is owner-scoped only.
    |
    */

    'verification' => [
        'enabled' => (bool) env('LOTTERY_VERIFICATION_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => true,
        'prefix' => 'lottery:',
        'draw_ttl' => (int) env('LOTTERY_DRAW_CACHE_TTL', 60),
        'result_ttl' => 3600,
        'number_limit_ttl' => 30,
    ],

];
