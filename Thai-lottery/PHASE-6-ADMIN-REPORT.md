# PHASE 6 — ADMIN PANEL

**Built on:** PHP 8.3.28 · Laravel 11.56.1 · Filament 3.3.55 · Livewire 3.8.7 · PHPUnit 11.5.56 · SQLite 3 (file-backed `thai_lottery_test`)

This phase closed the largest single gap the audit measured. Before it, `AUDIT-2026-08-31.md`
§3.2 read "Admin: 0% — nothing exists": every operator action the domain supported —
approving a deposit, approving a withdrawal, setting a number limit, publishing an official
result, reading the audit trail — was reachable only by writing PHP. Five roles and
eighteen permissions were seeded into the database with no surface to act on.

There is now an operations panel at `/admin` with 13 resources, a dashboard, and 166 tests
covering it.

---

## 1. What was built

### 1.1 The authorization and formatting surfaces

| File | Purpose |
|---|---|
| `app/Support/Admin/AdminAccess.php` | The single authorization surface. Declares `PANEL_ROLES` and the eighteen permission-phrase constants, and answers `canAccessPanel`, `allows`, `allowsAny`, `current`, `currentAny`, `isReadOnly`. Nothing in the panel asks a question about permissions any other way. |
| `app/Support/Admin/AdminFormat.php` | The single formatting surface. `money()`, `count()`, `marketTime()`, `timezoneLabel()`, `currency()`, `number()`. Every amount rendered anywhere in the panel goes through `money()`, which is string/bcmath-safe. |
| `app/Support/Admin/OperatorActionFactory.php` | Shared spec-to-action builder used by the finance resources: turns one declarative action spec into either a table row action or a page header action, wrapping the service call in the try/catch → red persistent notification pattern. Deliberately placed outside `app/Filament` so panel discovery never mistakes it for a resource. |

### 1.2 The panel itself

| File | Purpose |
|---|---|
| `app/Providers/Filament/AdminPanelProvider.php` | Panel id `admin`, path `/admin`, `->login()`, brand "Thai Lottery — Operations", Amber primary, full-width. Declares the five navigation groups and discovers resources, pages and widgets. |
| `app/Models/User.php` | Now `implements FilamentUser`; `canAccessPanel(Panel $panel)` delegates to `AdminAccess::canAccessPanel()`. This is the only change made to a model in this phase. |
| `app/Filament/Pages/OperationsDashboard.php` | The two-column landing page listing the four widgets. |
| `app/Filament/Widgets/PlatformStatsWidget.php` | Headline counts: users, draws, bets, wallets. |
| `app/Filament/Widgets/PendingApprovalsWidget.php` | Deposits and withdrawals awaiting a decision, linking into `DepositResource` / `WithdrawalResource`. |
| `app/Filament/Widgets/DrawPipelineWidget.php` | Draws by lifecycle state, with the "awaiting numbers" count as the alarm figure. |
| `app/Filament/Widgets/LedgerBalanceWidget.php` | Sums debits and credits with bcmath and reports whether the books balance. |

### 1.3 Resources

Thirteen resources across five navigation groups. Each has its own `Pages/` directory; the
ones with operator decisions also have a co-located actions class.

**Lottery**

| Resource | Shape |
|---|---|
| `DrawResource` (+ `DrawLifecycleActions`, 4 pages, 2 relation managers) | The exemplar. Full CRUD on the four fields the lifecycle service declares modifiable, plus the five lifecycle decisions: open, close, publish official numbers, settle, cancel. List screen has status tabs and defaults to "Awaiting numbers" when any draw is in that state. Relation managers for bets and number limits. |
| `BetResource` (2 pages) | Strictly read-only. A bet is an already-paid, already-ledgered commitment; there is no create, edit, delete or state action. Rich filters, and an infolist showing each selection with its simulated prize. |
| `TicketResource` (2 pages) | Strictly read-only. Its counters are cached sums, so the list shows cached against live bet count side by side and flags divergence rather than silently repairing it. |

**Risk**

| Resource | Shape |
|---|---|
| `NumberLimitResource` (3 pages) | The only genuine CRUD resource besides draws, because a number limit is configuration rather than a record of something that happened. The form writes only the six engine-input fields; `current_amount`, `current_payout_exposure`, `status` and `exceeded_at` are engine-owned and doubly guarded. Deletion is permitted only when nothing has been reserved against the limit. |

**Finance**

| Resource | Shape |
|---|---|
| `DepositResource` (+ `DepositDecisionActions`, 2 pages) | Read-only fields, four decisions — approve (optional note), reject (reason required), mark processing, complete — each delegating to `DepositApprovalService` / `DepositCompletionService` and visible only when that service's own `canApprove`/`canReject`/`canComplete` returns true. Shows the decision trail. |
| `WithdrawalResource` (+ `WithdrawalDecisionActions`, 2 pages) | The same shape plus `markFailed` (reason required). |
| `WalletResource` (+ `WalletControlActions`, 2 pages) | List and view only. Actions: lock wallet (reason required), unlock, and a balance adjustment that calls `WalletService::credit`/`debit` with the amount validated as a decimal string by regex, never as a number. |
| `FinancialTransactionResource` (2 pages) | Strictly read-only history. No create, edit, delete or action of any kind. |
| `LedgerAccountResource` (1 page) | Strictly read-only chart of accounts with each account's balance computed by aggregate query, not per row in PHP. |

**People**

| Resource | Shape |
|---|---|
| `UserResource` (+ `UserAccountActions`, 3 pages, 2 relation managers) | Profile-field editing only. Status changes are explicit actions, not a form select. Role assignment is guarded so only a super-admin may grant or revoke super-admin, and nobody may strip their own super-admin. `canDelete` is false: users own financial history. Read-only relation managers for wallets and bets. |
| `AgentResource` (2 pages) | Read-only, because no service owns agent commission. Commission columns are gated behind `VIEW_COMMISSIONS`. |

**Compliance**

| Resource | Shape |
|---|---|
| `AuditLogResource` (2 pages) | Strictly read-only, with `canEdit`, `canDelete` and `canDeleteAny` false and a test proving it holds for super-admin, admin and auditor alike. An audit log an administrator can edit is not an audit log. |

### 1.4 Tests

166 new tests, all passing:

| File | Tests |
|---|---|
| `tests/Feature/Filament/AdminPanelAccessTest.php` | 11 |
| `tests/Feature/Filament/DrawResourceTest.php` | 16 |
| `tests/Feature/Filament/OperationsDashboardTest.php` | 5 |
| `tests/Feature/Filament/FinanceResourcesTest.php` | 47 |
| `tests/Feature/Filament/IdentityResourcesTest.php` | 47 |
| `tests/Feature/Filament/LotteryOpsResourcesTest.php` | 33 |
| `tests/Feature/Seeders/LedgerAccountSeederTest.php` | 7 |

Totals: 53 PHP files under `app/Filament/`, 3 under `app/Support/Admin/`, 30 of the
application's 48 routes belong to the panel.

---

## 2. The central decision: the panel never writes state a service owns

No resource in this panel assigns a status, moves money, or writes a ledger row. There is
no `$record->status = ...` and no `->update(['status' => ...])` behind any operator button.
Every action calls the domain service that already owns the transition, and then reports
what that service said.

The panel's only job in a state change is to decide **which buttons to offer**. That
decision is a cheap pre-filter, not a second implementation of the rule: `publish` is
offered when the draw is awaiting numbers, `settle` when a result is published, `approve`
when the approval service's own `canApprove()` returns true.

The consequence is the behaviour that matters on draw night with two operators on one draw.
The first operator acts. The second operator's screen is now stale and still shows the
button. They click it, the service refuses, and they get the service's own message as a red
persistent notification — and **nothing was written**. Every action body is wrapped in the
same try/catch for exactly this reason. A domain exception here is not a bug report; it is
the lifecycle guard doing its job.

`DrawLifecycleActions` and the three finance action classes exist because the same decision
has to render into two Filament types Filament does not unify — `Filament\Tables\Actions\Action`
on a list screen and `Filament\Actions\Action` on a view screen. Writing them twice would
mean two answers to "when may an operator publish a result?" and the copies would drift, so
the rule is declared once as a spec and rendered into whichever type the screen needs.

---

## 3. Authorization: one surface, and why not policies

Everything in the panel authorizes through `AdminAccess`, which reads the seeded permission
phrase catalogue. Every resource implements `canViewAny`/`canView`/`canCreate`/`canEdit`/
`canDelete` in terms of `AdminAccess::current(AdminAccess::SOME_CONSTANT)`. No resource
calls `hasRole()` directly and no new policy was written.

Panel entry requires three things, all in `AdminAccess::canAccessPanel()`: an Active
account, one of `super-admin` / `admin` / `auditor`, and the `view dashboard` permission.
Anything else is a 403, including a player who guesses the URL and a suspended admin.

**Why not the existing policies.** `App\Policies\BasePolicy` builds ability strings like
`draw.view`, while `config/permission.php` and `RolePermissionSeeder` seed phrases like
`view draws`. These two vocabularies never meet, so every policy built on `BasePolicy`
resolves false for every role except super-admin, which passes only because of the
`Gate::before` bypass in `AuthServiceProvider`. Building the panel on that layer would have
produced a panel where admins can see nothing and super-admins can see everything,
regardless of what the seeder says. This defect is **pre-existing and was not fixed here** —
repairing it means choosing which vocabulary is canonical and migrating the other, which is
its own piece of work with its own test surface. It is recorded in §7.

---

## 4. Money and the intl problem

`AdminFormat::money()` is the only way an amount reaches a screen. It takes a decimal
string and returns a decimal string. No `(float)`, `floatval()`, `round()` or
`number_format()` appears anywhere in the panel.

Filament's own `->money()` and `->numeric()` column and entry formatters are **banned
project-wide**, and so is `Illuminate\Support\Number`. Two independent reasons:

1. They route through `Illuminate\Support\Number`, which calls
   `ensureIntlExtensionIsInstalled()` and throws a hard `RuntimeException` when ext-intl is
   absent.
2. They cast the value to float on the way in. A ledger that is exact everywhere else does
   not become inexact at the last inch because a table column wanted a thousands separator.

### 4.1 Two Blade view overrides — and the obligation they create

Two upstream Filament views render *record counts* through `Illuminate\Support\Number::format`,
which meant every paginated table in the panel threw a 500 on a runtime without ext-intl.
A missing optional PHP extension should not be a fatal error on the deposits list. Both are
overridden:

| Override | Replaces |
|---|---|
| `resources/views/vendor/filament/components/pagination/index.blade.php` | `vendor/filament/support/resources/views/components/pagination/index.blade.php` |
| `resources/views/vendor/filament-tables/components/selection/indicator.blade.php` | `vendor/filament/tables/resources/views/components/selection/indicator.blade.php` |

Each is a verbatim copy with the `Number::format` calls swapped for `AdminFormat::count()`
and a header comment explaining why. The counts involved are small integers with no locale
semantics worth an extension dependency.

**This is a maintenance obligation, not a free win.** Both files are pinned to Filament 3.3.
When Filament is upgraded, they must be re-copied from `vendor/` and re-patched, or deleted
if the deployment target guarantees ext-intl and locale-aware grouping is wanted back. Both
files say so in their own header. They also do **not** make the panel intl-free in general —
they fix the two views that crashed. Production should enable ext-intl.

---

## 5. Defects found and fixed in this phase

### 5.1 `phpunit.xml` shipped an APP_KEY that was not a valid key (P0 for any HTTP test)

`phpunit.xml` set `APP_KEY` to `base64:2fl+KtvkVqh82XVZJp1xVg7Jp1xVg7Jp1xVg7Jp1xVg7Jp1xVg7Jp1xVg7Jp1xVg=`,
which does not decode to 32 bytes. Any test that touched the encrypter — which means any
HTTP test with a session — died with `RuntimeException: Unsupported cipher or incorrect key
length`.

*Why it had never been caught:* the pre-existing 315 tests were unit tests and console
tests. Not one of them made a session-bearing HTTP request, so the encrypter was never
resolved and the broken key was never read. The very first panel test found it immediately.

*Resolution:* replaced with a freshly generated valid 32-byte key. No other change; this
was a one-line config defect with a large blast radius the moment any HTTP feature test is
added.

### 5.2 The chart of accounts was never seeded (P0 — a fresh install could not take a deposit)

`WalletService` declares eight ledger account codes as constants:

| Code | Account |
|---|---|
| 1000 | System Cash |
| 1100 | Withdrawal Clearing |
| 2000 | Player Balances |
| 3000 | Manual Adjustments |
| 4000 | Bet Revenue |
| 4100 | Fee Revenue |
| 5000 | Prize Expense |
| 5100 | Agent Commission |

`LedgerPostingService::resolveAccount()` throws `ledger_account_missing` when a code has no
row, and its own error message reads *"Seed the chart of accounts before posting financial
transactions."* No seeder existed. `DatabaseSeeder` called only `RolePermissionSeeder`, and
no migration inserted the rows.

*Consequence:* a fresh install could migrate cleanly, seed roles, register a player, and
then fail on the first deposit with what looks to the operator like an internal error. The
eight codes existed as PHP constants and never as data.

*Resolution:* `database/seeders/LedgerAccountSeeder.php`, registered in `DatabaseSeeder`.
Three properties matter:

- It is **keyed on the `WalletService::ACCOUNT_*` constants**, not on literal strings, so
  the codes the application posts to and the codes the database holds are generated from
  the same source and cannot drift.
- It is **idempotent** (`updateOrCreate` on the uniquely-indexed `code`) and deliberately
  never writes `opening_balance` or `current_balance` on an existing row. Those are
  posting-derived state owned by `LedgerPostingService`; a seeder that reset them would
  silently destroy the audit position of a live ledger. There is a test for that.
- The contract test `tests/Feature/Seeders/LedgerAccountSeederTest.php` reads the constants
  **by reflection** rather than from a hand-written list of eight strings. A hand-written
  list would pass forever after someone added a ninth account code and forgot the seeder,
  which is precisely the failure the test exists to catch.

### 5.3 Panel entry admitted unverified operators (P1)

`AdminAccess::canAccessPanel()` originally gated on `UserStatus::canLogin()`, which admits
`PendingVerification` as well as `Active`. That is right for a player browsing the product
and wrong for an operator: nobody should publish an official result or approve a withdrawal
from an account whose email address has never been confirmed to belong to them. Tightened
to `canTransact()`, which means Active only. Two tests cover it.

### 5.4 Navigation groups and items both carried icons (P2)

Filament refuses to render a sidebar where a navigation group and its items both have
icons, and throws during view rendering rather than at boot — so this surfaced as a failing
page render, not a configuration error. The group icons were removed; resource items keep
theirs.

---

## 6. Gaps found and deliberately not filled

Each of these is a real hole. None was papered over in the panel, because a panel that
implements domain logic the domain does not have is worse than a panel that admits the
feature is missing.

| Gap | Consequence | Recommended fix |
|---|---|---|
| **No service owns user status transitions.** `app/Services` contains only `Betting/`, `Draw/`, `Finance/`, `Risk/`, and `UserStatus` has no transition graph. | This is the **one place** in the panel that writes domain state directly: `UserAccountActions::applyStatus()`. It is commented as such and holds the transition map, the self-escalation and super-admin guards, a `refresh()` before deciding, and the audit write. It is honest, tested, and in the wrong layer. | `App\Services\Identity\UserModerationService`, with the transition map moved onto `UserStatus`. |
| **`AdminAccess::audit()` is not an audit writer.** It is a zero-argument static that returns a description of the authorization model. | Panel changes are written to `App\Models\AuditLog` directly, with that description embedded in `metadata.authorization_model`. Audit writing is therefore scattered across action classes rather than centralised. | A real `AuditRecorder` service; then make every panel action route through it. |
| **No agent or commission service.** | `AgentResource` is entirely read-only, with no edit page registered. Commission is displayed as stored and never recomputed. | Phase 9 work; the panel is ready to grow actions once a service exists. |
| **No `view bets` or `view tickets` permission is seeded.** | Bet and ticket viewing is gated on `currentAny([VIEW_DRAWS, VIEW_TRANSACTION_HISTORY])`. This produces an odd result: an auditor **can** read bets and tickets but **cannot** read draws. That fell out of the seeded permission matrix; it is not a product decision anyone made. | Add the two permissions to `config/permission.php` and `RolePermissionSeeder`, then narrow the gates. |
| **Auditors cannot see number limits at all**, holding neither risk permission. | Risk configuration is not auditable by the role whose job is auditing. | A seeder change, if risk should be auditable. |
| **`manage system settings` still has no surface.** | The permission is seeded and grants nothing; `super-admin` is the only role that holds it. | A settings page, which is the main reason module 6 is scored at 70% rather than higher. |
| **No reversal path.** A confirmed deposit and a completed withdrawal are terminal from the panel. | An operator who completes a withdrawal in error has no remedy inside the product. | A compensating-entry service — never an edit or a delete of the original row. |
| **Neither approval service exposes `canMarkProcessing()`.** | The panel replicates that one precondition instead of asking the service, which is a small violation of §2 that could drift. | Add the method to `DepositApprovalService` and `WithdrawalApprovalService`. |
| **`BetType::digits()` disagrees with config.** It returns 2 for both `Tod` and `Run`; `config/lottery.php` says 3 and 1. `NumberNormalizationService` documents the disagreement and treats config as authoritative, and the engine normalises against config. | Number-limit forms build their validation regex from `NumberNormalizationService::digitsFor()` rather than the enum. Any code that trusts the enum is wrong for two of four bet types. | Repair the enum to agree with config, then re-point the forms at it. This is a real inconsistency in the domain, not a panel workaround. |

### 6.1 Reported figures

The per-resource test counts in §1.4 for `FinanceResourcesTest`, `IdentityResourcesTest`
and `LotteryOpsResourcesTest` are as reported by the agents that wrote them; the **suite
totals in §8 were measured directly** and are the numbers to trust. All three files were
re-run together in the final full-suite pass.

---

## 7. Pre-existing defect documented, not fixed

`App\Policies\BasePolicy` builds abilities like `draw.view` while the seeder and
`config/permission.php` seed phrases like `view draws`. Every policy derived from it —
Wallet, LedgerEntry, Bet, Ticket, Draw, Payment, Agent — therefore returns false for every
role except super-admin, which passes only through the `Gate::before` bypass in
`AuthServiceProvider`.

This is why the panel authorizes on the phrase catalogue through `AdminAccess` instead
(§3). Repairing it requires deciding which vocabulary is canonical, migrating the other,
and re-testing every policy — worth doing, and not something to attach to a panel phase.

---

## 8. Verification

Full suite, run serially against a freshly migrated `database/thai_lottery_test`:

```
$ export PATH=$HOME/bin:$PATH
$ php vendor/bin/phpunit

  ...............................................................  483 / 483 (100%)

  Time: 05:07.316, Memory: 103.00 MB
  OK, but some tests were skipped!
  Tests: 483, Assertions: 92188, Skipped: 5.
```

Before this phase: 315 tests, 91,174 assertions. **+168 tests, +1,014 assertions, 0
failures.**

```
$ find app config database routes tests bootstrap public -name '*.php' | xargs -n1 php -l
  (338 files, no syntax errors detected)

$ php artisan route:list
  Showing [48] routes — 30 of them the admin panel's
```

The 5 skips are the same 5 legitimate skips as before: 1 requires PHP 8.4 (`Pdo\Mysql`) and
4 are multi-process concurrency probes needing a server-based database.

**A note on parallel test runs.** Three agents built resources concurrently and repeatedly
corrupted the shared SQLite file (`database is locked`, `database disk image is malformed`)
by running phpunit against it at the same time. The suite is not safe to run concurrently
against one SQLite file. The figures above come from a single serial run against a freshly
created database file.

### 8.1 Dependency advisories

`composer audit` reports 3 `laravel/framework` advisories — CRLF injection in the email
validation rule, and temporary signed URL path confusion — because the project pins
`laravel/framework ^11`. This phase did not change the constraint. It is a dependency-policy
decision for the owner: either accept the advisories on the 11.x line or plan the move to
12.x.

---

## 9. What an operator still cannot do from this panel

- Change any system setting. `manage system settings` has no surface.
- Run or export a financial report. `view financial reports` grants read access to
  transaction and ledger lists, not a report.
- Reconcile the ledger as a workflow. `LedgerBalanceWidget` states whether the books
  balance; there is no tool for finding out why they do not.
- Reverse a confirmed deposit or a completed withdrawal.
- Pay a real prize. Settlement is a simulation by design — no wallet is credited, no
  `payouts` row is written, no ledger entry is posted. That is Phase 5.2 and is not built.
- Manage agent commission, or pay a commission.
- Suspend an individual number. `LimitStatus::Suspended` exists; no service performs the
  transition.
- Cancel or refund a bet, or cancel or expire a ticket. No services exist.
- Impersonate a user, or use panel-side two-factor authentication.
- Act in bulk. Bulk actions are disabled everywhere; every financial decision is one
  record at a time and on purpose.
