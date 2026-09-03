<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\BetPurchaseData;
use App\Enums\BetPurchaseStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\TransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\LimitStatus;
use App\Enums\TicketStatus;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\BetPurchaseValidationException;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\NumberLimit;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Betting\BetPurchaseService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Phase 4.3 - atomicity, idempotency, concurrency and rollback of a bet purchase.
 *
 * WHY THIS SUITE DOES NOT USE RefreshDatabase
 * RefreshDatabase wraps every test in an open transaction and rolls it back afterwards.
 * That is fatal to this suite for two separate reasons. First, the purchase pipeline
 * asserts that it OWNS its transaction, so it would refuse to run inside the test's
 * wrapper. Second, and more importantly, an uncommitted test transaction is invisible to
 * any other connection, so a concurrency test could never observe a competing process's
 * writes. DatabaseTruncation commits normally and truncates between tests, so the
 * transaction boundaries under test are the real ones.
 *
 * FAIL-SAFE DATABASE GUARD
 * Every test in this class refuses to run unless the connection points at a database
 * that is explicitly a test database. There is no code path here that can mutate
 * anything else.
 */
final class BetPurchaseAtomicityTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * The only database names this suite will touch.
     */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    private const ACCOUNT_SYSTEM_CASH = '1000';

    private const ACCOUNT_PLAYER_LIABILITY = '2000';

    private const ACCOUNT_BET_REVENUE = '4000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestDatabaseOnly();
        $this->seedChartOfAccounts();
    }

    // -----------------------------------------------------------------------------
    // A-F: every declared market sells
    // -----------------------------------------------------------------------------

    #[Test]
    public function a_purchases_a_valid_three_digit_direct_bet(): void
    {
        $result = $this->buy('3d_direct', '123', '10.00');

        $this->assertSame(BetPurchaseStatus::Purchased, $result->status);
        $this->assertSame('3d', $result->bet->type->value);
        $this->assertSame('123', $result->item->number);
        $this->assertSame('10.00', (string) $result->bet->stake_amount);
        $this->assertSame('9000.00', (string) $result->bet->potential_payout);
        $this->assertSame(1, (int) $result->bet->total_numbers);
    }

    #[Test]
    public function b_purchases_a_valid_three_digit_tod_bet(): void
    {
        $result = $this->buy('3d_tod', '123', '10.00');

        $this->assertSame(BetPurchaseStatus::Purchased, $result->status);
        $this->assertSame(BetType::Tod->value, $result->bet->type->value);
        $this->assertSame('123', $result->item->number);
        // ONE selection, ONE charge, at the full stake and not six times it.
        $this->assertSame('10.00', (string) $result->bet->stake_amount);
        $this->assertSame(1, BetItem::query()->where('bet_id', $result->betId())->count());
    }

    #[Test]
    public function c_purchases_a_valid_two_digit_top_bet(): void
    {
        $result = $this->buy('2d_top', '45', '10.00');

        $this->assertSame('45', $result->item->number);
        $this->assertSame('top', $result->item->position);
        $this->assertSame('900.00', (string) $result->bet->potential_payout);
    }

    #[Test]
    public function d_purchases_a_valid_two_digit_bottom_bet(): void
    {
        $result = $this->buy('2d_bottom', '45', '10.00');

        $this->assertSame('45', $result->item->number);
        $this->assertSame('bottom', $result->item->position);
    }

    #[Test]
    public function e_purchases_a_valid_run_top_bet(): void
    {
        $result = $this->buy('run_top', '7', '10.00');

        $this->assertSame('7', $result->item->number);
        $this->assertSame('top', $result->item->position);
        $this->assertSame('30.00', (string) $result->bet->potential_payout);
    }

    #[Test]
    public function f_purchases_a_valid_run_bottom_bet(): void
    {
        $result = $this->buy('run_bottom', '7', '10.00');

        $this->assertSame('7', $result->item->number);
        $this->assertSame('bottom', $result->item->position);
    }

    // -----------------------------------------------------------------------------
    // G-H: leading zeros survive as strings
    // -----------------------------------------------------------------------------

    #[Test]
    public function g_preserves_the_leading_zeros_of_007(): void
    {
        $result = $this->buy('3d_direct', '007', '10.00');

        $this->assertSame('007', $result->item->number);
        $this->assertSame(
            '007',
            (string) DB::table('bet_items')->where('id', $result->betItemId())->value('number'),
        );
    }

    #[Test]
    public function h_preserves_the_leading_zero_of_099(): void
    {
        $result = $this->buy('3d_direct', '099', '10.00');

        $this->assertSame('099', $result->item->number);
        $this->assertSame(
            '099',
            (string) DB::table('bet_items')->where('id', $result->betItemId())->value('number'),
        );
    }

    // -----------------------------------------------------------------------------
    // I-K: malformed input is refused
    // -----------------------------------------------------------------------------

    #[Test]
    public function i_rejects_a_number_with_the_wrong_digit_count(): void
    {
        $this->expectException(BetPurchaseValidationException::class);

        $this->buy('3d_direct', '1234', '10.00');
    }

    #[Test]
    public function j_rejects_a_number_that_is_not_numeric(): void
    {
        $this->expectException(BetPurchaseValidationException::class);

        $this->buy('3d_direct', '12A', '10.00');
    }

    #[Test]
    public function k_rejects_an_unknown_market(): void
    {
        $this->expectException(BetPurchaseValidationException::class);

        $this->buy('4d_direct', '1234', '10.00');
    }

    // -----------------------------------------------------------------------------
    // L-M: draw state is respected
    // -----------------------------------------------------------------------------

    #[Test]
    public function l_rejects_a_bet_on_a_closed_draw(): void
    {
        $fixture = $this->fixture();
        $fixture['draw']->status = DrawStatus::Closed;
        $fixture['draw']->save();

        $caught = null;

        try {
            $this->purchase($fixture, '3d_direct', '123', '10.00');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(BetPurchaseValidationException::class, $caught);
        $this->assertNothingWasPersisted();
    }

    #[Test]
    public function m_rejects_a_bet_on_a_draw_whose_betting_window_has_expired(): void
    {
        $fixture = $this->fixture();
        $fixture['draw']->betting_close_at = now()->subMinute();
        $fixture['draw']->save();

        $caught = null;

        try {
            $this->purchase($fixture, '3d_direct', '123', '10.00');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(BetPurchaseValidationException::class, $caught);
        $this->assertNothingWasPersisted();
    }

    // -----------------------------------------------------------------------------
    // N-Q: the money
    // -----------------------------------------------------------------------------

    #[Test]
    public function n_rejects_a_bet_the_wallet_cannot_fund(): void
    {
        $fixture = $this->fixture(balance: '5.00');

        $caught = null;

        try {
            $this->purchase($fixture, '3d_direct', '123', '10.00');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(BetPurchaseException::class, $caught);
        $this->assertSame('5.00', (string) $fixture['wallet']->refresh()->balance);
        $this->assertNothingWasPersisted();
    }

    #[Test]
    public function o_debits_the_wallet_by_exactly_the_stake(): void
    {
        $fixture = $this->fixture(balance: '100.00');

        $this->purchase($fixture, '3d_direct', '123', '10.00');

        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);
    }

    #[Test]
    public function p_creates_the_ledger_entries_for_the_debit(): void
    {
        $result = $this->buy('3d_direct', '123', '10.00');

        $this->assertGreaterThanOrEqual(2, $result->ledgerEntryCount());

        $entries = LedgerEntry::query()
            ->where('financial_transaction_id', $result->financialTransactionId())
            ->get();

        $this->assertGreaterThanOrEqual(2, $entries->count());

        foreach ($entries as $entry) {
            $this->assertSame(Bet::class, $entry->reference_type);
            $this->assertSame($result->betId(), (int) $entry->reference_id);
        }
    }

    #[Test]
    public function q_posts_a_balanced_ledger(): void
    {
        // The declared stake step is a whole unit, so a 10.55 stake needs the step
        // relaxed. It is relaxed in this test only, to prove the ledger balances on an
        // amount with a fractional part; the shipped configuration is untouched.
        config(['lottery.betting.amount_step' => null]);

        $result = $this->buy('3d_direct', '123', '10.55');

        $entries = LedgerEntry::query()
            ->where('financial_transaction_id', $result->financialTransactionId())
            ->get();

        $debit = '0';
        $credit = '0';

        foreach ($entries as $entry) {
            if ($entry->type === LedgerEntryType::Debit) {
                $debit = bcadd($debit, (string) $entry->amount, 2);

                continue;
            }

            $credit = bcadd($credit, (string) $entry->amount, 2);
        }

        $this->assertSame(0, bccomp($debit, $credit, 2), 'The ledger must balance exactly.');
        $this->assertSame('10.55', $debit);
    }

    // -----------------------------------------------------------------------------
    // R-U: risk and invariants
    // -----------------------------------------------------------------------------

    #[Test]
    public function r_accepts_a_bet_the_risk_engine_allows(): void
    {
        $result = $this->buy('3d_direct', '123', '10.00');

        $limit = NumberLimit::query()
            ->where('draw_id', $result->bet->draw_id)
            ->where('bet_type', BetType::ThreeD->value)
            ->where('number', '123')
            ->firstOrFail();

        $this->assertSame('10.00', (string) $limit->current_amount);
    }

    #[Test]
    public function s_rejects_a_bet_the_risk_engine_refuses(): void
    {
        $fixture = $this->fixture(balance: '100000.00');

        // The number is one stake away from its ceiling.
        $this->tightenLimit($fixture['draw'], BetType::ThreeD, '123', [
            'max_amount' => '50.00',
            'current_amount' => '45.00',
        ]);

        $caught = null;

        try {
            $this->purchase($fixture, '3d_direct', '123', '10.00');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'A bet over its ceiling must be refused.');
        $this->assertNothingWasPersisted();
        $this->assertSame(
            '45.00',
            (string) $this->limitFor($fixture['draw'], BetType::ThreeD, '123')->refresh()->current_amount,
        );
    }

    #[Test]
    public function t_never_lets_exposure_exceed_the_ceiling(): void
    {
        $fixture = $this->fixture(balance: '100000.00');

        $this->tightenLimit($fixture['draw'], BetType::ThreeD, '123', [
            'max_amount' => '1000.00',
            'current_amount' => '900.00',
            'maximum_payout_exposure' => null,
        ]);

        $this->purchase($fixture, '3d_direct', '123', '100.00', key: $this->key('first'));

        $limit = $this->limitFor($fixture['draw'], BetType::ThreeD, '123')->refresh();
        $this->assertSame('1000.00', (string) $limit->current_amount);

        // A second identical stake would take it to 1100 and must be refused.
        $caught = null;

        try {
            $this->purchase($fixture, '3d_direct', '123', '100.00', key: $this->key('second'));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame(
            '1000.00',
            (string) $this->limitFor($fixture['draw'], BetType::ThreeD, '123')->refresh()->current_amount,
        );
    }

    #[Test]
    public function u_never_drives_a_wallet_negative(): void
    {
        $fixture = $this->fixture(balance: '10.00');

        $this->purchase($fixture, '3d_direct', '123', '10.00', key: $this->key('first'));
        $this->assertSame('0.00', (string) $fixture['wallet']->refresh()->balance);

        $caught = null;

        try {
            $this->purchase($fixture, '3d_direct', '456', '10.00', key: $this->key('second'));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame(0, bccomp('0.00', (string) $fixture['wallet']->refresh()->balance, 2));
    }

    // -----------------------------------------------------------------------------
    // V-X: exactly once
    // -----------------------------------------------------------------------------

    #[Test]
    public function v_creates_the_bet_exactly_once(): void
    {
        $this->buy('3d_direct', '123', '10.00');

        $this->assertSame(1, Bet::query()->count());
    }

    #[Test]
    public function w_creates_the_bet_item_exactly_once(): void
    {
        $this->buy('3d_tod', '123', '10.00');

        $this->assertSame(1, BetItem::query()->count());
    }

    #[Test]
    public function x_creates_the_ticket_exactly_once(): void
    {
        $this->buy('3d_tod', '123', '10.00');

        $this->assertSame(1, Ticket::query()->count());
    }

    // -----------------------------------------------------------------------------
    // Y-Z: idempotency
    // -----------------------------------------------------------------------------

    #[Test]
    public function y_replays_an_identical_retried_request(): void
    {
        $fixture = $this->fixture(balance: '100.00');
        $key = $this->key('retry');

        $first = $this->purchase($fixture, '3d_direct', '123', '10.00', key: $key);
        $second = $this->purchase($fixture, '3d_direct', '123', '10.00', key: $key);

        $this->assertSame(BetPurchaseStatus::Purchased, $first->status);
        $this->assertSame(BetPurchaseStatus::Replayed, $second->status);
        $this->assertSame($first->betId(), $second->betId());
        $this->assertSame($first->ticketId(), $second->ticketId());
        $this->assertSame($first->financialTransactionId(), $second->financialTransactionId());

        $this->assertSame(1, Bet::query()->count());
        $this->assertSame(1, BetItem::query()->count());
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);
    }

    #[Test]
    public function z_treats_the_same_number_under_a_different_key_as_a_new_bet(): void
    {
        $fixture = $this->fixture(balance: '100.00');

        $first = $this->purchase($fixture, '3d_direct', '123', '10.00', key: $this->key('one'));
        $second = $this->purchase($fixture, '3d_direct', '123', '10.00', key: $this->key('two'));

        $this->assertSame(BetPurchaseStatus::Purchased, $first->status);
        $this->assertSame(BetPurchaseStatus::Purchased, $second->status);
        $this->assertNotSame($first->betId(), $second->betId());
        $this->assertSame(2, Bet::query()->count());
        $this->assertSame('80.00', (string) $fixture['wallet']->refresh()->balance);
    }

    // -----------------------------------------------------------------------------
    // AA-AB: concurrency, in real separate processes
    // -----------------------------------------------------------------------------

    #[Test]
    public function aa_lets_only_one_of_two_concurrent_purchases_spend_the_last_balance(): void
    {
        $this->requiresRealConcurrency();

        $fixture = $this->fixture(balance: '100.00');
        $this->tightenLimit($fixture['draw'], BetType::ThreeD, '123', ['max_amount' => '100000.00']);

        $outcomes = $this->runConcurrently([
            $this->purchaseCommand($fixture, '3d_direct', '123', '80.00', $this->key('a')),
            $this->purchaseCommand($fixture, '3d_direct', '123', '80.00', $this->key('b')),
        ]);

        $this->assertSame(1, $this->countOutcome($outcomes, 'purchased'), $this->describe($outcomes));
        $this->assertSame(1, $this->countOutcome($outcomes, 'refused'), $this->describe($outcomes));
        $this->assertSame('20.00', (string) $fixture['wallet']->refresh()->balance);
        $this->assertSame(1, Bet::query()->count());
    }

    #[Test]
    public function ab_lets_only_one_of_two_concurrent_purchases_consume_the_last_capacity(): void
    {
        $this->requiresRealConcurrency();

        $fixture = $this->fixture(balance: '100000.00');
        $this->tightenLimit($fixture['draw'], BetType::ThreeD, '123', [
            'max_amount' => '1000.00',
            'current_amount' => '900.00',
            'maximum_payout_exposure' => null,
        ]);

        $outcomes = $this->runConcurrently([
            $this->purchaseCommand($fixture, '3d_direct', '123', '100.00', $this->key('c')),
            $this->purchaseCommand($fixture, '3d_direct', '123', '100.00', $this->key('d')),
        ]);

        $this->assertSame(1, $this->countOutcome($outcomes, 'purchased'), $this->describe($outcomes));
        $this->assertSame(1, $this->countOutcome($outcomes, 'refused'), $this->describe($outcomes));

        $limit = $this->limitFor($fixture['draw'], BetType::ThreeD, '123')->refresh();
        $this->assertSame('1000.00', (string) $limit->current_amount);
        $this->assertSame(1, Bet::query()->count());
    }

    #[Test]
    public function ab2_replays_rather_than_duplicates_two_concurrent_identical_requests(): void
    {
        $this->requiresRealConcurrency();

        $fixture = $this->fixture(balance: '100.00');
        $key = $this->key('same');
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $outcomes = $this->runConcurrently([
            $this->purchaseCommand($fixture, '3d_direct', '123', '10.00', $key),
            $this->purchaseCommand($fixture, '3d_direct', '123', '10.00', $key),
        ]);

        $this->assertSame(0, $this->countOutcome($outcomes, 'refused'), $this->describe($outcomes));
        $this->assertSame(1, Bet::query()->count(), $this->describe($outcomes));
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);
    }

    // -----------------------------------------------------------------------------
    // AC-AF: rollback after a mid-pipeline failure
    // -----------------------------------------------------------------------------

    #[Test]
    public function ac_rolls_everything_back_when_bet_creation_fails(): void
    {
        $this->assertRollsBackWhenStepFails('bets');
    }

    #[Test]
    public function ad_rolls_everything_back_when_ticket_creation_fails(): void
    {
        $this->assertRollsBackWhenStepFails('tickets');
    }

    #[Test]
    public function ae_rolls_everything_back_when_the_ledger_fails(): void
    {
        $this->assertRollsBackWhenStepFails('ledger_entries');
    }

    #[Test]
    public function af_rolls_everything_back_when_the_wallet_debit_fails(): void
    {
        $this->assertRollsBackWhenStepFails('financial_transactions');
    }

    // -----------------------------------------------------------------------------
    // AG-AI: no mutation on refusal
    // -----------------------------------------------------------------------------

    #[Test]
    public function ag_mutates_nothing_when_validation_fails(): void
    {
        $fixture = $this->fixture(balance: '100.00');

        try {
            $this->purchase($fixture, '3d_direct', '12345', '10.00');
        } catch (Throwable) {
            // expected
        }

        $this->assertNothingWasPersisted();
        $this->assertSame('100.00', (string) $fixture['wallet']->refresh()->balance);
    }

    #[Test]
    public function ah_mutates_nothing_when_risk_refuses(): void
    {
        $fixture = $this->fixture(balance: '100000.00');
        $this->tightenLimit($fixture['draw'], BetType::ThreeD, '123', [
            'max_amount' => '5.00',
            'current_amount' => '0.00',
        ]);

        try {
            $this->purchase($fixture, '3d_direct', '123', '10.00');
        } catch (Throwable) {
            // expected
        }

        $this->assertNothingWasPersisted();
        $this->assertSame('0.00', (string) $this->limitFor($fixture['draw'], BetType::ThreeD, '123')->refresh()->current_amount);
    }

    #[Test]
    public function ai_mutates_nothing_when_the_balance_is_insufficient(): void
    {
        $fixture = $this->fixture(balance: '1.00');

        try {
            $this->purchase($fixture, '3d_direct', '123', '10.00');
        } catch (Throwable) {
            // expected
        }

        $this->assertNothingWasPersisted();
        $this->assertSame('1.00', (string) $fixture['wallet']->refresh()->balance);
        $this->assertSame('0.00', (string) $this->limitFor($fixture['draw'], BetType::ThreeD, '123')->refresh()->current_amount);
    }

    // -----------------------------------------------------------------------------
    // AJ-AL: a replay duplicates nothing
    // -----------------------------------------------------------------------------

    #[Test]
    public function aj_creates_no_duplicate_financial_transaction_on_replay(): void
    {
        $fixture = $this->fixture(balance: '100.00');
        $key = $this->key('dup');

        $this->purchase($fixture, '3d_direct', '123', '10.00', key: $key);
        $this->purchase($fixture, '3d_direct', '123', '10.00', key: $key);

        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(
            1,
            FinancialTransaction::query()->where('reference_type', Bet::class)->count(),
        );
    }

    #[Test]
    public function ak_creates_no_duplicate_ledger_movement_on_replay(): void
    {
        $fixture = $this->fixture(balance: '100.00');
        $key = $this->key('dup');

        $first = $this->purchase($fixture, '3d_direct', '123', '10.00', key: $key);
        $before = LedgerEntry::query()->count();

        $this->purchase($fixture, '3d_direct', '123', '10.00', key: $key);

        $this->assertSame($before, LedgerEntry::query()->count());
        $this->assertSame($before, count($this->entryIdsFor($first->financialTransactionId())));
    }

    #[Test]
    public function al_creates_no_duplicate_ticket_on_replay(): void
    {
        $fixture = $this->fixture(balance: '100.00');
        $key = $this->key('dup');

        $this->purchase($fixture, '3d_direct', '123', '10.00', key: $key);
        $this->purchase($fixture, '3d_direct', '123', '10.00', key: $key);

        $this->assertSame(1, Ticket::query()->count());
    }

    // -----------------------------------------------------------------------------
    // AM-AO: no orphans
    // -----------------------------------------------------------------------------

    #[Test]
    public function am_leaves_no_bet_without_a_ticket_or_a_debit(): void
    {
        $this->buy('3d_direct', '123', '10.00');

        foreach (Bet::query()->get() as $bet) {
            $this->assertNotNull($bet->ticket_id, 'A committed bet must belong to a ticket.');
            $this->assertSame(BetStatus::Active, $bet->status);
            $this->assertSame(
                1,
                FinancialTransaction::query()
                    ->where('reference_type', Bet::class)
                    ->where('reference_id', $bet->getKey())
                    ->count(),
            );
        }
    }

    #[Test]
    public function an_leaves_no_bet_item_without_a_bet(): void
    {
        $this->buy('3d_direct', '123', '10.00');

        $orphans = BetItem::query()
            ->whereNotIn('bet_id', Bet::query()->select('id'))
            ->count();

        $this->assertSame(0, $orphans);
    }

    #[Test]
    public function ao_leaves_no_ticket_without_a_bet(): void
    {
        $this->buy('3d_direct', '123', '10.00');

        foreach (Ticket::query()->get() as $ticket) {
            $this->assertSame(1, $ticket->bets()->count());
            $this->assertSame(TicketStatus::Confirmed, $ticket->status);
            $this->assertSame('10.00', (string) $ticket->total_amount);
        }
    }

    // -----------------------------------------------------------------------------
    // AP-AR: one selection stays one selection
    // -----------------------------------------------------------------------------

    #[Test]
    public function ap_treats_tod_123_as_one_selection_covering_six_arrangements(): void
    {
        $fixture = $this->fixture(balance: '100.00');

        $result = $this->purchase($fixture, '3d_tod', '123', '10.00');

        $this->assertSame(1, Bet::query()->count());
        $this->assertSame(1, BetItem::query()->count());
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);

        $metadata = $result->item->refresh()->metadata;
        $this->assertSame(6, (int) ($metadata['covered_number_count'] ?? 0));
        $this->assertCount(6, (array) ($metadata['covered_numbers'] ?? []));
    }

    #[Test]
    public function aq_treats_tod_112_as_one_selection_covering_three_arrangements(): void
    {
        $fixture = $this->fixture(balance: '100.00');

        $result = $this->purchase($fixture, '3d_tod', '112', '10.00');

        $this->assertSame(1, BetItem::query()->count());
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);

        $metadata = $result->item->refresh()->metadata;
        $this->assertSame(3, (int) ($metadata['covered_number_count'] ?? 0));
    }

    #[Test]
    public function ar_treats_a_run_bet_as_one_payout_eligibility(): void
    {
        $fixture = $this->fixture(balance: '100.00');

        $result = $this->purchase($fixture, 'run_top', '7', '10.00');

        $this->assertSame(1, BetItem::query()->count());
        $this->assertSame('10.00', (string) $result->item->amount);
        // One eligibility priced once, whatever the drawn result repeats.
        $this->assertSame('30.00', (string) $result->item->potential_payout);
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);
    }

    // -----------------------------------------------------------------------------
    // AS: exact decimal arithmetic
    // -----------------------------------------------------------------------------

    #[Test]
    public function as_keeps_decimal_arithmetic_exact(): void
    {
        // The declared step is a whole unit, so a 10.55 stake is only sellable when the
        // step is relaxed. It is relaxed HERE ONLY, in this test, purely to exercise the
        // arithmetic; the shipped configuration is untouched.
        config(['lottery.betting.amount_step' => null]);

        $fixture = $this->fixture(balance: '100.55');

        $result = $this->purchase($fixture, '3d_direct', '123', '10.55');

        $this->assertSame('10.55', (string) $result->bet->stake_amount);
        $this->assertSame('9495.00', (string) $result->bet->potential_payout);
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);
        $this->assertSame(
            0,
            bccomp('9495.00', bcmul('10.55', '900', 2), 2),
            '10.55 x 900 must be exactly 9495.00.',
        );
    }

    // -----------------------------------------------------------------------------
    // AT-AW: compatibility
    // -----------------------------------------------------------------------------

    #[Test]
    public function at_remains_compatible_with_the_phase_three_risk_engine(): void
    {
        $result = $this->buy('3d_direct', '123', '10.00');

        $limit = $this->limitFor(Draw::query()->findOrFail($result->bet->draw_id), BetType::ThreeD, '123')->refresh();

        $this->assertSame('10.00', (string) $limit->current_amount);
        $this->assertSame(LimitStatus::Active, $limit->status);
        $this->assertNotSame([], $result->riskReservation);
        $this->assertTrue(($result->riskReservation['reserved'] ?? false) === true);
    }

    #[Test]
    public function au_remains_compatible_with_the_phase_two_financial_engine(): void
    {
        $result = $this->buy('3d_direct', '123', '10.00');

        $transaction = FinancialTransaction::query()->findOrFail($result->financialTransactionId());

        // Phase 2.1 records a bet stake as its own persisted transaction type; the
        // requested FinancialTransactionType::BetDebit is the engine's INPUT vocabulary
        // and TransactionType is what the column stores. The stored value is asserted
        // rather than the requested one, so this test cannot pass on an assumption.
        $this->assertSame(TransactionType::BetPlacement, $transaction->type);
        $this->assertSame(Bet::class, $transaction->reference_type);
        $this->assertSame($result->betId(), (int) $transaction->reference_id);
        $this->assertNotNull($transaction->idempotency_key);
    }

    #[Test]
    public function av_remains_compatible_with_the_phase_four_two_market_rules(): void
    {
        $expected = [
            '3d_direct' => ['123', '9000.00'],
            '3d_tod' => ['123', '450.00'],
            '2d_top' => ['45', '900.00'],
            '2d_bottom' => ['45', '900.00'],
            'run_top' => ['7', '30.00'],
            'run_bottom' => ['7', '40.00'],
        ];

        $fixture = $this->fixture(balance: '10000.00');

        foreach ($expected as $market => [$number, $payout]) {
            $result = $this->purchase($fixture, $market, $number, '10.00', key: $this->key($market));

            $this->assertSame(
                $payout,
                (string) $result->bet->potential_payout,
                sprintf('Market %s must price a 10.00 stake at %s.', $market, $payout),
            );
        }
    }

    #[Test]
    public function aw_leaves_the_existing_phase_two_wallet_api_working(): void
    {
        $fixture = $this->fixture(balance: '100.00');
        $wallets = app(WalletService::class);

        $before = $wallets->availableBalance($fixture['wallet']);
        $this->assertSame('100.00', $before->toString());

        $this->purchase($fixture, '3d_direct', '123', '10.00');

        $after = $wallets->availableBalance($fixture['wallet']->refresh());
        $this->assertSame('90.00', $after->toString());
        $this->assertSame('10.00', $before->minus($after)->toString());
    }

    // =============================================================================
    // Helpers
    // =============================================================================

    /**
     * A purchase against a freshly built fixture.
     */
    private function buy(string $market, string $number, string $stake): \App\DTOs\BetPurchaseResult
    {
        return $this->purchase($this->fixture(), $market, $number, $stake);
    }

    /**
     * @param  array{user: User, wallet: Wallet, draw: Draw}  $fixture
     */
    private function purchase(
        array $fixture,
        string $market,
        string $number,
        string $stake,
        ?string $key = null,
    ): \App\DTOs\BetPurchaseResult {
        $this->ensureLimit($fixture['draw'], $market, $number);

        return app(BetPurchaseService::class)->purchase(new BetPurchaseData(
            userId: (int) $fixture['user']->getKey(),
            drawId: (int) $fixture['draw']->getKey(),
            marketKey: $market,
            rawNumber: $number,
            rawStake: $stake,
            idempotencyKey: $key ?? $this->key($market.$number.$stake),
        ));
    }

    /**
     * A player with a funded active wallet and an open draw.
     *
     * @return array{user: User, wallet: Wallet, draw: Draw}
     */
    private function fixture(string $balance = '1000.00'): array
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance($balance)
            ->create();

        $draw = Draw::factory()->create([
            'type' => DrawType::ThreeD,
            'scheduled_at' => now()->addHour(),
        ]);

        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        return ['user' => $user, 'wallet' => $wallet, 'draw' => $draw];
    }

    /**
     * Phase 3.1 requires the number_limits row to exist before a number can be sold; it
     * does not create one implicitly, and a missing row is a refusal rather than an
     * implicit "unlimited". The fixture therefore provisions the row the same way an
     * operator would.
     */
    private function ensureLimit(Draw $draw, string $market, string $rawNumber): void
    {
        $definition = config('lottery.markets.'.$market);

        if (! is_array($definition)) {
            return;
        }

        $betType = BetType::tryFrom((string) ($definition['bet_type'] ?? ''));
        $digits = (int) ($definition['digits'] ?? 0);

        if ($betType === null || $digits < 1 || ! ctype_digit($rawNumber) || strlen($rawNumber) > $digits) {
            return;
        }

        $number = str_pad($rawNumber, $digits, '0', STR_PAD_LEFT);

        $this->limitRow((int) $draw->getKey(), $betType, $number);
    }

    /**
     * Provision or fetch a number_limits row.
     *
     * The counters and the status are deliberately outside NumberLimit::$fillable in
     * Phase 1, so that no request payload can ever mass-assign a risk counter. The
     * fixture therefore sets them explicitly rather than weakening the model.
     */
    private function limitRow(int $drawId, BetType $betType, string $number): NumberLimit
    {
        $limit = NumberLimit::query()
            ->where('draw_id', $drawId)
            ->where('bet_type', $betType->value)
            ->where('number', $number)
            ->first();

        if ($limit instanceof NumberLimit) {
            return $limit;
        }

        $limit = new NumberLimit();
        $limit->draw_id = $drawId;
        $limit->bet_type = $betType;
        $limit->number = $number;
        $limit->max_amount = '100000.00';
        $limit->current_amount = '0.00';
        $limit->maximum_payout_exposure = null;
        $limit->current_payout_exposure = '0.00';
        $limit->status = LimitStatus::Active;
        $limit->save();

        return $limit;
    }

    /**
     * Tighten an existing limit row for a test scenario.
     *
     * @param  array<string, string|null>  $attributes
     */
    private function tightenLimit(Draw $draw, BetType $betType, string $number, array $attributes): NumberLimit
    {
        $limit = $this->limitRow((int) $draw->getKey(), $betType, $number);

        foreach ($attributes as $column => $value) {
            $limit->{$column} = $value;
        }

        $limit->save();

        return $limit;
    }

    private function limitFor(Draw $draw, BetType $betType, string $number): NumberLimit
    {
        return $this->limitRow((int) $draw->getKey(), $betType, $number);
    }

    /**
     * The Phase 2.1 posting service resolves accounts by code and refuses to invent one,
     * so the chart of accounts is seeded here rather than assumed.
     */
    private function seedChartOfAccounts(): void
    {
        $accounts = [
            [self::ACCOUNT_SYSTEM_CASH, 'System Cash', 'asset'],
            [self::ACCOUNT_PLAYER_LIABILITY, 'Player Liability', 'liability'],
            [self::ACCOUNT_BET_REVENUE, 'Bet Revenue', 'revenue'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'currency' => Currency::primary()->value,
                    'is_active' => true,
                ],
            );
        }
    }

    private function key(string $seed): string
    {
        return 'phase43-'.substr(hash('sha256', $seed.'|'.spl_object_hash($this)), 0, 32);
    }

    /**
     * @return list<int>
     */
    private function entryIdsFor(?int $transactionId): array
    {
        if ($transactionId === null) {
            return [];
        }

        return LedgerEntry::query()
            ->where('financial_transaction_id', $transactionId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function assertNothingWasPersisted(): void
    {
        $this->assertSame(0, Bet::query()->withTrashed()->count(), 'No bet may exist.');
        $this->assertSame(0, BetItem::query()->withTrashed()->count(), 'No bet item may exist.');
        $this->assertSame(0, Ticket::query()->withTrashed()->count(), 'No ticket may exist.');
        $this->assertSame(0, FinancialTransaction::query()->withTrashed()->count(), 'No financial transaction may exist.');
        $this->assertSame(0, LedgerEntry::query()->withTrashed()->count(), 'No ledger entry may exist.');
    }

    /**
     * Force a failure at a chosen step by making the next insert into that table
     * impossible, then prove the whole purchase rolled back.
     *
     * The failure is injected at the DATABASE level rather than by mocking a service, so
     * the rollback being tested is the real one: the transaction the pipeline opened is
     * genuinely aborted by the engine, exactly as it would be by a production failure.
     */
    private function assertRollsBackWhenStepFails(string $table): void
    {
        $fixture = $this->fixture(balance: '100.00');
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $trigger = 'phase43_fail_'.$table;

        // The injection is written in the dialect of the connection actually in use.
        // MySQL/MariaDB raise a failure with SIGNAL; SQLite - the default connection in
        // phpunit.xml - has no SIGNAL and raises with RAISE(ABORT, ...). Both abort the
        // insert inside the pipeline's own transaction, which is the behaviour under test,
        // so the assertion is identical on either driver. Hard-coding SIGNAL made all four
        // of these tests error out with a syntax error on the documented default database.
        $driver = DB::connection()->getDriverName();

        $create = match ($driver) {
            'mysql', 'mariadb' => sprintf(
                'CREATE TRIGGER %s BEFORE INSERT ON %s FOR EACH ROW '
                .'SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'phase 4.3 injected failure\'',
                $trigger,
                $table,
            ),
            'sqlite' => sprintf(
                'CREATE TRIGGER %s BEFORE INSERT ON %s FOR EACH ROW '
                .'BEGIN SELECT RAISE(ABORT, \'phase 4.3 injected failure\'); END',
                $trigger,
                $table,
            ),
            'pgsql' => null,
            default => null,
        };

        if ($create === null) {
            $this->markTestSkipped(sprintf(
                'Database-level failure injection is implemented for MySQL/MariaDB and SQLite; the current driver is "%s".',
                $driver,
            ));
        }

        DB::unprepared($create);

        try {
            $caught = null;

            try {
                $this->purchase($fixture, '3d_direct', '123', '10.00');
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            $this->assertNotNull($caught, sprintf('A failure inserting into %s must abort the purchase.', $table));
            $this->assertNothingWasPersisted();
            $this->assertSame(
                '100.00',
                (string) $fixture['wallet']->refresh()->balance,
                'The wallet must be untouched after a rollback.',
            );
            $this->assertSame(
                '0.00',
                (string) $this->limitFor($fixture['draw'], BetType::ThreeD, '123')->refresh()->current_amount,
                'Risk capacity must not leak after a rollback.',
            );
        } finally {
            DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $trigger));
        }
    }

    // -----------------------------------------------------------------------------
    // Real multi-process concurrency
    // -----------------------------------------------------------------------------

    /**
     * @param  array{user: User, wallet: Wallet, draw: Draw}  $fixture
     */
    private function purchaseCommand(
        array $fixture,
        string $market,
        string $number,
        string $stake,
        string $key,
    ): string {
        return sprintf(
            '%s %s %d %d %s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->probeScript()),
            (int) $fixture['user']->getKey(),
            (int) $fixture['draw']->getKey(),
            escapeshellarg($market),
            escapeshellarg($number),
            escapeshellarg($stake),
            escapeshellarg($key),
        );
    }


    /**
     * Write the concurrency probe to a temporary location and return its path.
     *
     * It is generated at run time, into storage, rather than shipped as a console
     * command, for two reasons: the Phase 4.3 file scope is fixed at twenty files and a
     * probe is not one of them, and a harness that can place a real bet has no business
     * being registered in the application's command list where an operator could invoke
     * it by accident. It lives in the testing scratch directory and is never packaged.
     */
    private function probeScript(): string
    {
        $path = storage_path('framework/testing/phase43-purchase-probe.php');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $source = <<<'PROBE'
<?php

declare(strict_types=1);

// Temporary Phase 4.3 concurrency harness. Generated by the test suite; not shipped.

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$self, $userId, $drawId, $market, $number, $stake, $key, $barrier] = array_pad($argv, 9, null);

// Wait on the barrier so both processes attempt the purchase at the same instant.
if (is_string($barrier)) {
    $deadline = microtime(true) + 20.0;

    while (! file_exists($barrier) && microtime(true) < $deadline) {
        usleep(1000);
    }
}

try {
    $result = $app->make(App\Services\Betting\BetPurchaseService::class)->purchase(
        new App\DTOs\BetPurchaseData(
            userId: (int) $userId,
            drawId: (int) $drawId,
            marketKey: (string) $market,
            rawNumber: (string) $number,
            rawStake: (string) $stake,
            idempotencyKey: (string) $key,
        ),
    );

    echo json_encode([
        'outcome' => $result->status->value,
        'bet_id' => $result->betId(),
        'ticket_id' => $result->ticketId(),
        'financial_transaction_id' => $result->financialTransactionId(),
    ]);

    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'outcome' => 'refused',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ]);

    exit(0);
}
PROBE;

        file_put_contents($path, $source);

        return $path;
    }

    /**
     * Run commands in genuinely separate OS processes, started together.
     *
     * Two sequential in-process calls could never detect a missing row lock, because a
     * single process cannot contend with itself. Separate processes hold separate
     * database connections and separate transactions, which is the only arrangement in
     * which SELECT ... FOR UPDATE is actually exercised.
     *
     * @param  list<string>  $commands
     * @return list<array<string, mixed>>
     */
    private function runConcurrently(array $commands): array
    {
        $processes = [];
        $pipes = [];

        // A shared barrier file makes the children start their purchase at the same
        // moment rather than in the order they happened to boot.
        $barrier = storage_path('framework/testing/phase43-barrier-'.bin2hex(random_bytes(6)));
        @unlink($barrier);

        foreach ($commands as $index => $command) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                $command.' '.escapeshellarg($barrier),
                $descriptors,
                $processPipes,
                base_path(),
            );

            if (! is_resource($process)) {
                $this->fail('A concurrency probe process could not be started.');
            }

            $processes[$index] = $process;
            $pipes[$index] = $processPipes;
        }

        // Release the barrier once both children are up.
        usleep(400000);
        file_put_contents($barrier, 'go');

        $outcomes = [];

        foreach ($processes as $index => $process) {
            $stdout = (string) stream_get_contents($pipes[$index][1]);
            $stderr = (string) stream_get_contents($pipes[$index][2]);
            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            $exit = proc_close($process);

            $decoded = json_decode(trim($stdout), true);

            $outcomes[] = is_array($decoded)
                ? $decoded
                : ['outcome' => 'unparsable', 'exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        @unlink($barrier);

        return $outcomes;
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     */
    private function countOutcome(array $outcomes, string $outcome): int
    {
        $count = 0;

        foreach ($outcomes as $entry) {
            $value = $entry['outcome'] ?? null;

            if ($outcome === 'purchased' && ($value === 'purchased' || $value === 'replayed')) {
                $count++;

                continue;
            }

            if ($value === $outcome) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     */
    private function describe(array $outcomes): string
    {
        return 'Concurrency probe outcomes: '.json_encode($outcomes);
    }

    /**
     * Row locks only exist on a real server. SQLite in memory gives each connection its
     * own private database, so a concurrency claim measured there would be meaningless.
     */
    private function requiresRealConcurrency(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped(
                'Real multi-process concurrency requires a shared server with row locks; '
                .'SQLite in memory gives each process its own database.'
            );
        }
    }

    /**
     * FAIL SAFE. The suite aborts rather than touch a database it was not told to use.
     */
    private function assertTestDatabaseOnly(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        $basename = basename($database);

        if (! in_array($database, self::ALLOWED_DATABASES, true)
            && ! in_array($basename, self::ALLOWED_DATABASES, true)) {
            $this->fail(sprintf(
                'ABORTED: this suite may only run against %s. The connection points at "%s".',
                implode(' or ', self::ALLOWED_DATABASES),
                $database,
            ));
        }
    }
}
