<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\DrawLifecycleState;
use App\Enums\DrawStatus;
use App\Enums\MarketResultType;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\DrawResultValidationException;
use App\Models\DrawResult;
use App\Models\WinningNumber;
use App\Services\Draw\DrawResultValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 5.1 requirements B and C, and requirement I points 14 to 17.
 *
 * Result validation and publication: leading zeroes, digit widths, malformed input,
 * duplicate publication, and the fact that the existing schema is used as it stands.
 */
final class DrawResultPublicationTest extends SettlementTestCase
{
    // -------------------------------------------------------------------------
    // Point 14: leading zeroes survive exactly
    // -------------------------------------------------------------------------

    #[Test]
    public function point_14_preserves_the_leading_zeroes_of_the_first_prize(): void
    {
        $published = $this->publishResult($this->fixture()['draw'], '000007', '05');

        $data = $published['data'];

        $this->assertSame('000007', $data->firstPrize());
        $this->assertSame('007', $data->lastThree(), 'The 3D result must be 007, never 7.');
        $this->assertSame('07', $data->lastTwo(), 'The 2D top result must be 07, never 7.');
        $this->assertSame('05', $data->bottomTwo());

        // Persisted exactly, as a string, with the zeroes intact.
        $this->assertSame(
            '000007',
            (string) DB::table('draw_results')->where('draw_id', $published['draw']->getKey())
                ->value('first_prize'),
        );

        $this->assertTrue($data->firstPrizeHasLeadingZero());
        $this->assertTrue($data->bottomTwoHasLeadingZero());
    }

    #[Test]
    public function point_14b_preserves_leading_zeroes_in_every_derived_winning_number_row(): void
    {
        $published = $this->publishResult($this->fixture()['draw'], '000007', '05');

        $numbers = WinningNumber::query()
            ->where('draw_id', $published['draw']->getKey())
            ->pluck('number')
            ->all();

        // Stored as strings of the market's own width: 007 for the three digit markets,
        // 07 and 05 for the two digit markets, 7 and 5 for the single digit Run markets.
        $this->assertContains('007', $numbers);
        $this->assertContains('07', $numbers);
        $this->assertContains('05', $numbers);

        foreach ($numbers as $number) {
            $this->assertIsString($number);
            $this->assertMatchesRegularExpression('/^[0-9]+$/', $number);
        }
    }

    #[Test]
    public function point_14c_a_bottom_two_of_double_zero_is_a_valid_result(): void
    {
        $published = $this->publishResult($this->fixture()['draw'], '100000', '00');

        $key = app(DrawResultValidator::class)->bottomTwoMetadataKey();

        $this->assertSame('00', $published['data']->bottomTwo());
        $this->assertSame('00', $published['result']->metadata[$key] ?? null);
        $this->assertSame('000', $published['data']->lastThree());
        $this->assertSame('00', $published['data']->lastTwo());
    }

    // -------------------------------------------------------------------------
    // Point 15: the wrong digit length is refused
    // -------------------------------------------------------------------------

    #[Test]
    public function point_15_refuses_a_first_prize_of_the_wrong_length(): void
    {
        $validator = app(DrawResultValidator::class);
        $expected = $validator->firstPrizeDigits();

        foreach (['1', '12', '123', '12345', '1234567', '00000000'] as $candidate) {
            if (strlen($candidate) === $expected) {
                continue;
            }

            try {
                $validator->validateFirstPrize($candidate);
                $this->fail('A first prize of '.strlen($candidate).' digits must be refused.');
            } catch (DrawResultValidationException $exception) {
                $this->assertSame('RESULT_FIRST_PRIZE_LENGTH', $exception->errorCode());
                $this->assertSame('first_prize', $exception->field());
            }
        }
    }

    #[Test]
    public function point_15b_refuses_a_bottom_two_that_is_not_exactly_two_digits(): void
    {
        $validator = app(DrawResultValidator::class);

        foreach (['1', '123', '0000'] as $candidate) {
            try {
                $validator->validateBottomTwo($candidate);
                $this->fail('A bottom two of '.strlen($candidate).' digits must be refused.');
            } catch (DrawResultValidationException $exception) {
                $this->assertSame('RESULT_BOTTOM_TWO_LENGTH', $exception->errorCode());
            }
        }
    }

    #[Test]
    public function point_15c_never_pads_a_short_result_into_a_valid_one(): void
    {
        $draw = $this->advanceToResultPending($this->fixture()['draw']);

        try {
            $this->publication()->publish((int) $draw->getKey(), [
                'first_prize' => '7',
                'bottom_two' => '5',
            ]);
            $this->fail('A one digit first prize must be refused rather than padded to 000007.');
        } catch (DrawResultValidationException $exception) {
            $this->assertSame('RESULT_FIRST_PRIZE_LENGTH', $exception->errorCode());
        }

        $this->assertSame(
            0,
            DrawResult::query()->where('draw_id', $draw->getKey())->count(),
            'A refused result must leave no row behind.',
        );
        $this->assertSame(
            DrawStatus::Drawing->value,
            (string) DB::table('draws')->where('id', $draw->getKey())->value('status'),
            'A refused result must not advance the draw.',
        );
    }

    // -------------------------------------------------------------------------
    // Point 16: malformed results are refused
    // -------------------------------------------------------------------------

    #[Test]
    public function point_16_refuses_a_first_prize_that_is_not_a_digit_string(): void
    {
        $validator = app(DrawResultValidator::class);

        foreach (['abcdef', '12 456', '12-456', '+12345', '12.345', '１２３４５６'] as $candidate) {
            try {
                $validator->validateFirstPrize($candidate);
                $this->fail('"'.$candidate.'" must be refused as a first prize.');
            } catch (DrawResultValidationException $exception) {
                $this->assertContains(
                    $exception->errorCode(),
                    ['RESULT_FIRST_PRIZE_NOT_DIGITS', 'RESULT_FIRST_PRIZE_LENGTH'],
                );
            }
        }
    }

    #[Test]
    public function point_16b_refuses_a_missing_result(): void
    {
        $validator = app(DrawResultValidator::class);

        try {
            $validator->validate(['bottom_two' => '45']);
            $this->fail('A result with no first prize must be refused.');
        } catch (DrawResultValidationException $exception) {
            $this->assertSame('RESULT_FIRST_PRIZE_REQUIRED', $exception->errorCode());
        }

        try {
            $validator->validate(['first_prize' => '456123']);
            $this->fail('A result with no bottom two must be refused.');
        } catch (DrawResultValidationException $exception) {
            $this->assertSame('RESULT_BOTTOM_TWO_REQUIRED', $exception->errorCode());
        }
    }

    #[Test]
    public function point_16c_refuses_a_numeric_result_that_is_not_a_string(): void
    {
        $validator = app(DrawResultValidator::class);

        // 7 as an integer is not 000007. Accepting it would mean deciding for the
        // operator which zeroes they meant, so it is refused rather than cast.
        foreach ([7, 456123, 4.56123, true, null, [], 0] as $candidate) {
            $this->assertFalse(
                $validator->firstPrizeIsValid($candidate),
                'A non-string first prize must never be accepted.',
            );
        }

        $this->expectException(DrawResultValidationException::class);

        $validator->validateFirstPrize(456123);
    }

    #[Test]
    public function point_16d_refuses_an_operator_supplied_winner_or_payout_field(): void
    {
        $validator = app(DrawResultValidator::class);
        $draw = $this->advanceToResultPending($this->fixture()['draw']);

        foreach (DrawResultValidator::REFUSED_FIELDS as $field) {
            try {
                $validator->validate([
                    'first_prize' => '456123',
                    'bottom_two' => '45',
                    $field => 'anything',
                ]);
                $this->fail('The field '.$field.' must be refused, not ignored.');
            } catch (DrawResultValidationException $exception) {
                $this->assertSame('RESULT_UNEXPECTED_FIELD', $exception->errorCode());
                $this->assertSame($field, $exception->contextValue('field'));
            }
        }

        // And the refusal reaches the publication entry point too.
        try {
            $this->publication()->publish((int) $draw->getKey(), [
                'first_prize' => '456123',
                'bottom_two' => '45',
                'payout_multiplier' => '99999',
            ]);
            $this->fail('A client supplied multiplier must be refused at publication.');
        } catch (DrawResultValidationException $exception) {
            $this->assertSame('RESULT_UNEXPECTED_FIELD', $exception->errorCode());
        }

        $this->assertSame(0, DrawResult::query()->where('draw_id', $draw->getKey())->count());
    }

    // -------------------------------------------------------------------------
    // Point 17: duplicate publication is refused
    // -------------------------------------------------------------------------

    #[Test]
    public function point_17_refuses_a_second_publication_of_the_same_draw(): void
    {
        $published = $this->publishResult($this->fixture()['draw']);
        $drawId = (int) $published['draw']->getKey();

        try {
            $this->publication()->publish($drawId, [
                'first_prize' => '999999',
                'bottom_two' => '99',
            ]);
            $this->fail('A draw whose result is published must refuse a second publication.');
        } catch (DrawLifecycleException $exception) {
            $this->assertContains(
                $exception->errorCode(),
                ['DRAW_NOT_PUBLISHABLE', 'DRAW_DUPLICATE_PUBLICATION'],
            );
        }

        // The original numbers are untouched.
        $this->assertSame(
            self::FIRST_PRIZE,
            (string) DB::table('draw_results')->where('draw_id', $drawId)->value('first_prize'),
        );
        $this->assertSame(1, DrawResult::query()->where('draw_id', $drawId)->count());
    }

    #[Test]
    public function point_17b_refuses_publication_when_a_result_row_already_exists(): void
    {
        $published = $this->publishResult($this->fixture()['draw']);
        $draw = $published['draw'];
        $drawId = (int) $draw->getKey();

        // Force the lifecycle backwards to simulate the worst case: the state says
        // publishable but a result row is already stored. The row, not the state, must
        // be what stops a second publication.
        $draw->status = DrawStatus::Drawing;
        $draw->save();

        try {
            $this->publication()->publish($drawId, [
                'first_prize' => '999999',
                'bottom_two' => '99',
            ]);
            $this->fail('An existing result row must block publication regardless of the state.');
        } catch (DrawLifecycleException $exception) {
            $this->assertSame('DRAW_DUPLICATE_PUBLICATION', $exception->errorCode());
        }

        $this->assertSame(1, DrawResult::query()->where('draw_id', $drawId)->count());
        $this->assertSame(
            self::FIRST_PRIZE,
            (string) DB::table('draw_results')->where('draw_id', $drawId)->value('first_prize'),
        );
        $this->assertSame(
            6,
            WinningNumber::query()->where('draw_id', $drawId)->count(),
            'The refused second publication must not add winning number rows.',
        );
    }

    #[Test]
    public function point_17c_refuses_publication_from_a_state_that_is_not_result_pending(): void
    {
        foreach ([
            DrawLifecycleState::Draft,
            DrawLifecycleState::Open,
            DrawLifecycleState::Closed,
            DrawLifecycleState::Settled,
            DrawLifecycleState::Cancelled,
        ] as $state) {
            $draw = $this->drawInState($state);

            try {
                $this->publication()->publish((int) $draw->getKey(), [
                    'first_prize' => '456123',
                    'bottom_two' => '45',
                ]);
                $this->fail('A '.$state->value.' draw must not accept a published result.');
            } catch (DrawLifecycleException $exception) {
                // A Settled draw is refused one step earlier, as a duplicate: settled
                // implies a result is already public, so the duplicate guard fires
                // before the state guard. Both are refusals with no row written.
                $expected = $state === DrawLifecycleState::Settled
                    ? 'DRAW_DUPLICATE_PUBLICATION'
                    : 'DRAW_NOT_PUBLISHABLE';

                $this->assertSame(
                    $expected,
                    $exception->errorCode(),
                    'Publication from '.$state->value.' must be refused.',
                );
            }

            $this->assertSame(0, DrawResult::query()->where('draw_id', $draw->getKey())->count());
        }
    }

    // -------------------------------------------------------------------------
    // Requirement C: the existing schema, used as it stands
    // -------------------------------------------------------------------------

    #[Test]
    public function the_bottom_two_lives_in_metadata_because_there_is_no_column_for_it(): void
    {
        $this->assertFalse(
            Schema::hasColumn('draw_results', 'bottom_two'),
            'Phase 5.1 must not add a bottom_two column.',
        );
        $this->assertFalse(
            Schema::hasColumn('draws', 'winning_numbers'),
            'Phase 5.1 must not add a duplicate winning_numbers JSON column to draws.',
        );

        $published = $this->publishResult($this->fixture()['draw'], '456123', '45');
        $key = app(DrawResultValidator::class)->bottomTwoMetadataKey();

        $metadata = $published['result']->metadata;

        $this->assertIsArray($metadata);
        $this->assertSame('45', $metadata[$key] ?? null);
    }

    #[Test]
    public function publication_writes_exactly_one_winning_number_row_per_configured_market(): void
    {
        $published = $this->publishResult($this->fixture()['draw'], '456123', '45');
        $drawId = (int) $published['draw']->getKey();

        $rows = WinningNumber::query()->where('draw_id', $drawId)->get();

        $this->assertCount(6, $rows, 'Six markets are sold, so six rows are written.');

        // Every row is unique on the key the schema actually enforces.
        $keys = $rows->map(static fn (WinningNumber $row): string => implode('|', [
            (string) $row->getRawOriginal('bet_type'),
            (string) $row->number,
            (string) $row->prize_tier,
        ]))->all();

        $this->assertSame(count($keys), count(array_unique($keys)));

        // The prize tier of each row is a declared MarketResultType, not a free string.
        foreach ($rows as $row) {
            $this->assertInstanceOf(
                MarketResultType::class,
                MarketResultType::tryFrom((string) $row->prize_tier),
            );
            $this->assertNotNull($row->published_at);
        }
    }

    #[Test]
    public function the_derived_values_match_the_verified_market_result_sources(): void
    {
        $data = $this->publishResult($this->fixture()['draw'], '456123', '45')['data'];

        $this->assertSame('123', $data->valueFor(MarketResultType::ThreeDigitTop));
        $this->assertSame('23', $data->valueFor(MarketResultType::TwoDigitTop));
        $this->assertSame('45', $data->valueFor(MarketResultType::TwoDigitBottom));
    }

    #[Test]
    public function publication_stamps_the_existing_columns_and_advances_the_state(): void
    {
        $published = $this->publishResult($this->fixture()['draw']);
        $draw = $published['draw']->fresh();

        $this->assertNotNull($published['result']->published_at);
        $this->assertTrue($published['result']->isPublished());
        $this->assertNotNull($draw->result_published_at);
        $this->assertSame(
            DrawLifecycleState::ResultPublished,
            $this->lifecycle()->currentState($draw),
        );
    }
}
