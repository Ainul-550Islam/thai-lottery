<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\DrawLifecycleState;
use App\Exceptions\DrawLifecycleException;
use App\Models\Draw;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;

/**
 * Advances a draw through the seven state Phase 5.1 lifecycle, and refuses every
 * move the transition table does not declare.
 *
 * THE SEVEN STATES AND THE ONLY LEGAL MOVES
 *   Draft            -> Open, Cancelled
 *   Open             -> Closed, Cancelled
 *   Closed           -> ResultPending, Cancelled
 *   ResultPending    -> ResultPublished, Cancelled
 *   ResultPublished  -> Settled
 *   Settled          terminal
 *   Cancelled        terminal
 *
 * That table is NOT written here. It lives in App\Enums\DrawLifecycleState and this
 * service reads it, so there is exactly one copy of the rules in the project. There
 * is no switch or if-chain over states anywhere in this class.
 *
 * WHAT IT GUARANTEES
 *
 * 1. VALID TRANSITIONS ONLY. transitionTo() consults
 *    DrawLifecycleState::canTransitionTo() and throws DrawLifecycleException on
 *    anything else, including a move to the same state and any move out of a
 *    terminal state. There is no force, override, bypass or skip parameter, and no
 *    method writes draws.status without going through the table.
 *
 * 2. A CLOSED OR PUBLISHED DRAW IS IMMUTABLE. assertMutable() refuses a
 *    modification unless the draw is Draft or Open, and applyModification() is the
 *    only way to change a draw's own fields. It also refuses to touch status,
 *    lifecycle timestamps and the cached money counters at all, in any state, since
 *    those are lifecycle and settlement outputs rather than editable attributes.
 *
 * 3. ROW LEVEL SERIALISATION. Every transition takes SELECT ... FOR UPDATE on the
 *    draw row and re-reads the state INSIDE the lock, so two concurrent callers
 *    cannot both see ResultPending and both publish. The second waits, re-reads
 *    ResultPublished, and is refused.
 *
 * LOCK ORDERING
 * The existing finance and betting order is WALLET -> FINANCIAL ENTITY -> LEDGER
 * ACCOUNTS. This service locks NONE of those. It takes only the draw row, so it
 * cannot deadlock against a purchase: it acquires no lock a purchase wants, and a
 * purchase acquires no lock it wants. Where settlement needs bets and bet_items it
 * takes them AFTER the draw, giving the strictly narrower order
 * DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No result validation and no result persistence: DrawResultValidator and
 *   DrawResultPublicationService.
 * - No matching, no pricing, no settlement: SelectionSettlementResolver and
 *   DrawSettlementSimulationService.
 * - NO MONEY. This class contains no wallet, ledger, financial transaction, payout,
 *   deposit, withdrawal or payment gateway reference, and it never writes
 *   draws.total_payout or draws.house_profit.
 * - No authorization decision. Who may advance a draw is App\Policies\DrawPolicy's
 *   job; this service is the domain rule and assumes its caller already authorized.
 */
class DrawLifecycleService
{
    /**
     * Draw fields a caller may modify while the draw is still mutable.
     *
     * Exactly App\Models\Draw::$fillable. status, opened_at, closed_at, drawn_at,
     * result_published_at, completed_at and every money counter are absent on
     * purpose: they are outputs of this lifecycle and of settlement, never caller
     * input.
     *
     * @var list<string>
     */
    public const MODIFIABLE_FIELDS = [
        'draw_number',
        'type',
        'scheduled_at',
        'metadata',
    ];

    /**
     * Fields no caller may ever set through this service, in any state.
     *
     * @var list<string>
     */
    public const PROTECTED_FIELDS = [
        'status',
        'opened_at',
        'closed_at',
        'drawn_at',
        'result_published_at',
        'completed_at',
        'total_bets',
        'total_amount_wagered',
        'total_payout',
        'house_profit',
    ];

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * The lifecycle state of a draw, derived from its stored status.
     */
    public function currentState(Draw $draw): DrawLifecycleState
    {
        return DrawLifecycleState::fromDrawStatus($draw->status);
    }

    /**
     * The lifecycle state of a draw by id.
     *
     * @throws DrawLifecycleException when the draw does not exist
     */
    public function stateOf(int $drawId): DrawLifecycleState
    {
        $draw = Draw::query()->whereKey($drawId)->first();

        if (! $draw instanceof Draw) {
            throw DrawLifecycleException::notFound($drawId);
        }

        return $this->currentState($draw);
    }

    /**
     * Whether a move is declared valid, without attempting it.
     */
    public function canTransition(Draw $draw, DrawLifecycleState $target): bool
    {
        return $this->currentState($draw)->canTransitionTo($target);
    }

    /**
     * The states a draw may move to right now.
     *
     * @return list<DrawLifecycleState>
     */
    public function allowedTransitions(Draw $draw): array
    {
        return $this->currentState($draw)->allowedTransitions();
    }

    /**
     * Move a draw to a new lifecycle state, or refuse.
     *
     * The draw row is locked and its state re-read inside the lock, so the decision
     * is made against committed data rather than against a possibly stale in-memory
     * model. The passed model is refreshed on success so the caller does not keep a
     * stale status.
     *
     * @param  array<string, scalar|null>  $context  diagnostic context for the refusal
     *
     * @throws DrawLifecycleException
     */
    public function transitionTo(Draw $draw, DrawLifecycleState $target, array $context = []): Draw
    {
        $drawId = (int) $draw->getKey();

        return $this->withLockedDraw($drawId, function (Draw $locked) use ($target, $context, $drawId): Draw {
            $current = $this->currentState($locked);

            $this->assertTransitionAllowed($drawId, $current, $target, $context);

            $locked->status = $target->toDrawStatus();

            $timestampColumn = $target->timestampColumn();

            if ($timestampColumn !== null && $locked->{$timestampColumn} === null) {
                // Stamped only when empty, so replaying a lifecycle never rewrites
                // history. now() is Laravel's clock, which tests freeze rather than
                // approximate.
                $locked->{$timestampColumn} = now();
            }

            if ($target === DrawLifecycleState::Cancelled) {
                // The draws table has NO cancelled_at and NO cancelled_reason column.
                // That is a real schema limitation, recorded in PHASE_5.1_AUDIT.md.
                // Rather than invent columns, the cancellation is recorded in the
                // existing metadata JSON. No migration is created here.
                $metadata = is_array($locked->metadata) ? $locked->metadata : [];
                $metadata['lifecycle'] = [
                    'cancelled_from' => $current->value,
                    'cancelled_at' => now()->toIso8601String(),
                    'note' => 'draws has no cancelled_at column; recorded in metadata by Phase 5.1',
                ];
                $locked->metadata = $metadata;
            }

            $locked->save();

            return $locked;
        }, $draw);
    }

    /**
     * Draft -> Open. Betting begins.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    public function open(Draw $draw, array $context = []): Draw
    {
        return $this->transitionTo($draw, DrawLifecycleState::Open, $context);
    }

    /**
     * Open -> Closed. Betting ends and the draw is frozen from here on.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    public function close(Draw $draw, array $context = []): Draw
    {
        return $this->transitionTo($draw, DrawLifecycleState::Closed, $context);
    }

    /**
     * Closed -> ResultPending. The official numbers are being drawn.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    public function markResultPending(Draw $draw, array $context = []): Draw
    {
        return $this->transitionTo($draw, DrawLifecycleState::ResultPending, $context);
    }

    /**
     * ResultPending -> ResultPublished.
     *
     * Deliberately NOT public API for publishing a result: the state change alone
     * would leave the draw claiming a published result with no draw_results row.
     * DrawResultPublicationService calls this inside the same transaction that writes
     * the result and the winning numbers, which is what makes publication atomic.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    public function markResultPublished(Draw $draw, array $context = []): Draw
    {
        return $this->transitionTo($draw, DrawLifecycleState::ResultPublished, $context);
    }

    /**
     * ResultPublished -> Settled.
     *
     * Called by DrawSettlementSimulationService inside its settlement transaction.
     * Because Settled is terminal and only reachable from ResultPublished, the stored
     * state alone proves whether settlement has already run.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    public function markSettled(Draw $draw, array $context = []): Draw
    {
        return $this->transitionTo($draw, DrawLifecycleState::Settled, $context);
    }

    /**
     * Cancel a draw, allowed up to and including ResultPending.
     *
     * ResultPublished -> Cancelled is refused by the transition table, because
     * cancelling after the numbers are public is a reversal and Phase 5.1 implements
     * nothing reversal shaped.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    public function cancel(Draw $draw, array $context = []): Draw
    {
        return $this->transitionTo($draw, DrawLifecycleState::Cancelled, $context);
    }

    /**
     * Refuse unless the draw's own fields may still be changed.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    public function assertMutable(Draw $draw, string $attemptedChange, array $context = []): void
    {
        $state = $this->currentState($draw);

        if (! $state->isMutable()) {
            throw DrawLifecycleException::immutable(
                (int) $draw->getKey(),
                $state,
                $attemptedChange,
                $context + ['lock_after_publication' => $this->locksAfterPublication()],
            );
        }
    }

    /**
     * Whether the draw's own fields may still be changed.
     */
    public function isMutable(Draw $draw): bool
    {
        return $this->currentState($draw)->isMutable();
    }

    /**
     * Modify a draw's own fields, refusing once it is frozen.
     *
     * The mutability check happens INSIDE the row lock, so a draw that is being
     * closed concurrently cannot also be edited.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws DrawLifecycleException
     */
    public function applyModification(Draw $draw, array $attributes, string $description = 'modification'): Draw
    {
        $drawId = (int) $draw->getKey();

        foreach (array_keys($attributes) as $field) {
            $field = (string) $field;

            if (in_array($field, self::PROTECTED_FIELDS, true)) {
                throw DrawLifecycleException::immutable(
                    $drawId,
                    $this->currentState($draw),
                    sprintf(
                        'setting "%s" through a draw modification. That field is a lifecycle or '
                        .'settlement output and is never caller input',
                        $field,
                    ),
                    ['field' => $field],
                );
            }

            if (! in_array($field, self::MODIFIABLE_FIELDS, true)) {
                throw DrawLifecycleException::immutable(
                    $drawId,
                    $this->currentState($draw),
                    sprintf('setting the unknown or non-modifiable field "%s"', $field),
                    ['field' => $field],
                );
            }
        }

        return $this->withLockedDraw($drawId, function (Draw $locked) use ($attributes, $description): Draw {
            $this->assertMutable($locked, $description);

            $locked->fill($attributes);
            $locked->save();

            return $locked;
        }, $draw);
    }

    /**
     * Refuse unless an official result may be published from the draw's state.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    public function assertCanPublishResult(Draw $draw, array $context = []): void
    {
        $state = $this->currentState($draw);

        if ($state->hasPublishedResult()) {
            throw DrawLifecycleException::duplicatePublication(
                (int) $draw->getKey(),
                sprintf('draw lifecycle state is already %s', $state->value),
                $context + ['state' => $state->value],
            );
        }

        if (! $state->canPublishResult()) {
            throw DrawLifecycleException::notPublishable((int) $draw->getKey(), $state, $context);
        }
    }

    /**
     * Refuse unless simulated settlement may run from the draw's state.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    public function assertCanSettle(Draw $draw, array $context = []): void
    {
        $state = $this->currentState($draw);

        if (! $state->canSettle()) {
            throw DrawLifecycleException::notSettleable((int) $draw->getKey(), $state, $context);
        }
    }

    /**
     * Load a draw under SELECT ... FOR UPDATE.
     *
     * Callers that need the draw and its dependants in one transaction use this
     * first, which is what fixes the DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS order.
     *
     * @throws DrawLifecycleException when the draw does not exist
     */
    public function lockForUpdate(int $drawId): Draw
    {
        $draw = Draw::query()->whereKey($drawId)->lockForUpdate()->first();

        if (! $draw instanceof Draw) {
            throw DrawLifecycleException::notFound($drawId);
        }

        return $draw;
    }

    /**
     * Whether configuration says a published draw is locked.
     *
     * config('lottery.results.lock_after_publication') is already true in this
     * project. It is READ here and reported, never used to relax a rule: even were
     * it false, the transition table would still refuse to reopen a published draw,
     * because the immutability of a published result is a domain invariant and not a
     * setting.
     */
    public function locksAfterPublication(): bool
    {
        return $this->config->get('lottery.results.lock_after_publication') === true;
    }

    /**
     * The lifecycle in report form.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        return [
            'states' => DrawLifecycleState::audit(),
            'modifiable_fields' => self::MODIFIABLE_FIELDS,
            'protected_fields' => self::PROTECTED_FIELDS,
            'lock_after_publication' => $this->locksAfterPublication(),
            'lock_order' => 'DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS',
            'guarantees' => $this->guarantees(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'single_transition_table' => 'The only transition table is DrawLifecycleState::transitions(). '
                .'This service reads it and contains no switch or if-chain over states.',
            'no_override' => 'There is no force, override, bypass or skip parameter. An invalid '
                .'transition is refused, never performed.',
            'row_locked' => 'Every transition re-reads the state inside SELECT ... FOR UPDATE on the '
                .'draw row, so two concurrent callers cannot both act on the same state.',
            'frozen_after_close' => 'applyModification() refuses once the draw leaves Draft or Open, '
                .'and refuses status, lifecycle timestamps and money counters in every state.',
            'no_duplicate_publication' => 'assertCanPublishResult() refuses when the state already '
                .'implies a published result, and the unique key on draw_results.draw_id refuses it '
                .'again at the database.',
            'terminal_is_terminal' => 'Settled and Cancelled accept no transition, so there is no '
                .'un-settle, no re-open and no re-publish.',
            'no_money' => 'This service references no wallet, ledger, financial transaction, payout, '
                .'deposit, withdrawal or payment gateway, and never writes draws.total_payout or '
                .'draws.house_profit.',
            'no_raw_sql' => 'Every read and write goes through Eloquent. There is no DB::statement, '
                .'DB::raw, DB::select, DB::update or DB::unprepared call.',
        ];
    }

    /**
     * Refuse a move the table does not declare.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DrawLifecycleException
     */
    private function assertTransitionAllowed(
        int $drawId,
        DrawLifecycleState $current,
        DrawLifecycleState $target,
        array $context = [],
    ): void {
        if ($current->isTerminal()) {
            throw DrawLifecycleException::terminalState($drawId, $current, $target, $context);
        }

        if (! $current->canTransitionTo($target)) {
            throw DrawLifecycleException::invalidTransition($drawId, $current, $target, $context);
        }
    }

    /**
     * Run a callback against a freshly locked draw row.
     *
     * When a transaction is already open the callback joins it, so a caller such as
     * DrawResultPublicationService can hold the lock across the result write and the
     * state change and have both roll back together. Otherwise a transaction is
     * opened here so that even a bare transition is atomic.
     *
     * @template TReturn
     *
     * @param  callable(Draw): TReturn  $callback
     * @return TReturn
     *
     * @throws DrawLifecycleException
     */
    private function withLockedDraw(int $drawId, callable $callback, ?Draw $refresh = null): mixed
    {
        $run = function () use ($drawId, $callback, $refresh): mixed {
            $locked = $this->lockForUpdate($drawId);

            $outcome = $callback($locked);

            if ($refresh instanceof Draw && $refresh->getKey() === $locked->getKey()) {
                // Keep the caller's model honest rather than leaving it stale.
                $refresh->setRawAttributes($locked->getAttributes(), true);
            }

            return $outcome;
        };

        if (DB::transactionLevel() > 0) {
            return $run();
        }

        return DB::transaction($run);
    }
}
