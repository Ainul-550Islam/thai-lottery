<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\DrawLifecycleState;
use App\Enums\DrawStatus;
use App\Exceptions\DrawLifecycleException;
use App\Models\Draw;
use App\Services\Draw\DrawLifecycleService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 5.1 requirement A and requirement I points 1, 2 and 3.
 *
 * The draw lifecycle: which transitions exist, which are refused, and what a closed or
 * published draw will no longer allow.
 */
final class DrawLifecycleTest extends SettlementTestCase
{
    // -------------------------------------------------------------------------
    // Point 1: the valid lifecycle
    // -------------------------------------------------------------------------

    #[Test]
    public function point_01_walks_the_whole_valid_lifecycle(): void
    {
        $lifecycle = $this->lifecycle();
        $draw = $this->drawInState(DrawLifecycleState::Draft);

        $this->assertSame(DrawLifecycleState::Draft, $lifecycle->currentState($draw));

        $draw = $lifecycle->open($draw);
        $this->assertSame(DrawLifecycleState::Open, $lifecycle->currentState($draw));
        $this->assertNotNull($draw->opened_at, 'Open must stamp opened_at.');

        $draw = $lifecycle->close($draw);
        $this->assertSame(DrawLifecycleState::Closed, $lifecycle->currentState($draw));
        $this->assertNotNull($draw->closed_at, 'Closed must stamp closed_at.');

        $draw = $lifecycle->markResultPending($draw);
        $this->assertSame(DrawLifecycleState::ResultPending, $lifecycle->currentState($draw));
        $this->assertNotNull($draw->drawn_at, 'ResultPending must stamp drawn_at.');

        $draw = $lifecycle->markResultPublished($draw);
        $this->assertSame(DrawLifecycleState::ResultPublished, $lifecycle->currentState($draw));
        $this->assertNotNull(
            $draw->result_published_at,
            'ResultPublished must stamp the existing result_published_at column.',
        );

        $draw = $lifecycle->markSettled($draw);
        $this->assertSame(DrawLifecycleState::Settled, $lifecycle->currentState($draw));
        $this->assertNotNull($draw->completed_at, 'Settled must stamp completed_at.');

        // The states are persisted, not just held on the model.
        $this->assertSame(
            DrawStatus::Completed->value,
            (string) DB::table('draws')->where('id', $draw->getKey())->value('status'),
            'Settled persists as the existing DrawStatus::Completed value.',
        );
    }

    #[Test]
    public function point_01b_every_state_is_reachable_and_cancellation_is_available_until_publication(): void
    {
        $lifecycle = $this->lifecycle();

        foreach ([
            DrawLifecycleState::Draft,
            DrawLifecycleState::Open,
            DrawLifecycleState::Closed,
            DrawLifecycleState::ResultPending,
        ] as $state) {
            $draw = $this->drawInState($state);

            $this->assertTrue(
                $lifecycle->canTransition($draw, DrawLifecycleState::Cancelled),
                $state->value.' must still be cancellable.',
            );

            $cancelled = $lifecycle->cancel($draw, ['reason' => 'test']);

            $this->assertSame(DrawLifecycleState::Cancelled, $lifecycle->currentState($cancelled));
        }
    }

    #[Test]
    public function point_01c_the_seven_declared_states_all_exist(): void
    {
        $this->assertSame(
            ['draft', 'open', 'closed', 'result_pending', 'result_published', 'settled', 'cancelled'],
            DrawLifecycleState::values(),
        );
    }

    // -------------------------------------------------------------------------
    // Point 2: invalid transitions are refused
    // -------------------------------------------------------------------------

    #[Test]
    public function point_02_refuses_an_invalid_transition(): void
    {
        $lifecycle = $this->lifecycle();
        $draw = $this->drawInState(DrawLifecycleState::Draft);

        try {
            // Draft cannot jump straight to Settled.
            $lifecycle->transitionTo($draw, DrawLifecycleState::Settled);
            $this->fail('A Draft draw must not transition directly to Settled.');
        } catch (DrawLifecycleException $exception) {
            $this->assertSame('DRAW_INVALID_TRANSITION', $exception->errorCode());
            $this->assertSame('draft', $exception->contextValue('from'));
            $this->assertSame('settled', $exception->contextValue('to'));
        }

        $this->assertSame(
            DrawStatus::Scheduled->value,
            (string) DB::table('draws')->where('id', $draw->getKey())->value('status'),
            'A refused transition must not change the stored status.',
        );
    }

    #[Test]
    public function point_02b_refuses_every_transition_out_of_a_terminal_state(): void
    {
        $lifecycle = $this->lifecycle();

        foreach ([DrawLifecycleState::Settled, DrawLifecycleState::Cancelled] as $terminal) {
            $this->assertTrue($terminal->isTerminal(), $terminal->value.' must be terminal.');
            $this->assertSame([], $terminal->allowedTransitions());

            foreach (DrawLifecycleState::cases() as $target) {
                $draw = $this->drawInState($terminal);

                try {
                    $lifecycle->transitionTo($draw, $target);
                    $this->fail(sprintf(
                        'A %s draw must not transition to %s.',
                        $terminal->value,
                        $target->value,
                    ));
                } catch (DrawLifecycleException $exception) {
                    $this->assertContains(
                        $exception->errorCode(),
                        ['DRAW_TERMINAL_STATE', 'DRAW_INVALID_TRANSITION'],
                        'A terminal state refuses with a terminal or invalid-transition code.',
                    );
                }
            }
        }
    }

    #[Test]
    public function point_02c_a_published_draw_can_no_longer_be_cancelled(): void
    {
        $lifecycle = $this->lifecycle();
        $draw = $this->drawInState(DrawLifecycleState::ResultPublished);

        $this->assertFalse(
            $lifecycle->canTransition($draw, DrawLifecycleState::Cancelled),
            'Cancelling a draw whose result is public would retract a published result.',
        );

        $this->expectException(DrawLifecycleException::class);

        $lifecycle->cancel($draw);
    }

    #[Test]
    public function point_02d_the_transition_table_is_the_single_source_of_truth(): void
    {
        $expected = [
            'draft' => ['open', 'cancelled'],
            'open' => ['closed', 'cancelled'],
            'closed' => ['result_pending', 'cancelled'],
            'result_pending' => ['result_published', 'cancelled'],
            'result_published' => ['settled'],
            'settled' => [],
            'cancelled' => [],
        ];

        foreach (DrawLifecycleState::cases() as $state) {
            $this->assertSame(
                $expected[$state->value],
                array_map(
                    static fn (DrawLifecycleState $target): string => $target->value,
                    $state->allowedTransitions(),
                ),
                'The allowed transitions of '.$state->value.' must match the declared table.',
            );
        }
    }

    // -------------------------------------------------------------------------
    // Point 3: a closed or published draw cannot be modified
    // -------------------------------------------------------------------------

    #[Test]
    public function point_03_refuses_to_modify_a_closed_draw(): void
    {
        $lifecycle = $this->lifecycle();
        $draw = $this->drawInState(DrawLifecycleState::Closed);
        $original = (string) $draw->draw_number;

        $this->assertFalse($lifecycle->isMutable($draw));

        try {
            $lifecycle->applyModification($draw, ['draw_number' => 'CHANGED-0001']);
            $this->fail('A closed draw must not be modifiable.');
        } catch (DrawLifecycleException $exception) {
            $this->assertSame('DRAW_IMMUTABLE', $exception->errorCode());
        }

        $this->assertSame(
            $original,
            (string) DB::table('draws')->where('id', $draw->getKey())->value('draw_number'),
            'The refused modification must not reach the database.',
        );
    }

    #[Test]
    public function point_03b_refuses_to_modify_a_published_or_settled_draw(): void
    {
        $lifecycle = $this->lifecycle();

        foreach ([
            DrawLifecycleState::ResultPending,
            DrawLifecycleState::ResultPublished,
            DrawLifecycleState::Settled,
            DrawLifecycleState::Cancelled,
        ] as $state) {
            $draw = $this->drawInState($state);

            $this->assertFalse(
                $lifecycle->isMutable($draw),
                $state->value.' must not be modifiable.',
            );

            try {
                $lifecycle->applyModification($draw, ['scheduled_at' => now()->addDays(2)]);
                $this->fail('A '.$state->value.' draw must not be modifiable.');
            } catch (DrawLifecycleException $exception) {
                $this->assertSame('DRAW_IMMUTABLE', $exception->errorCode());
            }
        }
    }

    #[Test]
    public function point_03c_allows_a_declared_modification_only_while_draft_or_open(): void
    {
        $lifecycle = $this->lifecycle();

        foreach ([DrawLifecycleState::Draft, DrawLifecycleState::Open] as $state) {
            $draw = $this->drawInState($state);

            $this->assertTrue($lifecycle->isMutable($draw));

            $updated = $lifecycle->applyModification($draw, ['draw_number' => 'MOD-'.$state->value]);

            $this->assertSame('MOD-'.$state->value, (string) $updated->draw_number);
        }
    }

    #[Test]
    public function point_03d_refuses_to_modify_a_protected_field_even_on_an_open_draw(): void
    {
        $lifecycle = $this->lifecycle();
        $draw = $this->drawInState(DrawLifecycleState::Open);

        foreach (DrawLifecycleService::PROTECTED_FIELDS as $field) {
            try {
                $lifecycle->applyModification($draw, [$field => null]);
                $this->fail($field.' must not be settable through a modification.');
            } catch (DrawLifecycleException $exception) {
                $this->assertSame('DRAW_IMMUTABLE', $exception->errorCode());
                $this->assertSame($field, $exception->contextValue('field'));
            }
        }
    }

    #[Test]
    public function point_03e_a_draw_stops_accepting_bets_once_closed(): void
    {
        $this->assertTrue(DrawLifecycleState::Open->acceptsBets());

        foreach (DrawLifecycleState::cases() as $state) {
            if ($state === DrawLifecycleState::Open) {
                continue;
            }

            $this->assertFalse(
                $state->acceptsBets(),
                $state->value.' must not accept bets.',
            );
        }
    }

    // -------------------------------------------------------------------------
    // Backward compatibility of the one modified Phase 1 enum
    // -------------------------------------------------------------------------

    #[Test]
    public function the_added_draw_status_case_does_not_disturb_the_existing_ones(): void
    {
        // Phase 5.1 appends exactly one case. Every value Phase 1 to 4.4 relies on must
        // still exist with the same backing value.
        foreach (['scheduled', 'open', 'closed', 'drawing', 'completed', 'cancelled'] as $value) {
            $this->assertInstanceOf(
                DrawStatus::class,
                DrawStatus::tryFrom($value),
                'The pre-existing DrawStatus case '.$value.' must survive.',
            );
        }

        $this->assertInstanceOf(DrawStatus::class, DrawStatus::tryFrom('result_published'));

        // Every case still answers label() and color() - the two methods the added case
        // had to extend.
        foreach (DrawStatus::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->color());
        }
    }

    #[Test]
    public function the_lifecycle_state_maps_to_and_from_draw_status_totally(): void
    {
        foreach (DrawLifecycleState::cases() as $state) {
            $status = $state->toDrawStatus();

            $this->assertInstanceOf(
                DrawLifecycleState::class,
                DrawLifecycleState::fromDrawStatus($status),
                'Every lifecycle state must map back from its DrawStatus.',
            );
        }

        foreach (DrawStatus::cases() as $status) {
            $this->assertInstanceOf(
                DrawLifecycleState::class,
                DrawLifecycleState::fromDrawStatus($status),
                'Every DrawStatus must map to a lifecycle state, including the legacy '
                .'"drawing" value.',
            );
        }
    }

    #[Test]
    public function a_missing_draw_is_reported_and_not_invented(): void
    {
        $this->assertSame(0, Draw::query()->whereKey(987654321)->count());

        $this->expectException(DrawLifecycleException::class);

        $this->lifecycle()->lockForUpdate(987654321);
    }
}
