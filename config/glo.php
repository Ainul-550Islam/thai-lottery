<?php

use App\Enums\GloPrizeTier;

/*
|--------------------------------------------------------------------------
| Government Lottery Office (GLO) — Official Prize-Rule Catalogue
|--------------------------------------------------------------------------
|
| This file declares the OFFICIAL Thai government lottery prize structure as a
| reference and settlement catalogue. It is deliberately separate from
| config('lottery.markets'), which describes the online-operator markets this
| project sells.
|
| WHY SEPARATE
| The GLO's printed 6-digit ticket pays a ladder of prizes (first, adjacent,
| second, third, fourth, fifth, front 3, last 3, last 2) that the operator
| markets do not model. Keeping the official ladder here means admin screens,
| result recording and any future "ticket checker" read one authoritative
| source, and nothing in config('lottery') pretends the operator markets are
| the government product.
|
| AMOUNTS AND COUNTS ARE DECLARED, NOT COMPUTED
| The official payout schedule (6,000,000 THB first prize, 5 second prizes,
| …) is data, not code. Operators update it here when the GLO amends the
| schedule. No service hard codes a baht amount.
|
| Official schedule (per ticket), unchanged for the standard draw:
|   first           6,000,000 THB, 1 number
|   adjacent_first    100,000 THB, 2 numbers (first prize +/- 1)
|   second            200,000 THB, 5 numbers
|   third              80,000 THB, 10 numbers
|   fourth             40,000 THB, 50 numbers
|   fifth              20,000 THB, 100 numbers
|   front_three         4,000 THB, 2 numbers
|   last_three          4,000 THB, 2 numbers
|   last_two            2,000 THB, 1 number
|
| CLAIM RULES (from the GLO)
|   - Prize money must be claimed within two years of the draw date.
|   - Banks can pay prizes under 20,000 THB; larger prizes are paid by GLO
|     cheque, which requires the original signed ticket and an ID (Thai ID
|     card or passport).
|   - ~0.5%–1% withholding tax applies at source on most prizes.
|   - Tickets are sold in pairs; a pair winning the first prize pays double.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Prize Schedule
    |--------------------------------------------------------------------------
    */

    'prizes' => [

        GloPrizeTier::First->value => [
            'amount' => '6000000.00',
            'winners' => 1,
            'digits' => 6,
            'tax_withheld' => false,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::AdjacentFirst->value => [
            'amount' => '100000.00',
            'winners' => 2,
            'digits' => 6,
            'tax_withheld' => true,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::Second->value => [
            'amount' => '200000.00',
            'winners' => 5,
            'digits' => 6,
            'tax_withheld' => true,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::Third->value => [
            'amount' => '80000.00',
            'winners' => 10,
            'digits' => 6,
            'tax_withheld' => true,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::Fourth->value => [
            'amount' => '40000.00',
            'winners' => 50,
            'digits' => 6,
            'tax_withheld' => true,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::Fifth->value => [
            'amount' => '20000.00',
            'winners' => 100,
            'digits' => 6,
            'tax_withheld' => true,
            'min_claim_venue' => 'any_bank',
        ],

        GloPrizeTier::FrontThree->value => [
            'amount' => '4000.00',
            'winners' => 2,
            'digits' => 3,
            'tax_withheld' => false,
            'min_claim_venue' => 'any_bank',
        ],

        GloPrizeTier::LastThree->value => [
            'amount' => '4000.00',
            'winners' => 2,
            'digits' => 3,
            'tax_withheld' => false,
            'min_claim_venue' => 'any_bank',
        ],

        GloPrizeTier::LastTwo->value => [
            'amount' => '2000.00',
            'winners' => 1,
            'digits' => 2,
            'tax_withheld' => false,
            'min_claim_venue' => 'any_bank',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tier Recording Layout
    |--------------------------------------------------------------------------
    |
    | draw_results has dedicated columns only for first_prize, second_prize and
    | third_prize. The remaining official tiers (fourth, fifth, front 3, last 3,
    | last 2) are recorded inside draw_results.metadata JSON under this key. The
    | reader (App\Services\Lottery\GloResultService) resolves every tier from
    | this one structure, so an operator record layout change is a configuration
    | change, not a code change.
    |
    */

    'tiers' => [
        'metadata_key' => 'glo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Claim & Tax Rules (for display and payout administration)
    |--------------------------------------------------------------------------
    */

    'claim' => [
        'window_years' => 2,
        'bank_cash_limit' => '20000.00',
        'require_original_ticket' => true,
        'require_signed_ticket' => true,
        'require_id' => true,
        'accepted_id_documents' => ['thai_national_id', 'passport', 'employee_id', 'driver_license'],
        'withholding_tax_rate' => '0.005',
        'withholding_tax_rate_max' => '0.01',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket Rules (the printed government ticket)
    |--------------------------------------------------------------------------
    */

    'ticket' => [
        'digits' => 6,
        'sold_in_pairs' => true,
        'pair_set_value' => '120.00',
        'valid_series' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Draw Calendar
    |--------------------------------------------------------------------------
    |
    | The GLO draws twice a month. These are the structural calendar rules; the
    | application's own draw scheduling remains under config('lottery.draw').
    |
    */

    'draw_calendar' => [
        'days_of_month' => [1, 16],
        'announced_by' => 'Government Lottery Office (GLO)',
        'venue' => 'GLO Headquarters, Bangkok',
    ],

];
