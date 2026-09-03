<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\BetStatus;
use App\Enums\DrawLifecycleState;
use App\Enums\DrawStatus;
use App\Enums\SettlementSimulationStatus;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\SettlementSimulationException;
use App\Models\AuditLog;
use App\Models\BetItem;
use App\Services\Betting\TodMatchService;
use App\Services\Draw\DrawSettlementSimulationService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * Phase 5.1 requirements D, E, F and G, and requirement I points 4 to 13, 18 to 20,
 * 25 and 26.
 *
 * Market by market settlement against a published result, then the transactional
 * properties: idempotency, rollback and concurrency.
 */
final class SettlementSimulationTest extends SettlementTestCase
{
    // -------------------------------------------------------------------------
    // Point 4: 3D Direct
    // -------------------------------------------------------------------------

    #[Test]
    public function point_04_settles_a_winning_three_digit_direct_selection(): void
    {
        // First prize 456123: the three digit top result is 123.
        $settled = $this->settleOne('3d_direct', '123');
        $record = $settled['record'];

        $this->assertTrue($record->isWinner());
        $this->assertSame('3d_direct', $record->marketKey);
        $this->assertSame('123', $record->selection);
        $this->assertSame('123', $record->winningValue);
        $this->assertSame('exact', $record->matchMode);
        $this->assertSame(SettlementSimulationStatus::Won, $record->status);
        $this->assertSame($this->configuredMultiplier('3d_direct'), $record->multiplier->integerPart());
        $this->assertSame($this->expectedPrize('10.00', '3d_direct'), $record->simulatedPrize());
        $this->assertSame('9000.00', $record->simulatedPrize());
    }

    #[Test]
    public function point_04b_settles_a_losing_three_digit_direct_selection(): void
    {
        $record = $this->settleOne('3d_direct', '124')['record'];

        $this->assertFalse($record->isWinner());
        $this->assertSame(SettlementSimulationStatus::Lost, $record->status);
        $this->assertSame('0.00', $record->simulatedPrize());
        $this->assertTrue($record->simulatedPrizeIsZero());
        // A loss still records the rate that would have applied.
        $this->assertSame($this->configuredMultiplier('3d_direct'), $record->multiplier->integerPart());
    }

    #[Test]
    public function point_04c_a_permutation_does_not_win_the_direct_market(): void
    {
        // 321 is an arrangement of 123 but Direct requires the exact order.
        $record = $this->settleOne('3d_direct', '321')['record'];

        $this->assertFalse($record->isWinner());
        $this->assertSame('0.00', $record->simulatedPrize());
    }

    // -------------------------------------------------------------------------
    // Point 5: 3D Tod
    // -------------------------------------------------------------------------

    #[Test]
    public function point_05_settles_a_winning_three_digit_tod_selection(): void
    {
        // 321 is an arrangement of the drawn 123, so Tod wins.
        $record = $this->settleOne('3d_tod', '321')['record'];

        $this->assertTrue($record->isWinner());
        $this->assertSame('3d_tod', $record->marketKey);
        $this->assertSame('permutation', $record->matchMode);
        $this->assertSame('123', $record->winningValue);
        $this->assertSame($this->configuredMultiplier('3d_tod'), $record->multiplier->integerPart());
        $this->assertSame('450.00', $record->simulatedPrize());
        $this->assertSame($this->expectedPrize('10.00', '3d_tod'), $record->simulatedPrize());
    }

    #[Test]
    public function point_05b_a_tod_selection_pays_once_and_not_once_per_arrangement(): void
    {
        $record = $this->settleOne('3d_tod', '321')['record'];

        $this->assertSame(1, $record->charges, 'One selection is charged exactly once.');
        $this->assertSame(6, $record->coveredNumberCount, '321 covers six arrangements.');
        $this->assertTrue($record->chargedOnce());
        $this->assertTrue($record->payoutIsSinglyCharged());

        // The prize is the stake times the rate ONCE. Six times that would be 2700.00.
        $this->assertSame('450.00', $record->simulatedPrize());
        $this->assertNotSame('2700.00', $record->simulatedPrize());

        // And one selection is still one stored ticket item.
        $this->assertSame(
            1,
            BetItem::query()->where('bet_id', $record->betId)->count(),
            'A Tod selection remains a single simulated ticket item.',
        );
    }

    #[Test]
    public function point_05c_a_tod_selection_that_shares_no_digits_loses(): void
    {
        $record = $this->settleOne('3d_tod', '789')['record'];

        $this->assertFalse($record->isWinner());
        $this->assertSame('0.00', $record->simulatedPrize());
    }

    // -------------------------------------------------------------------------
    // Points 6 to 9: the declared Tod coverage counts
    // -------------------------------------------------------------------------

    #[Test]
    public function point_06_the_selection_123_covers_six_arrangements(): void
    {
        // Drawn 456123, so 123 wins as Tod and reports its coverage of six.
        $record = $this->settleOne('3d_tod', '123')['record'];

        $this->assertTrue($record->isWinner());
        $this->assertSame(6, $record->coveredNumberCount);
        $this->assertSame(1, $record->charges);
        $this->assertSame('450.00', $record->simulatedPrize());
        $this->assertSame(6, app(TodMatchService::class)->permutationCount('123'));
    }

    #[Test]
    public function point_07_the_selection_112_covers_three_arrangements(): void
    {
        // Drawn 999121: the three digit top is 121, an arrangement of 112.
        $record = $this->settleOne('3d_tod', '112', '999121', '45')['record'];

        $this->assertTrue($record->isWinner());
        $this->assertSame(3, $record->coveredNumberCount, '112 covers exactly three arrangements.');
        $this->assertSame(1, $record->charges);
        $this->assertSame('450.00', $record->simulatedPrize());
        $this->assertSame(3, app(TodMatchService::class)->permutationCount('112'));
    }

    #[Test]
    public function point_08_the_selection_111_covers_one_arrangement(): void
    {
        // Drawn 999111.
        $record = $this->settleOne('3d_tod', '111', '999111', '45')['record'];

        $this->assertTrue($record->isWinner());
        $this->assertSame(1, $record->coveredNumberCount, '111 covers exactly one arrangement.');
        $this->assertSame(1, $record->charges);
        $this->assertSame('450.00', $record->simulatedPrize());
        $this->assertSame(1, app(TodMatchService::class)->permutationCount('111'));
    }

    #[Test]
    public function point_09_the_selection_007_covers_three_arrangements_and_is_never_read_as_seven(): void
    {
        // Drawn 999700: the three digit top is 700, an arrangement of the digits 0, 0, 7.
        $record = $this->settleOne('3d_tod', '007', '999700', '45')['record'];

        $this->assertSame('007', $record->selection, 'The stored selection keeps both zeroes.');
        $this->assertSame('700', $record->winningValue);
        $this->assertTrue($record->isWinner(), '007 must match a drawn 700 as an arrangement.');
        $this->assertSame(3, $record->coveredNumberCount, '007 covers 007, 070 and 700.');
        $this->assertSame(1, $record->charges);
        $this->assertSame('450.00', $record->simulatedPrize());

        // The coverage of 007 is three, and the verified rule engine refuses the single
        // digit "7" outright rather than treating it as the same selection.
        $this->assertSame(3, app(TodMatchService::class)->permutationCount('007'));

        try {
            app(TodMatchService::class)->permutationCount('7');
            $this->fail('A one digit selection must not be accepted as a 3D Tod selection.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('7', $exception->getMessage());
        }

        // And 007 does not win a draw whose top three are 070's neighbour 077.
        $other = $this->settleOne('3d_tod', '007', '999077', '45')['record'];
        $this->assertFalse($other->isWinner());
    }

    // -------------------------------------------------------------------------
    // Points 10 and 11: the two digit markets
    // -------------------------------------------------------------------------

    #[Test]
    public function point_10_settles_a_two_digit_top_selection(): void
    {
        // First prize 456123: the two digit top result is 23.
        $winner = $this->settleOne('2d_top', '23')['record'];

        $this->assertTrue($winner->isWinner());
        $this->assertSame('first_prize_last_two', $winner->context['result_type']);
        $this->assertSame('23', $winner->winningValue);
        $this->assertSame($this->configuredMultiplier('2d_top'), $winner->multiplier->integerPart());
        $this->assertSame('900.00', $winner->simulatedPrize());

        // The bottom result of 45 must not win the TOP market.
        $loser = $this->settleOne('2d_top', '45')['record'];
        $this->assertFalse($loser->isWinner());
        $this->assertSame('0.00', $loser->simulatedPrize());
    }

    #[Test]
    public function point_11_settles_a_two_digit_bottom_selection(): void
    {
        // The bottom two is 45.
        $winner = $this->settleOne('2d_bottom', '45')['record'];

        $this->assertTrue($winner->isWinner());
        $this->assertSame('bottom_two', $winner->context['result_type']);
        $this->assertSame('45', $winner->winningValue);
        $this->assertSame($this->configuredMultiplier('2d_bottom'), $winner->multiplier->integerPart());
        $this->assertSame('900.00', $winner->simulatedPrize());

        // The top result of 23 must not win the BOTTOM market.
        $loser = $this->settleOne('2d_bottom', '23')['record'];
        $this->assertFalse($loser->isWinner());
    }

    // -------------------------------------------------------------------------
    // Points 12 and 13: the Run markets
    // -------------------------------------------------------------------------

    #[Test]
    public function point_12_settles_a_run_top_selection_at_its_own_configured_rate(): void
    {
        // The three digit top is 123, so the digit 1 runs.
        $winner = $this->settleOne('run_top', '1')['record'];

        $this->assertTrue($winner->isWinner());
        $this->assertSame('run_top', $winner->marketKey);
        $this->assertSame('digit_contains', $winner->matchMode);
        $this->assertSame('123', $winner->winningValue);
        $this->assertSame(1, $winner->charges, 'Run has no permutation and pays once.');

        // The rate is the market's own, which is 3 here and NOT the legacy BetType::Run
        // multiplier of 12.
        $this->assertSame($this->configuredMultiplier('run_top'), $winner->multiplier->integerPart());
        $this->assertSame($this->expectedPrize('10.00', 'run_top'), $winner->simulatedPrize());
        $this->assertSame('30.00', $winner->simulatedPrize());
        $this->assertTrue($winner->legacyMultiplierWasAvoided('12'));
        $this->assertNotSame('120.00', $winner->simulatedPrize());

        $loser = $this->settleOne('run_top', '8')['record'];
        $this->assertFalse($loser->isWinner());
        $this->assertSame('0.00', $loser->simulatedPrize());
    }

    #[Test]
    public function point_13_settles_a_run_bottom_selection_at_its_own_configured_rate(): void
    {
        // The bottom two is 45, so the digit 4 runs.
        $winner = $this->settleOne('run_bottom', '4')['record'];

        $this->assertTrue($winner->isWinner());
        $this->assertSame('run_bottom', $winner->marketKey);
        $this->assertSame('bottom_two', $winner->context['result_type']);
        $this->assertSame('45', $winner->winningValue);

        $this->assertSame($this->configuredMultiplier('run_bottom'), $winner->multiplier->integerPart());
        $this->assertSame($this->expectedPrize('10.00', 'run_bottom'), $winner->simulatedPrize());
        $this->assertSame('40.00', $winner->simulatedPrize());
        $this->assertTrue($winner->legacyMultiplierWasAvoided('12'));

        // Run Top and Run Bottom really are settled at DIFFERENT configured rates.
        $topRate = $this->configuredMultiplier('run_top');
        $bottomRate = $this->configuredMultiplier('run_bottom');
        $this->assertNotSame($topRate, $bottomRate);

        $loser = $this->settleOne('run_bottom', '8')['record'];
        $this->assertFalse($loser->isWinner());
    }

    #[Test]
    public function point_13b_a_run_digit_appearing_twice_still_pays_once(): void
    {
        // The three digit top is 121 - the digit 1 occurs twice.
        $record = $this->settleOne('run_top', '1', '999121', '45')['record'];

        $this->assertTrue($record->isWinner());
        $this->assertSame(2, $record->occurrences, 'The digit occurs twice in 121.');
        $this->assertSame(1, $record->charges, 'It still pays exactly once.');
        $this->assertSame('30.00', $record->simulatedPrize());
        $this->assertNotSame('60.00', $record->simulatedPrize());
        $this->assertSame(1, $record->context['payout_count']);
    }

    // -------------------------------------------------------------------------
    // Point 14 in the settlement context: leading zeroes end to end
    // -------------------------------------------------------------------------

    #[Test]
    public function point_14_settles_a_leading_zero_selection_without_normalising_it(): void
    {
        $settled = $this->settleOne('3d_direct', '007', '000007', '05');
        $record = $settled['record'];

        $this->assertSame('007', $record->selection);
        $this->assertSame('007', $record->winningValue);
        $this->assertTrue($record->isWinner());
        $this->assertSame('9000.00', $record->simulatedPrize());

        // Stored exactly, as a string.
        $this->assertSame(
            '007',
            (string) DB::table('bet_items')->where('id', $record->betItemId)->value('number'),
        );

        // The two digit top of 000007 is 07, and 7 is not the same selection.
        $seven = $this->settleOne('2d_top', '07', '000007', '05')['record'];
        $this->assertTrue($seven->isWinner());
        $this->assertSame('07', $seven->winningValue);
    }

    // -------------------------------------------------------------------------
    // Point 18: idempotency, from the database
    // -------------------------------------------------------------------------

    #[Test]
    public function point_18_settling_twice_produces_the_same_result_and_writes_once(): void
    {
        $fixture = $this->fixture();
        $this->purchase($fixture, '3d_direct', '123');
        $this->purchase($fixture, '2d_bottom', '45');
        $this->purchase($fixture, 'run_top', '9');
        $this->publishResult($fixture['draw']);

        $drawId = (int) $fixture['draw']->getKey();
        $service = $this->settlement();

        $first = $service->settle($drawId);
        $second = $service->settle($drawId);
        $third = $service->settle($drawId);

        // Identical outcome, three runs.
        $this->assertSame($first->idempotencyFingerprint(), $second->idempotencyFingerprint());
        $this->assertSame($first->idempotencyFingerprint(), $third->idempotencyFingerprint());
        $this->assertSame($first->totalSimulatedPrize, $second->totalSimulatedPrize);
        $this->assertSame(2, $first->winningSelections);

        // Only the first run wrote anything.
        $this->assertFalse($first->alreadySettled);
        $this->assertTrue($second->alreadySettled);
        $this->assertTrue($third->alreadySettled);
        $this->assertSame(3, $first->selectionsWritten);
        $this->assertSame(0, $second->selectionsWritten);
        $this->assertSame(0, $second->betsUpdated);
        $this->assertTrue($second->wroteNothing());

        // And exactly one audit row exists for the run.
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('auditable_type', \App\Models\Draw::class)
                ->where('auditable_id', $drawId)
                ->where('description', 'like', 'Simulated settlement%')
                ->count(),
            'A repeated settlement must not add a second audit row.',
        );
    }

    #[Test]
    public function point_18b_idempotency_survives_a_cleared_cache(): void
    {
        $settled = $this->settleOne('3d_direct', '123');
        $drawId = (int) $settled['fixture']['draw']->getKey();

        // Nothing about the guarantee may depend on a cache, so clear it and try again.
        \Illuminate\Support\Facades\Cache::clear();

        $again = $this->settlement()->settle($drawId);

        $this->assertTrue($again->alreadySettled);
        $this->assertSame(0, $again->selectionsWritten);
        $this->assertSame(
            $settled['simulation']->idempotencyFingerprint(),
            $again->idempotencyFingerprint(),
        );
    }

    #[Test]
    public function point_18c_a_second_run_does_not_duplicate_any_simulation_record(): void
    {
        $settled = $this->settleOne('3d_direct', '123');
        $drawId = (int) $settled['fixture']['draw']->getKey();

        $before = [
            'bet_items' => (int) DB::table('bet_items')->count(),
            'bets' => (int) DB::table('bets')->count(),
            'winning_numbers' => (int) DB::table('winning_numbers')->count(),
            'draw_results' => (int) DB::table('draw_results')->count(),
            'audit_logs' => (int) DB::table('audit_logs')->count(),
        ];

        $this->settlement()->settle($drawId);
        $this->settlement()->settle($drawId);

        foreach ($before as $table => $count) {
            $this->assertSame(
                $count,
                (int) DB::table($table)->count(),
                'A repeated settlement must not add a '.$table.' row.',
            );
        }
    }

    #[Test]
    public function point_18d_settlement_is_refused_before_the_result_is_published(): void
    {
        foreach ([
            DrawLifecycleState::Draft,
            DrawLifecycleState::Open,
            DrawLifecycleState::Closed,
            DrawLifecycleState::ResultPending,
            DrawLifecycleState::Cancelled,
        ] as $state) {
            $draw = $this->drawInState($state);

            try {
                $this->settlement()->settle((int) $draw->getKey());
                $this->fail('Settlement must be refused from '.$state->value.'.');
            } catch (DrawLifecycleException $exception) {
                $this->assertSame('DRAW_NOT_SETTLEABLE', $exception->errorCode());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Point 19: all or nothing
    // -------------------------------------------------------------------------

    #[Test]
    public function point_19_rolls_back_completely_when_one_selection_cannot_be_settled(): void
    {
        $fixture = $this->fixture();
        $good = $this->purchase($fixture, '3d_direct', '123');
        $bad = $this->purchase($fixture, '3d_direct', '456');
        $alsoGood = $this->purchase($fixture, '2d_bottom', '45');
        $this->publishResult($fixture['draw']);

        $drawId = (int) $fixture['draw']->getKey();

        // Corrupt ONE stored selection so the resolver refuses it: a 3D selection of
        // two digits is not a width the market accepts, and settlement must refuse it
        // rather than pad it. Written with the query builder because the model
        // deliberately guards these columns.
        DB::table('bet_items')->where('id', $bad->betItemId())->update(['number' => '45']);

        $financeBefore = $this->financeSnapshot();

        try {
            $this->settlement()->settle($drawId);
            $this->fail('A selection that cannot be settled must abort the whole run.');
        } catch (SettlementSimulationException $exception) {
            $this->assertSame('SETTLEMENT_SELECTION_UNREADABLE', $exception->errorCode());
        }

        // NOTHING was written. Not the good selection that came first, not the bet, not
        // the draw state, not an audit row.
        foreach ([$good->betItemId(), $bad->betItemId(), $alsoGood->betItemId()] as $itemId) {
            $row = DB::table('bet_items')->where('id', $itemId)->first();

            $this->assertNotNull($row);
            // bet_items.is_winner is a NOT NULL boolean defaulting to false, so an
            // unsettled selection reads as false rather than as null. What a rolled
            // back run must not leave behind is a TRUE flag or a non-zero prize.
            $this->assertSame(0, (int) $row->is_winner, 'No winner flag may survive a rolled back run.');
            $this->assertStoredMoneySame('0.00', $row->actual_payout);
        }

        $this->assertSame(
            DrawStatus::ResultPublished->value,
            (string) DB::table('draws')->where('id', $drawId)->value('status'),
            'A rolled back settlement must leave the draw unsettled.',
        );
        $this->assertNull(DB::table('draws')->where('id', $drawId)->value('completed_at'));
        $this->assertSame(
            0,
            AuditLog::query()->where('description', 'like', 'Simulated settlement%')->count(),
            'A rolled back settlement must leave no audit row claiming it happened.',
        );

        $this->assertFinanceUnchanged($financeBefore, 'a rolled back settlement');

        // No bet was marked won or lost.
        foreach (DB::table('bets')->where('draw_id', $drawId)->get() as $bet) {
            $this->assertNotSame(BetStatus::Won->value, (string) $bet->status);
            $this->assertNotSame(BetStatus::Lost->value, (string) $bet->status);
        }
    }

    #[Test]
    public function point_19b_refuses_to_run_inside_a_caller_transaction(): void
    {
        $settledFixture = $this->fixture();
        $this->purchase($settledFixture, '3d_direct', '123');
        $this->publishResult($settledFixture['draw']);
        $drawId = (int) $settledFixture['draw']->getKey();

        DB::beginTransaction();

        try {
            $this->settlement()->settle($drawId);
            $this->fail('Settlement must refuse to run inside a caller transaction.');
        } catch (SettlementSimulationException $exception) {
            $this->assertSame('SETTLEMENT_ALREADY_RUNNING', $exception->errorCode());
        } finally {
            DB::rollBack();
        }

        // And it is still settleable afterwards, because the refusal changed nothing.
        $result = $this->settlement()->settle($drawId);
        $this->assertFalse($result->alreadySettled);
        $this->assertSame(1, $result->selectionsWritten);
    }

    #[Test]
    public function point_19c_a_contradictory_stored_market_aborts_the_run(): void
    {
        $fixture = $this->fixture();
        $purchase = $this->purchase($fixture, '3d_direct', '123');
        $this->publishResult($fixture['draw']);

        // Claim in metadata that a 3D Direct selection was a 2D Bottom one. The market
        // is cross-checked, so this contradiction must abort rather than settle under
        // whichever looked more plausible.
        DB::table('bet_items')->where('id', $purchase->betItemId())->update([
            'metadata' => json_encode(['market' => '2d_bottom']),
        ]);

        try {
            $this->settlement()->settle((int) $fixture['draw']->getKey());
            $this->fail('A contradictory recorded market must abort the run.');
        } catch (SettlementSimulationException $exception) {
            $this->assertSame('SETTLEMENT_MARKET_MISMATCH', $exception->errorCode());
        }

        $this->assertSame(
            0,
            (int) DB::table('bet_items')->where('id', $purchase->betItemId())->value('is_winner'),
        );
        $this->assertStoredMoneySame(
            '0.00',
            DB::table('bet_items')->where('id', $purchase->betItemId())->value('actual_payout'),
        );
    }

    // -------------------------------------------------------------------------
    // Point 20: concurrency, in real separate processes
    // -------------------------------------------------------------------------

    #[Test]
    public function point_20_lets_only_one_of_two_concurrent_settlements_write(): void
    {
        $this->requiresRealConcurrency();

        $fixture = $this->fixture();
        $this->purchase($fixture, '3d_direct', '123');
        $this->purchase($fixture, '2d_bottom', '45');
        $this->publishResult($fixture['draw']);

        $drawId = (int) $fixture['draw']->getKey();
        $financeBefore = $this->financeSnapshot();

        $outcomes = $this->runConcurrently([
            $this->settleCommand($drawId),
            $this->settleCommand($drawId),
        ]);

        $this->assertSame(1, $this->countOutcome($outcomes, 'settled'), $this->describe($outcomes));
        $this->assertSame(
            1,
            $this->countOutcome($outcomes, 'already_settled'),
            $this->describe($outcomes),
        );
        $this->assertSame(0, $this->countOutcome($outcomes, 'refused'), $this->describe($outcomes));

        // Both processes report the SAME outcome.
        $this->assertSame(
            $outcomes[0]['fingerprint'],
            $outcomes[1]['fingerprint'],
            $this->describe($outcomes),
        );

        // Exactly one audit row, and every selection written exactly once.
        $this->assertSame(
            1,
            AuditLog::query()->where('description', 'like', 'Simulated settlement%')->count(),
            $this->describe($outcomes),
        );
        $this->assertSame(
            2,
            BetItem::query()->where('is_winner', true)->count(),
            $this->describe($outcomes),
        );
        $this->assertSame(
            '9900.00',
            (string) BetItem::query()->sum('actual_payout'),
            'Each selection is settled exactly once, so the simulated prizes are not doubled. '
            .$this->describe($outcomes),
        );
        $this->assertSame(
            DrawStatus::Completed->value,
            (string) DB::table('draws')->where('id', $drawId)->value('status'),
        );

        $this->assertFinanceUnchanged($financeBefore, 'two concurrent settlements');
    }

    // -------------------------------------------------------------------------
    // Points 25 and 26: exact decimals and the authoritative multiplier
    // -------------------------------------------------------------------------

    #[Test]
    public function point_25_computes_the_simulated_prize_as_an_exact_decimal(): void
    {
        // config('lottery.betting') sets a minimum stake of 10.00 and a step of 1.00, so
        // the stakes here are whole units - the exactness being proved is of the PRODUCT
        // and of the accumulated total, both computed with BCMath.
        $record = $this->settleOne('3d_tod', '321', self::FIRST_PRIZE, self::BOTTOM_TWO, '11.00')['record'];

        $this->assertTrue($record->isWinner());
        $this->assertSame('11.00', $record->stake);
        $this->assertSame('495.00', $record->simulatedPrize());
        $this->assertSame(bcmul('11.00', $this->configuredMultiplier('3d_tod'), 2), $record->simulatedPrize());
        $this->assertFalse($record->wasRounded, 'An exact product needs no rounding.');

        // Two decimal places, always, and nothing that looks like a float.
        $this->assertMatchesRegularExpression('/^[0-9]+\.[0-9]{2}$/', $record->simulatedPrize());
        $this->assertStringNotContainsString('E', $record->simulatedPrize());
        $this->assertStringNotContainsString('e', $record->simulatedPrize());

        // Stored to the cent, exactly.
        $this->assertStoredMoneySame(
            '495.00',
            DB::table('bet_items')->where('id', $record->betItemId)->value('actual_payout'),
        );
    }

    #[Test]
    public function point_25b_a_large_product_is_exact_to_the_cent(): void
    {
        // The largest stake the Phase 3.1 payout exposure ceiling allows on this market
        // (100.00 x 900 = 90000.00, against a per number ceiling of 100000.00), so the
        // product is as large as the verified risk rules permit.
        $record = $this->settleOne(
            '3d_direct',
            '123',
            self::FIRST_PRIZE,
            self::BOTTOM_TWO,
            '100.00',
        )['record'];

        $this->assertTrue($record->isWinner());
        $this->assertSame('90000.00', $record->simulatedPrize());
        $this->assertSame(
            bcmul('100.00', $this->configuredMultiplier('3d_direct'), 2),
            $record->simulatedPrize(),
        );
        $this->assertFalse($record->wasRounded);
        // The untruncated product the verified calculator computed, at whatever scale
        // the multiplier carries. Compared numerically so the assertion does not depend
        // on that scale.
        $this->assertNotNull($record->exactProduct);
        $this->assertSame(0, bccomp((string) $record->exactProduct, '90000', 4));
        $this->assertStoredMoneySame(
            '90000.00',
            DB::table('bet_items')->where('id', $record->betItemId)->value('actual_payout'),
        );
    }

    #[Test]
    public function point_25c_the_reported_totals_are_the_exact_sums_of_the_selections(): void
    {
        $fixture = $this->fixture();
        $this->purchase($fixture, '3d_direct', '123', '10.00');
        $this->purchase($fixture, '2d_top', '23', '20.00');
        $this->purchase($fixture, 'run_bottom', '4', '30.00');
        $this->publishResult($fixture['draw']);

        $simulation = $this->settlement()->settle((int) $fixture['draw']->getKey());

        $expectedStake = bcadd(bcadd('10.00', '20.00', 2), '30.00', 2);
        $expectedPrize = bcadd(
            bcadd(
                bcmul('10.00', $this->configuredMultiplier('3d_direct'), 2),
                bcmul('20.00', $this->configuredMultiplier('2d_top'), 2),
                2,
            ),
            bcmul('30.00', $this->configuredMultiplier('run_bottom'), 2),
            2,
        );

        $this->assertSame('60.00', $expectedStake);
        $this->assertSame('10920.00', $expectedPrize);
        $this->assertSame($expectedStake, $simulation->totalStake);
        $this->assertSame($expectedPrize, $simulation->totalSimulatedPrize);
        $this->assertTrue($simulation->totalMatchesSelections());
        $this->assertTrue($simulation->everySelectionChargedOnce());
        $this->assertSame(3, $simulation->winningSelections);
    }

    #[Test]
    public function point_26_uses_the_configured_multiplier_as_the_only_authority(): void
    {
        $markets = [
            '3d_direct' => '123',
            '3d_tod' => '321',
            '2d_top' => '23',
            '2d_bottom' => '45',
            'run_top' => '1',
            'run_bottom' => '4',
        ];

        foreach ($markets as $market => $selection) {
            $record = $this->settleOne($market, $selection)['record'];

            $this->assertTrue($record->isWinner(), $market.' must win.');
            $this->assertSame(
                $this->configuredMultiplier($market),
                $record->multiplier->integerPart(),
                'The rate applied to '.$market.' must be the configured one.',
            );
            $this->assertSame(
                $this->expectedPrize('10.00', $market),
                $record->simulatedPrize(),
                'The prize for '.$market.' must be stake x configured rate, once.',
            );
            $this->assertSame(
                'lottery.markets.'.$market.'.payout_multiplier',
                $record->context['multiplier_source'],
            );
            $this->assertTrue($record->multiplierFitsBetItemColumn());
            $this->assertTrue($record->legacyMultiplierWasAvoided('12'));
        }
    }

    #[Test]
    public function point_26b_stores_the_configured_multiplier_on_the_settled_selection(): void
    {
        $record = $this->settleOne('run_top', '1')['record'];

        // bet_items.payout_multiplier is an unsigned integer column. The configured
        // Run Top rate of 3 is stored, not the legacy BetType::Run rate of 12.
        $this->assertSame(
            (int) $this->configuredMultiplier('run_top'),
            (int) DB::table('bet_items')->where('id', $record->betItemId)->value('payout_multiplier'),
        );
        $this->assertNotSame(
            12,
            (int) DB::table('bet_items')->where('id', $record->betItemId)->value('payout_multiplier'),
        );
    }

    // -------------------------------------------------------------------------
    // The settled record and the state it leaves behind
    // -------------------------------------------------------------------------

    #[Test]
    public function the_simulation_result_reports_every_field_the_requirement_asks_for(): void
    {
        $settled = $this->settleOne('3d_direct', '123');
        $record = $settled['record'];
        $array = $record->toArray();

        // Requirement G names the fields the audit record must show.
        foreach ([
            'ticket_number',
            'bet_item_id',
            'selected_number',
            'market',
            'winning_number',
            'winning_status',
            'configured_multiplier',
            'simulated_prize_amount',
            'settlement_status',
        ] as $field) {
            $this->assertArrayHasKey($field, $array, 'The audit record must report '.$field.'.');
        }

        $this->assertNotNull($record->ticketNumber);
        $this->assertSame(SettlementSimulationStatus::Won->value, $array['settlement_status']);
        $this->assertSame('won', $array['winning_status']);
        $this->assertSame('none', $array['financial_effect']);
        $this->assertTrue($array['is_simulation']);
        $this->assertFalse($array['wallet_credited']);
        $this->assertFalse($array['ledger_entry_created']);
        $this->assertFalse($array['financial_transaction_created']);
        $this->assertFalse($array['payout_row_created']);
        $this->assertSame(DrawSettlementSimulationService::MODE, $settled['simulation']->mode);
        $this->assertTrue($settled['simulation']->isSimulation());
    }

    #[Test]
    public function settlement_marks_the_bet_and_advances_the_draw(): void
    {
        $settled = $this->settleOne('3d_direct', '123');
        $drawId = (int) $settled['fixture']['draw']->getKey();

        $bet = DB::table('bets')->where('id', $settled['record']->betId)->first();

        $this->assertSame(BetStatus::Won->value, (string) $bet->status);
        $this->assertStoredMoneySame('9000.00', $bet->actual_payout);
        $this->assertNotNull($bet->won_at);
        // bets.payout_id is the link to a REAL money payout and must stay empty.
        $this->assertNull($bet->payout_id);

        $this->assertSame(
            DrawLifecycleState::Settled,
            $this->lifecycle()->stateOf($drawId),
        );
        $this->assertNotNull(DB::table('draws')->where('id', $drawId)->value('completed_at'));
    }

    #[Test]
    public function a_losing_bet_is_marked_lost_with_a_zero_simulated_prize(): void
    {
        $settled = $this->settleOne('3d_direct', '999');

        $bet = DB::table('bets')->where('id', $settled['record']->betId)->first();

        $this->assertSame(BetStatus::Lost->value, (string) $bet->status);
        $this->assertStoredMoneySame('0.00', $bet->actual_payout);
        $this->assertNull($bet->won_at);
        $this->assertNull($bet->payout_id);
    }

    #[Test]
    public function a_draw_with_no_bets_settles_to_an_empty_but_valid_simulation(): void
    {
        $fixture = $this->fixture();
        $this->publishResult($fixture['draw']);

        $simulation = $this->settlement()->settle((int) $fixture['draw']->getKey());

        $this->assertSame(0, $simulation->selectionsEvaluated);
        $this->assertSame(0, $simulation->selectionsWritten);
        $this->assertSame('0.00', $simulation->totalSimulatedPrize);
        $this->assertSame(
            DrawLifecycleState::Settled,
            $this->lifecycle()->stateOf((int) $fixture['draw']->getKey()),
        );
    }

    #[Test]
    public function settling_a_missing_draw_is_reported_and_not_invented(): void
    {
        $this->expectException(DrawLifecycleException::class);

        try {
            $this->settlement()->settle(987654321);
        } catch (Throwable $exception) {
            $this->assertStringNotContainsString('SELECT', $exception->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());

            throw $exception;
        }
    }
}
