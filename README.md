# Thai Lottery — Real Online Lottery System

Production-grade Laravel 11 application (PHP 8.2+).

## Build phases

| Phase | Scope | State |
|-------|-------|-------|
| 1 | Core architecture (config, enums, models, schema, auth scaffolding) | complete |
| 2 | Wallet + double-entry financial ledger | domain complete; operator surface in the admin panel, no player HTTP surface |
| 3 | Risk + number limits | domain complete; operator surface in the admin panel |
| 4 | 3D / 2D / Tod / Run betting engine | complete (single-selection purchase API) |
| 4.4 | First HTTP API surface (`/api/v1`) | complete |
| 4.5 | Token surface (`/api/v1/auth/*`) | complete |
| 5.1 | Draw lifecycle + result publication + **non-monetary** settlement simulation | complete |
| 5.2 | Real-money payout settlement (`payouts`, wallet credit, ledger posting) | **not started** |
| 6 | Filament enterprise admin (`/admin`, 13 resources) | operations panel complete; no settings page, no reporting |
| 7 | Responsive frontend (Blade views, web routes) | asset stubs only |
| 8 | Reverb / real-time live result feed | **not started** |
| 9 | Payment gateways + agent system | config only, no driver |
| 10 | Security + testing + deployment | tests + draw automation done, no CI/deploy |

See `AUDIT-2026-08-31.md` for the measured per-module completion figures.

## Draw automation

The draw lifecycle runs itself. `bootstrap/app.php` schedules a single orchestrator,
`lottery:tick`, which performs five steps in order every minute:

| Step | Command | What it does |
|------|---------|--------------|
| 1 | `lottery:schedule-draws` | Creates the draws `config('lottery.draw.official_schedule')` declares, out to the horizon, with their betting window |
| 2 | `lottery:open-draws` | Opens a scheduled draw once its betting window has started (and never one whose cut-off already passed) |
| 3 | `lottery:close-draws` | Closes an open draw at `scheduled_at - config('lottery.closing.minutes_before_draw')` |
| 4 | `lottery:mark-results-pending` | Moves a closed draw to *awaiting official numbers* once its draw moment has passed |
| 5 | `lottery:settle-draws` | Runs the non-monetary settlement simulation `config('lottery.automation.settlement_delay_minutes')` after a result was published |

Every command also runs standalone, takes `--dry-run` (report only, write nothing) and
`--force` (ignore the kill switch), is bounded by `config('lottery.automation.batch_size')`
per run, and survives a bad draw: one failure is logged and skipped, the batch continues,
and the exit code is non-zero.

**Result publication is deliberately not automated.** The official numbers come from the
Thai Government Lottery Office, outside this system. A human enters them:

```bash
php artisan lottery:publish-result DR-20260901-1500 --first-prize=456123 --bottom-two=45
```

Nothing scheduled writes a result, and nothing scheduled touches money — `tests/Feature/Console/ScheduleRegistrationTest.php`
asserts both as a tripwire against a future change that "automates the last manual step".

### Enabling it on a server

The scheduler needs one system cron entry, or a long-running worker in development:

```bash
# production: crontab -e
* * * * * cd /path/to/thai-lottery && php artisan schedule:run >> /dev/null 2>&1

# development
php artisan schedule:work
```

Tick output is appended to `storage/logs/lottery-tick.log`; `php artisan schedule:list`
shows the registered task. The task uses `withoutOverlapping()` and `onOneServer()`, both
of which need a cache store that supports locks (`database` and `redis` do).

### Automation settings

| Env var | Config | Default | Meaning |
|---------|--------|---------|---------|
| `LOTTERY_AUTOMATION_ENABLED` | `lottery.automation.enabled` | `true` | Master kill switch. When false, nothing is scheduled and every command refuses without `--force` |
| `LOTTERY_TICK_CRON` | `lottery.automation.tick_cron` | `* * * * *` | Tick cadence |
| `LOTTERY_AUTO_SETTLE` | `lottery.automation.auto_settle` | `true` | Unattended settlement. Independent of the Phase 5.2 real-payout switch |
| `LOTTERY_AUTO_CLOSE` | `lottery.closing.auto_close` | `true` | Unattended closing |
| `LOTTERY_DEFAULT_DRAW_TYPE` | `lottery.draw.default_type` | `3d` | Type given to provisioned draws |
| — | `lottery.automation.horizon_days` | `90` | How far ahead draws are provisioned |
| — | `lottery.automation.settlement_delay_minutes` | `15` | Grace period after publication, so a mistyped result can still be caught |
| — | `lottery.automation.batch_size` | `50` | Maximum draws advanced per command per run |

`app/Services/Draw/DrawScheduleService.php` owns the calendar arithmetic only: it never
writes `draws.status` (that stays with `DrawLifecycleService`), never publishes, and never
moves money.

## Admin panel

An operations panel built on Filament 3, served at **`/admin`**.

### Who can get in

Three roles may enter: `super-admin`, `admin` and `auditor`. Every other role — including
`player` and `agent` — gets a 403, and so does any account that is not `Active`, so a
suspended or unverified operator cannot reach it. The rule lives in exactly one place,
`App\Support\Admin\AdminAccess::canAccessPanel()`, reached through
`User::canAccessPanel()`.

Authorization inside the panel uses the seeded permission phrases (`view draws`,
`manage draws`, `process settlements`, `view audit logs`, …), not the model policies. The
policies in `app/Policies` are built on `BasePolicy`, which composes ability strings in a
different vocabulary (`draw.view`) from the one the seeder creates, so they currently pass
only for `super-admin`. See `PHASE-6-ADMIN-REPORT.md` §3 and §7.

### What is in it

| Group | Resources |
|---|---|
| Lottery | Draws (full lifecycle), Bets (read-only), Tickets (read-only) |
| Finance | Deposits, Withdrawals, Wallets, Financial transactions (read-only), Ledger accounts (read-only) |
| Risk | Number limits (full CRUD) |
| People | Users, Agents (read-only) |
| Compliance | Audit logs (read-only, and not deletable by anyone) |

The landing page is an operations dashboard with four widgets: platform counts, pending
deposit/withdrawal approvals, the draw pipeline, and a bcmath debit-versus-credit balance
check on the ledger.

### How operator actions behave

The panel never writes state a domain service owns. Publishing a result calls
`DrawResultPublicationService`, settling calls `DrawSettlementSimulationService`, approving
a deposit calls `DepositApprovalService`, and so on. The panel only decides which buttons to
offer.

So if two operators have the same draw open and the first one acts, the second one's button
is stale. They click it, the service refuses, and they see the service's own message as a
red notification — and nothing is written. That is normal operation, not an error to report.

Result publication is deliberately manual: the official numbers come from the Thai
Government Lottery Office and a human enters them. Nothing schedules it.

### Assets

Filament's compiled CSS and JS are already published to `public/css/filament/` and
`public/js/filament/`, so the panel renders on a fresh clone **without** running
`npm run build`. Vite is still the route for changing the panel theme; rebuild after editing
the theme file.

### Two pinned Blade overrides — read before upgrading Filament

Two upstream Filament views render record counts through `Illuminate\Support\Number::format`,
which hard-requires the `intl` extension and throws a `RuntimeException` without it, turning
a missing optional extension into a 500 on every paginated table. Both are overridden with
`AdminFormat::count()`:

```
resources/views/vendor/filament/components/pagination/index.blade.php
resources/views/vendor/filament-tables/components/selection/indicator.blade.php
```

Both are verbatim copies of the Filament **3.3** originals with only those calls changed,
and each carries a header comment saying so. **When you upgrade Filament, re-copy them from
`vendor/` and re-apply the change, or delete them** if your deployment target guarantees
ext-intl and you want upstream's locale-aware grouping back. Leaving a stale override in
place will silently pin old markup.

> **Sandbox deviation — production must enable ext-intl.** Filament was installed here with
> `composer require ... --ignore-platform-req=ext-intl` because the build PHP has no `intl`,
> and `symfony/polyfill-intl-icu` is installed. The two overrides above fix the two views
> that crashed; they are **not** a general substitute for the extension. Enable `ext-intl`
> in production.


## Local setup

```bash
composer install
cp .env.example .env          # required: `php artisan test` aborts without a .env file
php artisan key:generate
php artisan migrate --seed     # RolePermissionSeeder + LedgerAccountSeeder
npm install
npm run dev
```

`migrate --seed` runs `DatabaseSeeder`, which seeds two things a fresh install cannot work
without:

```bash
php artisan db:seed --class=RolePermissionSeeder   # the 5 roles and 18 permissions
php artisan db:seed --class=LedgerAccountSeeder    # the 8-account chart of accounts
```

The chart of accounts is **required before any financial movement**.
`LedgerPostingService::resolveAccount()` throws `ledger_account_missing` for a code that has
no row, so without it a fresh install migrates cleanly, registers a player, and then fails
on the very first deposit. Both seeders are idempotent, and `LedgerAccountSeeder` never
resets a balance that has already been posted, so re-running them on a live database is
safe.

To create your first operator, assign a panel role to a user and log in at `/admin`:

```bash
php artisan tinker
>>> App\Models\User::where('email', 'you@example.com')->first()->assignRole('super-admin');
```

`storage/framework/{cache,sessions,views,testing}`, `storage/logs` and `bootstrap/cache`
must exist and be writable. They are tracked with per-directory `.gitignore` files, so a
fresh clone or archive already contains them.

## Conventions

- Money is stored as `decimal(20,2)` / `decimal(24,2)` and computed with `bcmath` strings — never floats.
- All domain state uses backed PHP enums in `app/Enums`.
- The ledger is strictly double-entry (`ledger_entries.type` = debit | credit).
- Financial writes are idempotent via `financial_transactions.idempotency_key`.
- Audit records in `audit_logs` are append-only (no `updated_at`, no soft deletes).

## Test suites

The suites need no manual database preparation. `phpunit.xml` points at a file-backed
SQLite database named `database/thai_lottery_test`, and `Tests\TestCase` migrates it once
per process if it is empty. Nothing runs against a database whose name is not an
allow-listed test name.

To run against MySQL/MariaDB instead — which is what production uses — export the
connection first, and keep the `thai_lottery_test` database name:

```bash
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=thai_lottery_test \
       DB_USERNAME=lottery DB_PASSWORD=lottery
php artisan test
```

```bash
php artisan test                      # all suites
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Feature
vendor/bin/phpunit --testsuite=Integration
vendor/bin/phpunit --testsuite=Security
```
