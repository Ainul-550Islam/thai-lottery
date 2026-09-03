<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\DrawLifecycleState;
use App\Enums\DrawStatus;
use App\Enums\MarketResultType;
use App\Exceptions\BetDomainException;
use App\Exceptions\SettlementSimulationException;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\WinningNumber;
use App\ValueObjects\PayoutMultiplier;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase 5.1 follow-up suite.
 *
 * Phase 5.1 shipped green, and this file does not re-test what that suite already
 * proves. It closes the specific gaps that the Phase 5.1 report left open and
 * described honestly as unproven or as limitations:
 *
 *   ITEM A  bet_items.payout_multiplier is an unsigned INTEGER while
 *           App\ValueObjects\PayoutMultiplier carries four decimal places. The
 *           report recorded this as limitation L5 without a test that pins the
 *           behaviour. These tests prove that all six currently configured rates
 *           are stored exactly, and that a fractional rate is REFUSED rather than
 *           silently truncated or rounded, so the limitation can never turn into a
 *           wrong prize.
 *
 *   ITEM E  Neither draw_results nor winning_numbers has a database level
 *           immutability constraint, so the published result of a draw could be
 *           edited after publication. These tests prove the settlement service now
 *           detects a result that no longer agrees with its own published winning
 *           numbers and refuses the run, on both the first-run path and the replay
 *           path, writing nothing and touching no money.
 *
 *   ITEM F  The report showed exact decimal arithmetic in general but did not pin
 *           one exact expected product per market. These tests pin all six.
 *
 *   ITEM G  Leading zeros were covered for 007 only. These tests add 000, 099 and
 *           the two digit 00 and 09 cases.
 *
 *   ITEM C  The DrawStatus change is re-asserted value for value, so a future edit
 *           that renames or drops one of the six original cases fails here.
 *
 * Every test buys through the verified Phase 4.3 purchase pipeline and publishes
 * through the verified Phase 5.1 publication service, exactly as the rest of the
 * settlement suite does. Nothing is hand-inserted except the deliberate tampering
 * in the immutability tests, which is the whole point of those tests.
 */
final class Phase51FollowUpTest extends SettlementTestCase
{
    // -------------------------------------------------------------------------
    // ITEM F - one exact expected product per configured market
    // -------------------------------------------------------------------------

    /**
     * 100.00 x 900 = 90000.00, compared as a decimal string.
     *
     * 100.00 is the largest stake this market accepts, because Phase 3.1 caps the
     * per number payout exposure at 100000.00 and 100.00 x 900 is 90000.00.
     */
    #[Test]
    public function item_f_three_digit_direct_pays_exactly_stake_times_nine_hundred(): void
    {
        $settled = $this->settleOne('3d_direct', '123', stake: '100.00');
        $record = $settled['record'];

        $this->assertSame('900', $this->configuredMultiplier('3d_direct'));
        $this->assertTrue($record->isWinner(), '123 must match the last three of 456123.');
        $this->assertSame('100.00', $record->stake);
        $this->assertSame('90000.00', $record->simulatedPrize());
        $this->assertSame(
            '90000.00',
            $this->expectedPrize('100.00', '3d_direct'),
            'The expected product must come from configuration, not from a literal in the assertion.',
        );
        $this->assertFalse($record->wasRounded, 'An exact product must never be flagged as rounded.');
    }

    /**
     * 100.00 x 45 = 4500.00 on a tod permutation match.
     *
     * 321 is a permutation of the drawn 123. The stake is charged ONCE: the six
     * permutations of 123 do not multiply the stake, and the prize is the single
     * configured tod rate applied to the single stake.
     */
    #[Test]
    public function item_f_three_digit_tod_pays_exactly_stake_times_forty_five_once(): void
    {
        $settled = $this->settleOne('3d_tod', '321', stake: '100.00');
        $record = $settled['record'];

        $this->assertSame('45', $this->configuredMultiplier('3d_tod'));
        $this->assertTrue($record->isWinner(), '321 is a permutation of the drawn 123.');
        $this->assertSame('100.00', $record->stake);
        $this->assertSame('4500.00', $record->simulatedPrize());
        $this->assertSame(1, $record->charges, 'A tod selection is charged exactly once.');
        $this->assertTrue($record->chargedOnce());
        $this->assertTrue($record->payoutIsSinglyCharged());
        $this->assertFalse($record->wasRounded);
    }

    /**
     * 100.00 x 90 = 9000.00 against the last two of the first prize.
     */
    #[Test]
    public function item_f_two_digit_top_pays_exactly_stake_times_ninety(): void
    {
        $settled = $this->settleOne('2d_top', '23', stake: '100.00');
        $record = $settled['record'];

        $this->assertSame('90', $this->configuredMultiplier('2d_top'));
        $this->assertTrue($record->isWinner(), '23 must match the last two of 456123.');
        $this->assertSame('9000.00', $record->simulatedPrize());
        $this->assertFalse($record->wasRounded);
    }

    /**
     * 100.00 x 90 = 9000.00 against the bottom two.
     */
    #[Test]
    public function item_f_two_digit_bottom_pays_exactly_stake_times_ninety(): void
    {
        $settled = $this->settleOne('2d_bottom', self::BOTTOM_TWO, stake: '100.00');
        $record = $settled['record'];

        $this->assertSame('90', $this->configuredMultiplier('2d_bottom'));
        $this->assertTrue($record->isWinner(), '45 is the drawn bottom two.');
        $this->assertSame('9000.00', $record->simulatedPrize());
        $this->assertFalse($record->wasRounded);
    }

    /**
     * 100.00 x 3 = 300.00, using the CONFIGURED run top rate of 3.
     *
     * The legacy App\Enums\BetType::Run->payoutMultiplier() returns 12. If that
     * legacy value were ever reached for, this test would read 1200.00 instead of
     * 300.00, which is why the assertion names both numbers.
     */
    #[Test]
    public function item_f_run_top_pays_exactly_stake_times_three_not_the_legacy_twelve(): void
    {
        $settled = $this->settleOne('run_top', '1', stake: '100.00');
        $record = $settled['record'];

        $this->assertSame('3', $this->configuredMultiplier('run_top'));
        $this->assertTrue($record->isWinner(), 'The digit 1 appears in the drawn last three 123.');
        $this->assertSame(1, $record->occurrences, 'The digit 1 appears exactly once in 123.');
        $this->assertSame('300.00', $record->simulatedPrize());
        $this->assertNotSame('1200.00', $record->simulatedPrize());
        $this->assertTrue(
            $record->legacyMultiplierWasAvoided('12'),
            'Run must never be settled at the legacy BetType multiplier of 12.',
        );
        $this->assertFalse($record->wasRounded);
    }

    /**
     * 100.00 x 4 = 400.00, using the CONFIGURED run bottom rate of 4.
     */
    #[Test]
    public function item_f_run_bottom_pays_exactly_stake_times_four_not_the_legacy_twelve(): void
    {
        $settled = $this->settleOne('run_bottom', '4', stake: '100.00');
        $record = $settled['record'];

        $this->assertSame('4', $this->configuredMultiplier('run_bottom'));
        $this->assertTrue($record->isWinner(), 'The digit 4 appears in the drawn bottom two 45.');
        $this->assertSame(1, $record->occurrences, 'The digit 4 appears exactly once in 45.');
        $this->assertSame('400.00', $record->simulatedPrize());
        $this->assertNotSame('1200.00', $record->simulatedPrize());
        $this->assertTrue($record->legacyMultiplierWasAvoided('12'));
        $this->assertFalse($record->wasRounded);
    }

    // -------------------------------------------------------------------------
    // ITEM A - the unsigned integer multiplier column
    // -------------------------------------------------------------------------

    /**
     * Every currently configured rate is a whole number and survives the column.
     *
     * This is the test that makes limitation L5 a documented boundary rather than a
     * latent defect: if a future configuration edit introduces a fractional rate,
     * this test fails immediately and names the market.
     */
    #[Test]
    public function item_a_every_configured_multiplier_is_storable_in_the_integer_column(): void
    {
        $markets = config('lottery.markets');

        $this->assertIsArray($markets);
        $this->assertNotEmpty($markets);

        foreach (array_keys($markets) as $marketKey) {
            $configured = $this->configuredMultiplier((string) $marketKey);
            $multiplier = PayoutMultiplier::fromConfig(
                $configured,
                'lottery.markets.'.$marketKey.'.payout_multiplier',
            );

            $this->assertTrue(
                $multiplier->isInteger(),
                sprintf('Market %s has a fractional configured rate of %s.', $marketKey, $configured),
            );
            $this->assertTrue(
                $multiplier->fitsBetItemColumn(),
                sprintf('Market %s cannot be stored in bet_items.payout_multiplier.', $marketKey),
            );
            $this->assertSame(
                $configured,
                $multiplier->toBetItemColumn(),
                sprintf('Market %s must round trip through the integer column unchanged.', $marketKey),
            );
        }
    }

    /**
     * A fractional rate is refused, never truncated and never rounded.
     *
     * 2.5000 would become 2 under truncation and 3 under rounding, and both would
     * silently change what a winning selection is worth. The value object refuses
     * instead, which is what makes the integer column safe.
     */
    #[Test]
    public function item_a_a_fractional_multiplier_is_refused_rather_than_truncated(): void
    {
        $fractional = PayoutMultiplier::of('2.5000');

        $this->assertFalse($fractional->isInteger());
        $this->assertFalse($fractional->fitsBetItemColumn());
        $this->assertSame('2.5000', $fractional->value());
        $this->assertSame('2', $fractional->integerPart(), 'The integer part is 2, so truncation would lose 0.5.');

        try {
            $fractional->toBetItemColumn();
            $this->fail('A fractional multiplier must not be storable in the integer column.');
        } catch (BetDomainException $exception) {
            $this->assertStringContainsString('refusing to truncate', $exception->getMessage());
        }

        // Prove the refusal is about the column, not about the rate: the wider
        // DECIMAL(20,4) column keeps the fractional value exactly.
        $this->assertSame('2.5000', $fractional->toDatabase());
    }

    /**
     * A settled selection stores the configured rate exactly, as an integer.
     */
    #[Test]
    public function item_a_a_settled_selection_stores_the_configured_rate_exactly(): void
    {
        $settled = $this->settleOne('3d_direct', '123', stake: '100.00');
        $record = $settled['record'];

        $item = BetItem::query()->findOrFail($record->betItemId);

        // The value object is canonical at four decimal places, so the configured 900
        // is carried as '900.0000' and narrowed to '900' only when it is written to
        // the integer column.
        $this->assertSame('900.0000', $record->multiplierValue());
        $this->assertTrue($record->multiplierFitsBetItemColumn());
        $this->assertSame('900', $record->multiplier->toBetItemColumn());
        $this->assertSame(
            '900',
            (string) $item->payout_multiplier,
            'The stored column must equal the configured rate with nothing lost.',
        );
        $this->assertSame('90000.00', (string) $item->actual_payout);
    }

    // -------------------------------------------------------------------------
    // ITEM E - result immutability, enforced in the application layer
    // -------------------------------------------------------------------------

    /**
     * Editing the stored first prize after publication refuses the settlement.
     *
     * The draw stays in result_published, the selection stays unsettled, and no
     * balance, ledger row, financial transaction or payout row is created.
     */
    #[Test]
    public function item_e_a_first_prize_edited_after_publication_refuses_settlement(): void
    {
        $fixture = $this->fixture();
        $purchase = $this->purchase($fixture, '3d_direct', '123', '100.00');
        $this->publishResult($fixture['draw']);

        $drawId = (int) $fixture['draw']->getKey();
        $financeBefore = $this->financeSnapshot();

        $result = DrawResult::query()->where('draw_id', $drawId)->firstOrFail();
        $result->first_prize = '456999';
        $result->save();

        try {
            $this->settlement()->settle($drawId);
            $this->fail('Settlement must refuse a result that no longer matches its winning numbers.');
        } catch (SettlementSimulationException $exception) {
            $this->assertSame(SettlementSimulationException::CODE_RESULT_TAMPERED, $exception->errorCode());
            $this->assertStringContainsString('not self consistent', $exception->getMessage());
        }

        $this->assertSame(
            DrawStatus::ResultPublished->value,
            (string) Draw::query()->findOrFail($drawId)->status->value,
            'A refused run must leave the draw in result_published.',
        );

        $item = BetItem::query()->findOrFail($purchase->betItemId());
        $this->assertFalse((bool) $item->is_winner, 'A refused run must not settle any selection.');
        $this->assertSame('0.00', (string) $item->actual_payout);

        $this->assertFinanceUnchanged($financeBefore, 'a refused settlement must touch no money');
    }

    /**
     * Editing a published winning number refuses the settlement.
     */
    #[Test]
    public function item_e_an_edited_winning_number_row_refuses_settlement(): void
    {
        $fixture = $this->fixture();
        $this->purchase($fixture, '3d_direct', '123', '100.00');
        $this->publishResult($fixture['draw']);

        $drawId = (int) $fixture['draw']->getKey();
        $financeBefore = $this->financeSnapshot();

        $row = WinningNumber::query()
            ->where('draw_id', $drawId)
            ->where('prize_tier', MarketResultType::ThreeDigitTop->value)
            ->firstOrFail();

        $row->number = '999';
        $row->save();

        try {
            $this->settlement()->settle($drawId);
            $this->fail('Settlement must refuse an edited winning number row.');
        } catch (SettlementSimulationException $exception) {
            $this->assertSame(SettlementSimulationException::CODE_RESULT_TAMPERED, $exception->errorCode());
            $this->assertStringContainsString('999', $exception->getMessage());
            $this->assertStringContainsString('123', $exception->getMessage());
        }

        $this->assertSame(
            DrawStatus::ResultPublished->value,
            (string) Draw::query()->findOrFail($drawId)->status->value,
        );
        $this->assertFinanceUnchanged($financeBefore, 'a refused settlement must touch no money');
    }

    /**
     * Removing the winning numbers of one prize tier refuses the settlement.
     *
     * App\Models\WinningNumber is soft deletable, so a delete leaves the row in
     * place but invisible. A market of that tier would then have no official number
     * to be settled against, so the run is refused instead of settling that market
     * as a loss.
     */
    #[Test]
    public function item_e_a_missing_prize_tier_refuses_settlement(): void
    {
        $fixture = $this->fixture();
        $this->purchase($fixture, '3d_direct', '123', '100.00');
        $this->publishResult($fixture['draw']);

        $drawId = (int) $fixture['draw']->getKey();
        $financeBefore = $this->financeSnapshot();

        $removed = WinningNumber::query()
            ->where('draw_id', $drawId)
            ->where('prize_tier', MarketResultType::TwoDigitBottom->value)
            ->delete();

        $this->assertGreaterThan(0, $removed, 'The fixture must publish a bottom two winning number.');

        try {
            $this->settlement()->settle($drawId);
            $this->fail('Settlement must refuse a result with a missing prize tier.');
        } catch (SettlementSimulationException $exception) {
            $this->assertSame(SettlementSimulationException::CODE_RESULT_TAMPERED, $exception->errorCode());
            $this->assertStringContainsString(MarketResultType::TwoDigitBottom->value, $exception->getMessage());
        }

        $this->assertSame(
            DrawStatus::ResultPublished->value,
            (string) Draw::query()->findOrFail($drawId)->status->value,
        );
        $this->assertFinanceUnchanged($financeBefore, 'a refused settlement must touch no money');
    }

    /**
     * A result edited AFTER a successful settlement refuses the replay.
     *
     * This is the case that matters most. Once a draw is Settled, a second call
     * returns the stored outcome instead of settling again. If the stored result
     * had been edited in the meantime, that replay would be describing a result the
     * bet_items rows were never settled from. The replay refuses instead, so a
     * tampered settled draw can never be read back as if it were authentic.
     */
    #[Test]
    public function item_e_a_result_edited_after_settlement_refuses_the_replay(): void
    {
        $settled = $this->settleOne('3d_direct', '123', stake: '100.00');
        $drawId = $settled['simulation']->drawId;

        $this->assertSame('90000.00', $settled['record']->simulatedPrize());

        $financeBefore = $this->financeSnapshot();

        $result = DrawResult::query()->where('draw_id', $drawId)->firstOrFail();
        $result->first_prize = '456999';
        $result->save();

        try {
            $this->settlement()->settle($drawId);
            $this->fail('The replay path must refuse a result that was edited after settlement.');
        } catch (SettlementSimulationException $exception) {
            $this->assertSame(SettlementSimulationException::CODE_RESULT_TAMPERED, $exception->errorCode());
        }

        // The already settled data is untouched: the refusal is a read time guard,
        // not a mutation.
        $item = BetItem::query()->findOrFail($settled['record']->betItemId);
        $this->assertTrue((bool) $item->is_winner);
        $this->assertSame('90000.00', (string) $item->actual_payout);
        $this->assertSame(
            DrawStatus::Completed->value,
            (string) Draw::query()->findOrFail($drawId)->status->value,
            'A settled draw stays settled; the refusal changes no state.',
        );

        $this->assertFinanceUnchanged($financeBefore, 'a refused replay must touch no money');
    }

    /**
     * An untampered published result settles and replays normally.
     *
     * The negative control for the three tests above. Without it, a guard that
     * refused every run would look like a passing suite.
     */
    #[Test]
    public function item_e_an_untampered_result_settles_and_replays_normally(): void
    {
        $settled = $this->settleOne('3d_direct', '123', stake: '100.00');
        $drawId = $settled['simulation']->drawId;

        $replay = $this->settlement()->settle($drawId);

        $this->assertTrue($replay->alreadySettled, 'The second run must be the idempotent no-op path.');
        $this->assertSame(
            $settled['simulation']->totalSimulatedPrize,
            $replay->totalSimulatedPrize,
            'A replay must report the same simulated total as the run that settled the draw.',
        );
        $this->assertSame($settled['simulation']->firstPrize, $replay->firstPrize);
        $this->assertSame($settled['simulation']->bottomTwo, $replay->bottomTwo);
    }

    /**
     * The published winning numbers agree with the stored result by construction.
     *
     * Proves the guard's premise directly: immediately after publication, every
     * winning_numbers row equals the value derived from draw_results for its tier.
     */
    #[Test]
    public function item_e_publication_leaves_the_result_and_the_winning_numbers_in_agreement(): void
    {
        $fixture = $this->fixture();
        $published = $this->publishResult($fixture['draw'], '456007', '05');
        $data = $published['data'];

        $rows = WinningNumber::query()
            ->where('draw_id', (int) $fixture['draw']->getKey())
            ->get();

        $this->assertGreaterThanOrEqual(3, $rows->count());

        $tiers = [];

        foreach ($rows as $row) {
            $resultType = MarketResultType::from((string) $row->prize_tier);
            $tiers[$resultType->value] = true;

            $this->assertSame(
                $data->valueFor($resultType),
                (string) $row->number,
                sprintf('Winning number %d disagrees with the stored result.', (int) $row->getKey()),
            );
        }

        foreach (MarketResultType::cases() as $resultType) {
            $this->assertArrayHasKey(
                $resultType->value,
                $tiers,
                'Publication must write a winning number for every market result type.',
            );
        }

        $this->assertSame('007', $data->valueFor(MarketResultType::ThreeDigitTop));
        $this->assertSame('07', $data->valueFor(MarketResultType::TwoDigitTop));
        $this->assertSame('05', $data->valueFor(MarketResultType::TwoDigitBottom));
    }

    // -------------------------------------------------------------------------
    // ITEM G - leading zeros
    // -------------------------------------------------------------------------

    /**
     * 007 stays 007 and wins against a first prize ending 007.
     */
    #[Test]
    public function item_g_a_three_digit_selection_of_007_keeps_its_leading_zeros(): void
    {
        $settled = $this->settleOne('3d_direct', '007', '456007', '05', '100.00');
        $record = $settled['record'];

        $this->assertSame('007', $record->selection, '007 must never be reduced to 7.');
        $this->assertSame('007', $record->winningValue);
        $this->assertTrue($record->isWinner());
        $this->assertSame('90000.00', $record->simulatedPrize());

        $item = BetItem::query()->findOrFail($record->betItemId);
        $this->assertSame('007', (string) $item->number);
    }

    /**
     * 000 stays 000 and wins against a first prize ending 000.
     *
     * The hardest leading zero case: every digit is a zero, so any numeric handling
     * anywhere in the path would collapse it to the empty string or to 0.
     */
    #[Test]
    public function item_g_a_three_digit_selection_of_000_keeps_its_leading_zeros(): void
    {
        $settled = $this->settleOne('3d_direct', '000', '456000', '00', '100.00');
        $record = $settled['record'];

        $this->assertSame('000', $record->selection, '000 must never become 0 or an empty string.');
        $this->assertSame('000', $record->winningValue);
        $this->assertNotSame('0', $record->selection);
        $this->assertSame(3, strlen($record->selection));
        $this->assertTrue($record->isWinner());
        $this->assertSame('90000.00', $record->simulatedPrize());

        $item = BetItem::query()->findOrFail($record->betItemId);
        $this->assertSame('000', (string) $item->number);
    }

    /**
     * 099 stays 099 and wins as a tod permutation of the drawn 990.
     */
    #[Test]
    public function item_g_a_tod_selection_of_099_keeps_its_leading_zero(): void
    {
        $settled = $this->settleOne('3d_tod', '099', '456990', '05', '100.00');
        $record = $settled['record'];

        $this->assertSame('099', $record->selection, '099 must never be reduced to 99.');
        $this->assertSame('990', $record->winningValue);
        $this->assertTrue($record->isWinner(), '099 is a permutation of the drawn 990.');
        $this->assertSame(1, $record->charges, 'The three permutations of 099 do not multiply the stake.');
        $this->assertSame('4500.00', $record->simulatedPrize());

        $item = BetItem::query()->findOrFail($record->betItemId);
        $this->assertSame('099', (string) $item->number);
    }

    /**
     * A two digit 09 stays 09 on the top market.
     */
    #[Test]
    public function item_g_a_two_digit_top_selection_of_09_keeps_its_leading_zero(): void
    {
        $settled = $this->settleOne('2d_top', '09', '456009', '05', '100.00');
        $record = $settled['record'];

        $this->assertSame('09', $record->selection, '09 must never be reduced to 9.');
        $this->assertSame('09', $record->winningValue);
        $this->assertTrue($record->isWinner());
        $this->assertSame('9000.00', $record->simulatedPrize());

        $item = BetItem::query()->findOrFail($record->betItemId);
        $this->assertSame('09', (string) $item->number);
    }

    /**
     * A two digit 00 stays 00 on the bottom market.
     */
    #[Test]
    public function item_g_a_two_digit_bottom_selection_of_00_keeps_its_leading_zeros(): void
    {
        $settled = $this->settleOne('2d_bottom', '00', '456123', '00', '100.00');
        $record = $settled['record'];

        $this->assertSame('00', $record->selection, '00 must never become 0 or an empty string.');
        $this->assertSame('00', $record->winningValue);
        $this->assertSame(2, strlen($record->selection));
        $this->assertTrue($record->isWinner());
        $this->assertSame('9000.00', $record->simulatedPrize());

        $item = BetItem::query()->findOrFail($record->betItemId);
        $this->assertSame('00', (string) $item->number);
    }

    /**
     * A zero digit run selection keeps its value and counts its occurrences.
     *
     * The drawn last three of 456000 is 000, so the digit 0 occurs three times. The
     * occurrence count is RECORDED but the prize is still one stake at one rate:
     * 100.00 x 3 = 300.00, not 900.00. One original selection remains one simulated
     * ticket item, and a repeated digit does not multiply what that item is worth,
     * exactly as a repeated tod permutation does not multiply the stake.
     */
    #[Test]
    public function item_g_a_run_selection_of_zero_records_occurrences_without_multiplying_the_prize(): void
    {
        $settled = $this->settleOne('run_top', '0', '456000', '11', '100.00');
        $record = $settled['record'];

        $this->assertSame('0', $record->selection, '0 must survive as a digit, not become an empty string.');
        $this->assertSame('000', $record->winningValue);
        $this->assertTrue($record->isWinner());
        $this->assertSame(3, $record->occurrences, 'The digit 0 occurs three times in 000.');
        $this->assertSame(1, $record->charges, 'Three occurrences are still one charge.');
        $this->assertTrue($record->chargedOnce());
        $this->assertSame(
            '300.00',
            $record->simulatedPrize(),
            'The prize is stake x configured rate. Occurrences are reported, not multiplied in.',
        );
        $this->assertNotSame('900.00', $record->simulatedPrize());
        $this->assertSame('300.00', $this->expectedPrize('100.00', 'run_top'));
        $this->assertFalse($record->wasRounded);
    }

    // -------------------------------------------------------------------------
    // ITEM C - the DrawStatus change is still value for value compatible
    // -------------------------------------------------------------------------

    /**
     * The six original DrawStatus values are unchanged and ResultPublished is added.
     *
     * draws.status is varchar(32), so no migration was needed. This test pins the
     * stored strings so that a rename would fail here rather than silently orphan
     * every existing row.
     */
    #[Test]
    public function item_c_draw_status_keeps_its_original_values_and_adds_result_published(): void
    {
        $values = array_map(
            static fn (DrawStatus $status): string => $status->value,
            DrawStatus::cases(),
        );

        foreach (['scheduled', 'open', 'closed', 'drawing', 'completed', 'cancelled'] as $original) {
            $this->assertContains($original, $values, sprintf('DrawStatus lost its %s value.', $original));
        }

        $this->assertContains('result_published', $values);
        $this->assertSame('result_published', DrawStatus::ResultPublished->value);
        $this->assertCount(7, $values, 'Exactly one case was appended in Phase 5.1.');
        $this->assertSame($values, array_values(array_unique($values)));

        // The lifecycle state and the stored status agree on the spelling, which is
        // what lets a varchar column carry both vocabularies.
        $this->assertSame(
            DrawStatus::ResultPublished->value,
            DrawLifecycleState::ResultPublished->value,
        );
    }
}
