<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The seven state draw lifecycle of Phase 5.1, and the only place transition rules
 * are declared.
 *
 * WHY A SECOND ENUM EXISTS ALONGSIDE App\Enums\DrawStatus
 * ------------------------------------------------------
 * DrawStatus is the PERSISTENCE vocabulary: it is what draws.status stores and what
 * App\Models\Draw casts to, and Phase 1 named its states 'scheduled', 'drawing' and
 * 'completed'. The Phase 5.1 specification names the same states Draft,
 * ResultPending and Settled.
 *
 * Renaming the Phase 1 cases to match the specification would break
 * Database\Factories\DrawFactory, Draw::scopeScheduled(), Draw::scopeCompleted(),
 * Draw::isCompleted(), config('lottery.draw.initial_status') and every row already
 * stored. So the specification's vocabulary is declared here instead, and mapped
 * onto the persisted values by two total functions.
 *
 * DIVISION OF RESPONSIBILITY, NO DUPLICATION
 *   DrawStatus           owns the stored string.
 *   DrawLifecycleState   owns the state names and the transition table.
 * Neither repeats the other. There is exactly one transition table in the project
 * and it is transitions() below; App\Services\Draw\DrawLifecycleService reads it and
 * does not contain a second copy, a switch or an if-chain over states.
 *
 * THE MAPPING
 *   Draft            <-> DrawStatus::Scheduled        ('scheduled')
 *   Open             <-> DrawStatus::Open             ('open')
 *   Closed           <-> DrawStatus::Closed           ('closed')
 *   ResultPending    <-> DrawStatus::Drawing          ('drawing')
 *   ResultPublished  <-> DrawStatus::ResultPublished   ('result_published')  [added in 5.1]
 *   Settled          <-> DrawStatus::Completed        ('completed')
 *   Cancelled        <-> DrawStatus::Cancelled        ('cancelled')
 *
 * fromDrawStatus() and toDrawStatus() are TOTAL over their inputs and have no
 * default branch. If a future phase adds a DrawStatus case, fromDrawStatus() will
 * fail to compile-match rather than quietly mapping the new status onto a plausible
 * lifecycle state.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No database access, no model knowledge, no timestamps. Advancing a draw is
 *   App\Services\Draw\DrawLifecycleService's job.
 * - No result value, no market rule, no payout, no money of any kind.
 * - No force, override, bypass or skip concept. An invalid transition is refused,
 *   never overridden.
 */
enum DrawLifecycleState: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case ResultPending = 'result_pending';
    case ResultPublished = 'result_published';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    /**
     * Human readable name for reports and audit records.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::ResultPending => 'Result Pending',
            self::ResultPublished => 'Result Published',
            self::Settled => 'Settled',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The persisted status this lifecycle state is stored as.
     *
     * Total, no default branch.
     */
    public function toDrawStatus(): DrawStatus
    {
        return match ($this) {
            self::Draft => DrawStatus::Scheduled,
            self::Open => DrawStatus::Open,
            self::Closed => DrawStatus::Closed,
            self::ResultPending => DrawStatus::Drawing,
            self::ResultPublished => DrawStatus::ResultPublished,
            self::Settled => DrawStatus::Completed,
            self::Cancelled => DrawStatus::Cancelled,
        };
    }

    /**
     * The lifecycle state a persisted status represents.
     *
     * Total, no default branch, so an unmapped future DrawStatus case is a match
     * error at the call site rather than a silent guess.
     */
    public static function fromDrawStatus(DrawStatus $status): self
    {
        return match ($status) {
            DrawStatus::Scheduled => self::Draft,
            DrawStatus::Open => self::Open,
            DrawStatus::Closed => self::Closed,
            DrawStatus::Drawing => self::ResultPending,
            DrawStatus::ResultPublished => self::ResultPublished,
            DrawStatus::Completed => self::Settled,
            DrawStatus::Cancelled => self::Cancelled,
        };
    }

    /**
     * THE transition table. The single source of truth for what may follow what.
     *
     * Rules, stated rather than implied:
     *
     *   Draft            -> Open, Cancelled
     *   Open             -> Closed, Cancelled
     *   Closed           -> ResultPending, Cancelled
     *   ResultPending    -> ResultPublished, Cancelled
     *   ResultPublished  -> Settled
     *   Settled          -> nothing (terminal)
     *   Cancelled        -> nothing (terminal)
     *
     * ResultPublished -> Cancelled is REFUSED on purpose. Once the official numbers
     * are public, cancelling the draw is a financial reversal, and Phase 5.1 is a
     * non-monetary simulator that implements nothing reversal shaped. Cancellation
     * is available up to and including ResultPending.
     *
     * There is no path back: no un-settle, no re-open, no re-publish. That is what
     * makes "prevent duplicate result publication" and settlement idempotency
     * enforceable from the stored state alone.
     *
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Open, self::Cancelled],
            self::Open->value => [self::Closed, self::Cancelled],
            self::Closed->value => [self::ResultPending, self::Cancelled],
            self::ResultPending->value => [self::ResultPublished, self::Cancelled],
            self::ResultPublished->value => [self::Settled],
            self::Settled->value => [],
            self::Cancelled->value => [],
        ];
    }

    /**
     * The states this state may move to.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return self::transitions()[$this->value] ?? [];
    }

    /**
     * Whether a move from this state to the given state is declared valid.
     *
     * A move to the SAME state is not a transition and is refused. That is what
     * makes a second publication attempt and a second settlement attempt detectable
     * rather than idempotent-by-accident.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Terminal states accept no further transition.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the draw's own definition and schedule may still be modified.
     *
     * True only in Draft and Open. From Closed onwards the draw is frozen, which is
     * the "prevent modification of a closed/published draw" requirement, and
     * config('lottery.results.lock_after_publication') is already true in this
     * project. Lifecycle transitions are not modification and are governed by
     * canTransitionTo() instead.
     */
    public function isMutable(): bool
    {
        return $this === self::Draft || $this === self::Open;
    }

    /**
     * Whether the draw accepts new bets in this state.
     *
     * Open only. Agrees with DrawStatus::canAcceptBets(), which is also Open only,
     * so the two vocabularies cannot disagree about when betting is live.
     */
    public function acceptsBets(): bool
    {
        return $this === self::Open;
    }

    /**
     * Whether an official result may be published from this state.
     *
     * ResultPending only. A draw that is already ResultPublished or Settled is
     * refused here, which is the application-level half of the duplicate
     * publication guard; the database half is the unique key on
     * draw_results.draw_id and the winning_numbers composite unique key.
     */
    public function canPublishResult(): bool
    {
        return $this === self::ResultPending;
    }

    /**
     * Whether simulated settlement may run from this state.
     *
     * ResultPublished only. Settled is refused, which is how a second settlement
     * run is detected and turned into a zero-write no-op.
     */
    public function canSettle(): bool
    {
        return $this === self::ResultPublished;
    }

    /**
     * Whether the official result of the draw is already public.
     */
    public function hasPublishedResult(): bool
    {
        return $this === self::ResultPublished || $this === self::Settled;
    }

    /**
     * Whether simulated settlement has already completed.
     */
    public function isSettled(): bool
    {
        return $this === self::Settled;
    }

    /**
     * The lifecycle timestamp column on draws that entering this state stamps.
     *
     * Every column named here already exists in the draws table; none is invented.
     * Draft has no arrival timestamp because a draw is created in it, and Cancelled
     * has none because the draws table has no cancelled_at column - that absence is
     * recorded in the Phase 5.1 audit as a schema limitation and is handled by
     * writing the cancellation reason into draws.metadata rather than by adding a
     * column.
     */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Draft => null,
            self::Open => 'opened_at',
            self::Closed => 'closed_at',
            self::ResultPending => 'drawn_at',
            self::ResultPublished => 'result_published_at',
            self::Settled => 'completed_at',
            self::Cancelled => null,
        };
    }

    /**
     * Resolve a state from its backing value without defaulting.
     */
    public static function fromValue(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The whole lifecycle in report form.
     *
     * @return array<string, array{state: string, label: string, persisted_status: string, allowed_transitions: list<string>, terminal: bool, mutable: bool, accepts_bets: bool, can_publish_result: bool, can_settle: bool, timestamp_column: string|null}>
     */
    public static function audit(): array
    {
        $audit = [];

        foreach (self::cases() as $state) {
            $audit[$state->value] = [
                'state' => $state->value,
                'label' => $state->label(),
                'persisted_status' => $state->toDrawStatus()->value,
                'allowed_transitions' => array_map(
                    static fn (self $target): string => $target->value,
                    $state->allowedTransitions(),
                ),
                'terminal' => $state->isTerminal(),
                'mutable' => $state->isMutable(),
                'accepts_bets' => $state->acceptsBets(),
                'can_publish_result' => $state->canPublishResult(),
                'can_settle' => $state->canSettle(),
                'timestamp_column' => $state->timestampColumn(),
            ];
        }

        return $audit;
    }
}
