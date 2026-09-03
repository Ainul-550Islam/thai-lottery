<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Models\Draw;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Turns the declared draw calendar into rows, and answers which draws are due for
 * their next lifecycle move.
 *
 * WHY THIS EXISTS
 * Phase 5.1 delivered a complete, tested draw lifecycle - and nothing that invoked
 * it. config('lottery.draw.official_schedule') declared draws on the 1st and 16th at
 * 15:00, and config('lottery.closing') declared an auto-close five minutes before the
 * draw, but no code read either value, so a draw only existed if someone inserted it
 * and only moved if someone called a service in tinker. This class is the missing
 * half: the calendar arithmetic and the "what is due right now" queries that the
 * console commands and the scheduler consume.
 *
 * WHAT IT GUARANTEES
 *
 * 1. IDEMPOTENT PROVISIONING. A draw's identity is derived from its scheduled
 *    moment - draw_number is DR-YYYYMMDD-HHMM, not a random string - so running
 *    provisioning twice, or twice concurrently, cannot create the same draw twice.
 *    The unique index on draws.draw_number is the backstop, and a duplicate is
 *    treated as "already provisioned", not as an error.
 *
 * 2. THE CALENDAR IS READ, NEVER GUESSED. Days of month and time come from config.
 *    A month without the configured day (there is no 31st in February) simply has no
 *    occurrence; no date is rolled forward into a day the operator did not declare,
 *    because silently drawing on a different day is worse than not drawing.
 *
 * 3. ONE TIMEZONE DECISION, MADE EXPLICITLY. Occurrences are built in
 *    config('lottery.timezone') - the market's timezone, Asia/Bangkok by default -
 *    and converted to the application timezone before they are stored, because
 *    Eloquent formats a Carbon for storage without converting it. "15:00 on the 16th"
 *    therefore means 15:00 in Bangkok no matter where the server runs.
 *
 * 4. DUE-QUERIES ARE STATE PLUS TIME, NOTHING ELSE. Each due* method is a plain
 *    Eloquent builder over draws.status and a timestamp column, ordered oldest
 *    first, so a backlog drains in the order it accumulated and the caller can count
 *    or paginate before acting.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No transitions. This class never writes draws.status; DrawLifecycleService owns
 *   every state change and is the only thing that may.
 * - No result publication and no settlement.
 * - NO MONEY. It contains no wallet, ledger, payout or payment reference.
 * - No authorization. Who may run provisioning is the command layer's problem.
 */
class DrawScheduleService
{
    /**
     * The prefix fallback when config('lottery.draw.reference_prefix') is unusable.
     */
    public const DEFAULT_PREFIX = 'DR';

    public function __construct(private readonly ConfigRepository $config) {}

    // -------------------------------------------------------------------------
    // The calendar
    // -------------------------------------------------------------------------

    /**
     * Every declared draw moment in [$from, $to], ascending.
     *
     * Boundaries are inclusive so a horizon that lands exactly on a draw time
     * includes that draw rather than dropping it.
     *
     * @return list<CarbonImmutable> in the market timezone
     */
    public function occurrencesBetween(Carbon|CarbonImmutable $from, Carbon|CarbonImmutable $to): array
    {
        $timezone = $this->timezone();
        $start = CarbonImmutable::parse($from)->setTimezone($timezone);
        $end = CarbonImmutable::parse($to)->setTimezone($timezone);

        if ($end->lessThan($start)) {
            return [];
        }

        $days = $this->daysOfMonth();
        [$hour, $minute] = $this->timeOfDay();

        $occurrences = [];

        // Walk months, not days: two configured days a month over a 90 day horizon is
        // three iterations of this loop, where a day-by-day walk would be ninety.
        $cursor = $start->startOfMonth();
        $lastMonth = $end->startOfMonth();

        while ($cursor->lessThanOrEqualTo($lastMonth)) {
            foreach ($days as $day) {
                // A day the month does not have is skipped, never clamped and never
                // rolled into the next month. Carbon would happily turn "February 31"
                // into March 3; drawing on an undeclared day is not an acceptable
                // interpretation of the schedule.
                if ($day > $cursor->daysInMonth) {
                    continue;
                }

                $moment = $cursor->setDay($day)->setTime($hour, $minute);

                if ($moment->greaterThanOrEqualTo($start) && $moment->lessThanOrEqualTo($end)) {
                    $occurrences[] = $moment;
                }
            }

            $cursor = $cursor->addMonthNoOverflow();
        }

        usort($occurrences, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $occurrences;
    }

    /**
     * The declared draw moment immediately before $moment, if the calendar has one.
     *
     * Used to open a betting window the instant the previous draw's window ends, so
     * there is no dead period in which no draw accepts bets.
     */
    public function previousOccurrenceBefore(Carbon|CarbonImmutable $moment): ?CarbonImmutable
    {
        $target = CarbonImmutable::parse($moment)->setTimezone($this->timezone());

        // Two months back is enough for any calendar with at least one draw a month,
        // and bounded so a misconfigured empty calendar cannot loop forever.
        $candidates = $this->occurrencesBetween($target->subMonthsNoOverflow(2), $target->subSecond());

        return $candidates === [] ? null : $candidates[array_key_last($candidates)];
    }

    /**
     * The moment betting closes for a draw scheduled at $scheduledAt.
     *
     * config('lottery.closing.minutes_before_draw') minutes before the draw, plus the
     * declared grace seconds. This is the ONLY place that arithmetic is written; the
     * close command and the provisioner both read it here, so the stored
     * betting_close_at and the moment the closer acts cannot disagree.
     */
    public function bettingCloseAtFor(Carbon|CarbonImmutable $scheduledAt): CarbonImmutable
    {
        $minutes = max(0, (int) $this->config->get('lottery.closing.minutes_before_draw', 5));
        $grace = max(0, (int) $this->config->get('lottery.closing.grace_seconds', 0));

        return CarbonImmutable::parse($scheduledAt)
            ->subMinutes($minutes)
            ->addSeconds($grace);
    }

    /**
     * The moment betting opens for a draw scheduled at $scheduledAt.
     *
     * The previous draw's close time when the calendar has a previous occurrence,
     * otherwise config('lottery.draw.betting_window_days') before the draw.
     */
    public function bettingOpenAtFor(Carbon|CarbonImmutable $scheduledAt): CarbonImmutable
    {
        $scheduled = CarbonImmutable::parse($scheduledAt)->setTimezone($this->timezone());
        $previous = $this->previousOccurrenceBefore($scheduled);

        if ($previous instanceof CarbonImmutable) {
            return $this->bettingCloseAtFor($previous);
        }

        $days = max(1, (int) $this->config->get('lottery.draw.betting_window_days', 15));

        return $scheduled->subDays($days);
    }

    /**
     * The deterministic draw number for a moment: DR-YYYYMMDD-HHMM.
     *
     * Derived rather than random ON PURPOSE. It is what makes provisioning safe to
     * repeat: the second run computes the same identifier, finds the row, and creates
     * nothing. It is also readable in a support conversation.
     */
    public function drawNumberFor(Carbon|CarbonImmutable $scheduledAt): string
    {
        $moment = CarbonImmutable::parse($scheduledAt)->setTimezone($this->timezone());

        return sprintf('%s-%s-%s', $this->prefix(), $moment->format('Ymd'), $moment->format('Hi'));
    }

    // -------------------------------------------------------------------------
    // Provisioning
    // -------------------------------------------------------------------------

    /**
     * Create every declared draw from now to the configured horizon that does not
     * exist yet.
     *
     * @return array{created: list<Draw>, existing: int, horizon_days: int, horizon_until: CarbonImmutable}
     */
    public function provision(?int $horizonDays = null): array
    {
        $horizon = $horizonDays ?? (int) $this->config->get('lottery.automation.horizon_days', 90);
        $horizon = max(1, $horizon);

        $now = CarbonImmutable::now($this->timezone());
        $until = $now->addDays($horizon);

        $created = [];
        $existing = 0;

        foreach ($this->occurrencesBetween($now, $until) as $moment) {
            $draw = $this->provisionOne($moment);

            if ($draw === null) {
                $existing++;

                continue;
            }

            $created[] = $draw;
        }

        return [
            'created' => $created,
            'existing' => $existing,
            'horizon_days' => $horizon,
            'horizon_until' => $until,
        ];
    }

    /**
     * Create the draw for one declared moment, or return null if it already exists.
     *
     * Soft-deleted rows count as existing: a draw an operator deleted must not
     * silently reappear on the next tick.
     */
    public function provisionOne(Carbon|CarbonImmutable $scheduledAt): ?Draw
    {
        $moment = CarbonImmutable::parse($scheduledAt)->setTimezone($this->timezone());
        $drawNumber = $this->drawNumberFor($moment);

        $alreadyExists = Draw::withTrashed()
            ->where('draw_number', $drawNumber)
            ->exists();

        if ($alreadyExists) {
            return null;
        }

        $draw = new Draw;
        $draw->draw_number = $drawNumber;
        $draw->type = $this->defaultType();
        $draw->scheduled_at = $this->forStorage($moment);
        $draw->metadata = [
            'provisioned_by' => 'lottery:schedule-draws',
            'provisioned_at' => CarbonImmutable::now()->toIso8601String(),
            'schedule_source' => 'config(lottery.draw.official_schedule)',
            'market_timezone' => $this->timezone(),
            'scheduled_local' => $moment->toIso8601String(),
        ];

        // betting_open_at and betting_close_at are outside Draw::$fillable and outside
        // DrawLifecycleService::MODIFIABLE_FIELDS, so they are set here on a new model
        // rather than mass assigned. They are the PLANNED window; opened_at/closed_at
        // remain the record of what actually happened, written only by the lifecycle.
        $draw->betting_open_at = $this->forStorage($this->bettingOpenAtFor($moment));
        $draw->betting_close_at = $this->forStorage($this->bettingCloseAtFor($moment));

        // The initial status is the configured one, and it must be the status the
        // lifecycle treats as Draft - otherwise a provisioned draw could never be
        // opened. A wrong config value fails loudly here instead of producing draws
        // that are stuck.
        $draw->status = $this->initialStatus();

        try {
            $draw->save();
        } catch (\Illuminate\Database\QueryException $exception) {
            // A concurrent provisioner won the race. The row it wrote is identical -
            // the identifier is derived from the same moment - so this is success,
            // not a failure. Any other query error is a real problem and re-thrown.
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }

        return $draw;
    }

    // -------------------------------------------------------------------------
    // What is due
    // -------------------------------------------------------------------------

    /**
     * Draws that should start accepting bets: still Scheduled (Draft) and inside
     * their planned betting window.
     *
     * A draw whose close time has already passed is NOT opened. Opening it would
     * create a draw that accepts bets it must immediately reject - the validator
     * enforces betting_close_at independently of status - so a draw that was
     * provisioned late is left Draft for the closer to handle.
     *
     * @return Builder<Draw>
     */
    public function dueToOpen(?Carbon $now = null): Builder
    {
        $moment = $now ?? Carbon::now();

        return Draw::query()
            ->where('status', DrawStatus::Scheduled)
            ->where(function (Builder $query) use ($moment): void {
                $query->whereNull('betting_open_at')
                    ->orWhere('betting_open_at', '<=', $moment);
            })
            ->where(function (Builder $query) use ($moment): void {
                $query->whereNull('betting_close_at')
                    ->orWhere('betting_close_at', '>', $moment);
            })
            ->where('scheduled_at', '>', $moment)
            ->orderBy('scheduled_at');
    }

    /**
     * Draws whose betting window has ended but which are still Open.
     *
     * Includes a draw with no planned close time once its scheduled moment has
     * passed, so a hand-created draw missing betting_close_at still closes rather
     * than accepting bets forever.
     *
     * @return Builder<Draw>
     */
    public function dueToClose(?Carbon $now = null): Builder
    {
        $moment = $now ?? Carbon::now();

        return Draw::query()
            ->where('status', DrawStatus::Open)
            ->where(function (Builder $query) use ($moment): void {
                $query->where('betting_close_at', '<=', $moment)
                    ->orWhere(function (Builder $inner) use ($moment): void {
                        $inner->whereNull('betting_close_at')
                            ->where('scheduled_at', '<=', $moment);
                    });
            })
            ->orderBy('scheduled_at');
    }

    /**
     * Closed draws whose scheduled moment has passed: the numbers are now being
     * drawn in the real world, so the draw is awaiting its result.
     *
     * @return Builder<Draw>
     */
    public function dueForResultPending(?Carbon $now = null): Builder
    {
        $moment = $now ?? Carbon::now();
        $after = max(0, (int) $this->config->get('lottery.automation.result_pending_after_minutes', 0));

        return Draw::query()
            ->where('status', DrawStatus::Closed)
            ->where('scheduled_at', '<=', $moment->copy()->subMinutes($after))
            ->orderBy('scheduled_at');
    }

    /**
     * Published draws whose settlement delay has elapsed.
     *
     * The delay is measured from result_published_at, not from the scheduled time,
     * because the window exists to let an operator catch a mistyped result - and
     * that window starts when the result was entered.
     *
     * @return Builder<Draw>
     */
    public function dueToSettle(?Carbon $now = null): Builder
    {
        $moment = $now ?? Carbon::now();
        $delay = max(0, (int) $this->config->get('lottery.automation.settlement_delay_minutes', 0));
        $cutoff = $moment->copy()->subMinutes($delay);

        return Draw::query()
            ->where('status', DrawStatus::ResultPublished)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('result_published_at', '<=', $cutoff)
                    ->orWhereNull('result_published_at');
            })
            ->orderBy('scheduled_at');
    }

    // -------------------------------------------------------------------------
    // Introspection
    // -------------------------------------------------------------------------

    /**
     * Whether automation is switched on.
     */
    public function automationEnabled(): bool
    {
        return (bool) $this->config->get('lottery.automation.enabled', false);
    }

    /**
     * Whether closing is automatic, per config('lottery.closing.auto_close').
     */
    public function autoCloseEnabled(): bool
    {
        return (bool) $this->config->get('lottery.closing.auto_close', false);
    }

    /**
     * Whether unattended settlement is switched on.
     */
    public function autoSettleEnabled(): bool
    {
        return (bool) $this->config->get('lottery.automation.auto_settle', false);
    }

    /**
     * How many draws one command run may touch.
     */
    public function batchSize(): int
    {
        return max(1, (int) $this->config->get('lottery.automation.batch_size', 50));
    }

    /**
     * The cron expression the scheduler uses for the orchestrator.
     */
    public function tickCron(): string
    {
        $expression = $this->config->get('lottery.automation.tick_cron');

        return is_string($expression) && trim($expression) !== '' ? trim($expression) : '* * * * *';
    }

    /**
     * A machine readable description of what this service decided, for the audit
     * trail and for the reports.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        return [
            'service' => self::class,
            'writes_status' => false,
            'writes_money' => false,
            'publishes_results' => false,
            'calendar_source' => 'config(lottery.draw.official_schedule)',
            'close_arithmetic_source' => 'config(lottery.closing.minutes_before_draw) + grace_seconds',
            'market_timezone' => $this->timezone(),
            'application_timezone' => (string) $this->config->get('app.timezone', 'UTC'),
            'draw_number_format' => $this->prefix().'-YYYYMMDD-HHMM (derived, so provisioning is idempotent)',
            'automation_enabled' => $this->automationEnabled(),
            'auto_close_enabled' => $this->autoCloseEnabled(),
            'auto_settle_enabled' => $this->autoSettleEnabled(),
            'horizon_days' => (int) $this->config->get('lottery.automation.horizon_days', 90),
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function timezone(): string
    {
        $timezone = $this->config->get('lottery.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * @return list<int>
     */
    private function daysOfMonth(): array
    {
        $configured = $this->config->get('lottery.draw.official_schedule.days_of_month', []);
        $days = [];

        foreach ((array) $configured as $day) {
            if (! is_numeric($day)) {
                continue;
            }

            $day = (int) $day;

            if ($day >= 1 && $day <= 31) {
                $days[$day] = $day;
            }
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function timeOfDay(): array
    {
        $time = $this->config->get('lottery.draw.official_schedule.time', '15:00');

        if (is_string($time) && preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $matches) === 1) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            if ($hour <= 23 && $minute <= 59) {
                return [$hour, $minute];
            }
        }

        // A malformed time falls back to midday rather than to 00:00, because a draw
        // silently moved to the start of the day is a worse surprise than one at noon,
        // and the fallback is reported by audit().
        return [12, 0];
    }

    private function prefix(): string
    {
        $prefix = $this->config->get('lottery.draw.reference_prefix');

        return is_string($prefix) && trim($prefix) !== '' ? strtoupper(trim($prefix)) : self::DEFAULT_PREFIX;
    }

    private function defaultType(): DrawType
    {
        $configured = $this->config->get('lottery.draw.default_type');

        if (is_string($configured)) {
            $type = DrawType::tryFrom($configured);

            if ($type instanceof DrawType) {
                return $type;
            }
        }

        return DrawType::ThreeD;
    }

    private function initialStatus(): DrawStatus
    {
        $configured = $this->config->get('lottery.draw.initial_status');

        if (is_string($configured)) {
            $status = DrawStatus::tryFrom($configured);

            if ($status instanceof DrawStatus) {
                return $status;
            }
        }

        return DrawStatus::Scheduled;
    }

    /**
     * Convert a market-timezone moment to the timezone the application stores in.
     *
     * Eloquent formats a Carbon for storage WITHOUT converting its timezone, so
     * skipping this would store 15:00 as if it were 15:00 UTC and shift every draw
     * by the market's offset.
     */
    private function forStorage(Carbon|CarbonImmutable $moment): Carbon
    {
        $appTimezone = $this->config->get('app.timezone');
        $appTimezone = is_string($appTimezone) && $appTimezone !== '' ? $appTimezone : 'UTC';

        return Carbon::parse($moment)->setTimezone($appTimezone);
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $exception): bool
    {
        // 23000/23505 are the SQLSTATE integrity-violation classes across MySQL,
        // MariaDB, PostgreSQL and SQLite. The driver message is not parsed.
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
