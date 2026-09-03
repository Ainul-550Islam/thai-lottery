# Draw automation — implementation report

**Date:** 2026-08-31
**Closes:** §3.1 of `AUDIT-2026-08-31.md` ("the system cannot run itself")
**Verified on:** PHP 8.3.28 · Laravel 11.56.1 · PHPUnit 11.5.56 · SQLite 3 (`thai_lottery_test`)

---

## 1. The problem this closes

Phase 5.1 shipped a complete, tested draw lifecycle that nothing invoked. The 1st/16th
calendar in `config('lottery.draw.official_schedule')` and the 5-minute cut-off in
`config('lottery.closing.auto_close')` were configuration no code read, and
`bootstrap/app.php` declared no schedule at all. Every transition had to be made by calling
a service by hand.

## 2. What was added

### One scheduled orchestrator

`bootstrap/app.php` now registers `lottery:tick` on `config('lottery.automation.tick_cron')`
(default every minute) with `withoutOverlapping(10)`, `onOneServer()`, `runInBackground()`,
the market timezone, and output appended to `storage/logs/lottery-tick.log`. It is
registered **only** when `config('lottery.automation.enabled')` is true. `withCommands()`
was added because Laravel 11 does not auto-discover `app/Console/Commands` from
`withRouting(commands:)` alone.

One task rather than five means the whole chain advances inside the same minute: a draw can
close and move to *awaiting official numbers* in a single tick.

### Five automated steps, in order

| # | Command | Trigger condition |
|---|---------|-------------------|
| 1 | `lottery:schedule-draws` | Any calendar occurrence inside `automation.horizon_days` with no draw row yet |
| 2 | `lottery:open-draws` | `status = scheduled`, `betting_open_at <= now`, cut-off not yet passed |
| 3 | `lottery:close-draws` | `status = open`, `betting_close_at <= now` (falls back to `scheduled_at` for hand-created draws) |
| 4 | `lottery:mark-results-pending` | `status = closed`, `scheduled_at + result_pending_after_minutes <= now` |
| 5 | `lottery:settle-draws` | result published, `result_published_at + settlement_delay_minutes <= now` |

Plus one operator-only command that is **never scheduled**: `lottery:publish-result`.

### `app/Services/Draw/DrawScheduleService.php`

Owns the calendar arithmetic and nothing else — occurrence generation, betting window,
deterministic draw numbers (`DR-YYYYMMDD-HHMM`), idempotent provisioning, and the "which
draws are due" queries. It never writes `draws.status` (that stays with
`DrawLifecycleService`), never publishes a result and never touches money; `audit()` states
this and a test asserts it.

Details that matter:

- **Deterministic identity.** A draw number is derived from the draw moment in UTC, so
  provisioning is idempotent and two differently configured servers cannot create duplicates.
  A unique-violation (SQLSTATE 23000/23505) is treated as "already provisioned", not an error.
- **A day the month does not have is skipped, not rolled forward.** A calendar containing
  day 31 produces no February draw; Carbon's default would have drawn on 3 March.
- **Market timezone in, app timezone stored.** 15:00 Asia/Bangkok is persisted as 08:00 UTC.
- **Soft-deleted draws count as existing**, so a draw an operator deleted is not silently
  recreated on the next tick.
- **Late provisioning cannot mislead players.** A draw created after its own cut-off is
  never opened, because the bet validator would reject every bet against it anyway.

### `app/Console/Commands/Lottery/` (7 commands + 1 base class)

`LotteryAutomationCommand` gives all of them the same operational behaviour:

- **Kill switch** — with `lottery.automation.enabled` false, every command refuses unless
  `--force`. `lottery.closing.auto_close` and `lottery.automation.auto_settle` are separate,
  finer switches.
- **`--dry-run`** — reports the work and writes nothing.
- **Bounded batches** — `automation.batch_size` (default 50) per run, floored at 1, so a
  backlog cannot overrun the next tick.
- **Failure isolation** — a per-draw try/catch: one bad draw is logged (`lottery.automation.*`)
  and skipped, the batch finishes, and the exit code is non-zero so the scheduler notices.
- **No borrowed transaction** — settlement asserts it is not already inside one, which is
  what `DrawSettlementSimulationService` requires.

## 3. What is deliberately not automated

- **Result publication.** The winning numbers come from the Thai Government Lottery Office,
  outside this system. `lottery:publish-result` requires an operator, prints the numbers for
  confirmation before writing, and states that publication is irreversible.
  `ScheduleRegistrationTest` fails if `lottery:publish-result` ever appears in the schedule.
- **Anything financial.** No scheduled task writes `payouts`, `deposits`, `withdrawals`,
  `payments`, `financial_transactions` or `ledger_entries` — asserted both from the schedule
  definition and by counting rows after a tick. Settlement remains the Phase 5.1
  **simulation** (`mode = 'simulation'`); Phase 5.2 real payouts are still unbuilt.
- **Queues and notifications.** A queue connection and mail transport are still configured
  and unused; commands run synchronously inside the tick.
- **Supervisor/systemd config.** Not in the repository; the README documents the required
  cron line.

## 4. Tests added

| Suite | Tests | Covers |
|---|---|---|
| `tests/Unit/Services/Draw/DrawScheduleServiceTest.php` | 16 | Calendar generation, skipped impossible days, inclusive boundaries, malformed time/prefix/cron fallbacks, timezone conversion, cut-off + grace seconds, betting-window continuity and fallback, deterministic draw numbers, config switches |
| `tests/Feature/Console/DrawAutomationTest.php` | 28 | Provisioning (idempotent, dry-run, soft-deleted not recreated), open/close/pending/settle due-logic and negatives, late-provisioned draw not opened, `auto_close` and `auto_settle` switches with `--force`, settlement delay + operator override + idempotent replay, failure isolation with non-zero exit, batch cap, kill switch, a full tick, "a tick never publishes and never moves money", and the publication command's happy path plus four refusals |
| `tests/Feature/Console/ScheduleRegistrationTest.php` | 7 | The tick is scheduled exactly once, on the configured expression, non-overlapping/one-server/background, with a readable log trace; publication is never scheduled; no scheduled task touches money; all 7 commands are registered |

## 5. Verification

```
$ vendor/bin/phpunit
  Tests: 315, Assertions: 91174, Skipped: 5, Failures: 0
  Time: 02:24.152

$ DB_CONNECTION=sqlite CACHE_STORE=file php artisan schedule:list
  * * * * *  php artisan lottery:tick .......... Next Due: 14 seconds from now

$ php artisan lottery:tick          # against a live SQLite database
  lottery:schedule-draws  Provisioned 6 new draw(s); 0 already existed. Horizon 90 day(s).
  lottery:open-draws      ✓ DR-20260901-1500 -> open
  lottery:close-draws     ✓ DR-TEST-0001 -> closed
  lottery:mark-results-pending  ✓ DR-TEST-0001 -> awaiting official numbers
  lottery:settle-draws    ✓ DR-TEST-0001 -> settled: simulated 0.00 THB
  Tick complete in 30ms; all 5 step(s) succeeded.
```

Test count before this phase: 264. After: **315** (+51). No previously passing test changed
behaviour; the 5 skips are the same 5 documented in `AUDIT-2026-08-31.md` §5.

## 6. Effect on the measured gap

| Module | Before | After |
|---|---|---|
| 5 — Draw, result, settlement | 50% | **60%** |
| 10 — Security, testing, automation, deployment | 45% | **70%** |
| **Overall system** | **≈50%** | **≈53%** |

The next largest gap is unchanged: the admin panel (Phase 6), still 0%.
