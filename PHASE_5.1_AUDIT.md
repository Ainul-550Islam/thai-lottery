# PHASE 5.1 AUDIT — DRAW + RESULT + SIMULATION SETTLEMENT DOMAIN

Pre-implementation audit of the existing Thai-lottery Laravel 11 project.

**Nothing in this document is a plan that was already carried out.** This file was
written *before* any Phase 5.1 file was created or modified, exactly as required by
step 4 of the specification. Every measurement below was actually executed on the
project; every command and its output is reproduced.

**This phase is a NON-MONETARY LOTTERY SIMULATOR.** No real-money gambling, no
prize payout, no payment processing, no withdrawal, no crypto settlement and no
financial transfer is implemented, and section 11 lists exactly which tables are
therefore permanently off limits.

---

## 1. Phase 4.4 baseline verification

Executed before reading any domain file.

```
cd /home/user/workspace/proj/Thai-lottery
sudo service mariadb start
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
       DB_DATABASE=thai_lottery_test DB_USERNAME=lottery DB_PASSWORD=lottery
php artisan --version
composer validate --no-check-publish
php artisan migrate --pretend
php artisan test
```

Actual output:

```
Laravel Framework 11.56.1
./composer.json is valid
   INFO  Nothing to migrate.
  Tests:    118 deprecated, 3 passed (1572 assertions)
  Duration: 54.92s
```

| Baseline fact | Measured value |
|---|---|
| Laravel | 11.56.1 |
| PHP | 8.5.4 |
| Composer | 2.10.3 |
| PHPUnit | 11.5.56 |
| MariaDB | 11.8.6 |
| Migrations | 23, all applied, `Nothing to migrate` |
| Tests | 121 total, 1,572 assertions |
| Failures | 0 |
| Errors | 0 |

**On the 118 `deprecated` markers.** These are not failures. Stock
`config/database.php` references `PDO::MYSQL_ATTR_SSL_CA`, which PHP 8.5 deprecated,
so every test that opens a MySQL connection is flagged. This is pre-existing, was
present and reported identically at the end of Phase 4.3 and Phase 4.4, and
`config/database.php` is outside Phase 5.1 scope. The suite reports `0` failures,
which is the number that matters.

**Baseline is VERIFIED. Phase 5.1 starts from a green suite.**

---

## 2. Actual project structure

Read from disk, not assumed.

### 2.1 Migrations (23)

```
0001_01_01_000000_create_users_table.php
0001_01_01_000001_create_cache_table.php
0001_01_01_000002_create_jobs_table.php
2024_01_01_000100_create_permission_tables.php
2024_01_01_000200_create_personal_access_tokens_table.php
2024_01_02_000100_create_wallets_table.php
2024_01_02_000200_create_ledger_accounts_table.php
2024_01_02_000300_create_financial_transactions_table.php
2024_01_02_000400_create_ledger_entries_table.php
2024_01_03_000100_create_draws_table.php
2024_01_03_000200_create_draw_results_table.php
2024_01_03_000300_create_winning_numbers_table.php
2024_01_03_000400_create_number_limits_table.php
2024_01_03_000500_create_tickets_table.php
2024_01_03_000600_create_bets_table.php
2024_01_03_000700_create_bet_items_table.php
2024_01_03_000800_create_payouts_table.php
2024_01_04_000100_create_payments_table.php
2024_01_04_000200_create_deposits_table.php
2024_01_04_000300_create_withdrawals_table.php
2024_01_05_000100_create_agents_table.php
2024_01_05_000200_create_agent_commissions_table.php
2024_01_06_000100_create_audit_logs_table.php
```

### 2.2 Models (19)

`Agent`, `AgentCommission`, `AuditLog`, `Bet`, `BetItem`, `Deposit`, `Draw`,
`DrawResult`, `FinancialTransaction`, `LedgerAccount`, `LedgerEntry`, `NumberLimit`,
`Payment`, `Payout`, `Ticket`, `User`, `Wallet`, `WinningNumber`, `Withdrawal`.

### 2.3 Enums (38)

Relevant to this phase: `DrawStatus`, `DrawType`, `BetMarket`, `BetSide`,
`BetSelectionType`, `BetType`, `BetStatus`, `TicketStatus`, `MarketResultType`,
`PayoutStatus`, `BetValidationCode`, `AuditAction`, `RiskLevel`, `Currency`.

### 2.4 Services (54)

`app/Services/Betting` (23), `app/Services/Finance` (18), `app/Services/Risk` (13).
There is **no `app/Services/Draw` directory**. Phase 5.1 creates it; nothing is
displaced.

### 2.5 DTOs and value objects

`app/DTOs`: `BetCalculationResult`, `BetPurchaseContext`, `BetPurchaseData`,
`BetPurchaseResult`, `BetSelectionData`, `BetValidationResult`, `MarketMatchResult`,
`MarketRuleData`, `TodPermutationResult`.

`app/ValueObjects`: `BetAmount`, `LotteryNumber`, `PayoutMultiplier`.

### 2.6 Existing tests (10 files, 121 tests)

```
tests/TestCase.php
tests/Feature/Api/V1/ApiPurchaseTestCase.php
tests/Feature/Api/V1/BetAccessApiTest.php
tests/Feature/Api/V1/BetPurchaseApiTest.php
tests/Feature/Betting/BetPurchaseAtomicityTest.php
tests/Feature/HealthCheckTest.php
tests/Integration/MigrationSchemaTest.php
tests/Security/SecurityHeadersTest.php
tests/Unit/Api/ApplicationLayerSafetyTest.php
tests/Unit/EnumIntegrityTest.php
```

**No existing test file will be deleted, rewritten or weakened.**

### 2.7 Factories (3)

`DrawFactory`, `UserFactory`, `WalletFactory`. `DrawFactory` offers `open()` and
`closed()` states only. Phase 5.1 will **not** modify it — the new lifecycle states
are constructed inside the new Phase 5.1 test base class instead, so a Phase 1
factory stays untouched.

---

## 3. Schema capabilities — exactly what exists

Every column below was read from the migration file, not inferred.

### 3.1 `draws`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `draw_number` | varchar(64) **unique** | |
| `type` | varchar(16) indexed | cast `DrawType` |
| `status` | **varchar(32)** default `'scheduled'`, indexed | cast `DrawStatus` |
| `scheduled_at` | timestamp indexed | planned |
| `betting_open_at` | timestamp nullable | planned |
| `betting_close_at` | timestamp nullable indexed | planned |
| `opened_at` | timestamp nullable | **actual lifecycle event** |
| `closed_at` | timestamp nullable | **actual lifecycle event** |
| `drawn_at` | timestamp nullable | **actual lifecycle event** |
| `completed_at` | timestamp nullable | **actual lifecycle event** |
| `result_published_at` | timestamp nullable | **actual lifecycle event** |
| `total_bets` | unsignedBigInteger default 0 | engine-maintained counter |
| `total_amount_wagered` | decimal(24,2) default 0 | engine-maintained counter |
| `total_payout` | decimal(24,2) default 0 | engine-maintained counter |
| `house_profit` | decimal(24,2) default 0 | engine-maintained counter |
| `metadata` | json nullable | cast `array` |
| timestamps, softDeletes | | |

**Capability 1 — the five lifecycle timestamp columns already exist.** The
migration's own docblock says they are "written by the draw engine as they happen".
Phase 5.1 is that engine. No timestamp column has to be invented.

**Capability 2 — `status` is `varchar(32)`, not a MySQL `ENUM`.** A new status
string therefore needs **no migration**. This is decisive for section 6.

**Capability 3 — the migration explicitly states results are NOT stored here:**
"Results are NOT stored here. The normalised `winning_numbers` table is the single
source of truth for published numbers, and `draw_results` holds the official prize
structure." Phase 5.1 obeys this: it will **not** add a `winning_numbers` JSON
column to `draws`, as the specification also forbids (scope item C).

**Capability 4 — `Draw::$fillable` is `['draw_number','type','scheduled_at','metadata']` only.**
Status, every lifecycle timestamp and every counter are excluded from mass
assignment by design: "they are advanced by the draw service as the draw moves
through its states, never by mass assignment." Phase 5.1 will assign them
explicitly on the model instance, never via `fill()`/`update([...])` of request data.

### 3.2 `draw_results`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `draw_id` | FK → draws, `restrictOnDelete`, **`unique('draw_id')`** | |
| `first_prize` | **varchar(16)**, cast `'string'` | |
| `second_prize` | json nullable | |
| `third_prize` | json nullable | |
| `consolation_prizes` | json nullable | |
| `all_numbers` | json nullable | |
| `total_winners` | unsignedBigInteger default 0 | |
| `total_payout` | decimal(24,2) default 0 | |
| `house_profit` | decimal(24,2) default 0 | |
| `published_at` | timestamp nullable indexed | |
| `metadata` | json nullable, cast `array` | |
| timestamps, softDeletes | | |

**Capability 5 — `unique('draw_id')` is a database-level guarantee of at most one
result row per draw.** This is the primary defence for requirement A "prevent
duplicate result publication" and requirement E "no duplicate records". It is a real
constraint, not a cache and not an application check.

**Capability 6 — `first_prize` is a string column with a `'string'` cast.** A first
prize of `'100007'` survives verbatim; nothing pads or parses it. Leading zeros are
safe.

**Capability 7 — there is NO `bottom_two` column.** Confirmed by reading the
migration line by line. The project's own declared abstraction stores it in
`draw_results.metadata` under the key named by
`config('lottery.results.bottom_two_metadata_key')`, whose value is `'bottom_two'`.
`App\Enums\MarketResultType::isMetadataBacked()` and `storageDescription()` already
document this, and `App\Services\Betting\MarketResultResolver` already reads it.
**Phase 5.1 will therefore write the bottom number to that same metadata key and
will NOT invent a `bottom_two` column**, exactly as scope item C demands.

### 3.3 `winning_numbers`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `draw_id` | FK → draws, `restrictOnDelete` | |
| `bet_type` | varchar(16) indexed, cast `BetType` | `2d` / `3d` / `tod` / `run` |
| `number` | **varchar(16)**, cast `'string'` | leading zeros survive |
| `prize_tier` | varchar(32) nullable | |
| `position` | varchar(32) nullable | `top` / `bottom` |
| `payout_multiplier` | **unsignedInteger** default 0 | integral only |
| `total_winners` | unsignedBigInteger default 0 | settlement summary |
| `total_payout` | decimal(24,2) default 0 | settlement summary |
| `published_at` | timestamp nullable indexed | |
| `metadata` | json nullable, cast `array` | |
| timestamps, softDeletes | | |
| **unique** | `(draw_id, bet_type, number, prize_tier)` named `winning_numbers_unique` | |

**Capability 8 — the composite unique key is the second database-level duplicate
guard.** The migration docblock states its purpose outright: "The composite unique
key prevents the same result being published twice for a draw." `position` is
deliberately excluded because MySQL treats NULLs as distinct.

**Capability 9 — `number` is a string, so `'007'` is storable and distinct from
`'07'` and from `'7'`.**

**Capability 10 — `WinningNumber::$fillable` excludes `total_winners` and
`total_payout`**, which the model docblock calls "settlement summaries written once
by the settlement service". Phase 5.1 assigns them explicitly on the instance.

### 3.4 `bets`

Relevant columns: `status` varchar(32) (cast `BetStatus`), `stake_amount`
decimal(20,2), `potential_payout` decimal(20,2), `actual_payout` decimal(20,2)
default 0, `won_at` timestamp nullable, `payout_id` unsignedBigInteger nullable FK →
payouts, `metadata` json, `type` cast `BetType`, `idempotency_key` varchar(128)
**unique**.

MySQL/MariaDB CHECK constraints: `bets_stake_amount_positive` (`stake_amount > 0`),
`bets_actual_payout_non_negative` (`actual_payout >= 0`).

**Capability 11 — `Bet::$fillable` deliberately excludes `status`, `payout_id`,
`actual_payout`, `won_at` and the cancellation fields.** The model docblock: "these
are settlement results: they are written by the settlement service after the draw,
never from request input." **Phase 5.1 is the settlement service these columns were
designed for.**

### 3.5 `bet_items`

Relevant columns: `bet_id` FK, `number` **varchar(16)** cast `'string'`, `position`
varchar(32) nullable, `amount` decimal(20,2), `payout_multiplier`
**unsignedInteger** default 0, `potential_payout` decimal(20,2), **`is_winner`
boolean default false, indexed**, **`actual_payout` decimal(20,2) default 0**,
`metadata` json.

CHECK constraints: `bet_items_amount_positive`, `bet_items_actual_payout_non_negative`.

**Capability 12 — `is_winner` and `actual_payout` already exist and are already
excluded from `$fillable`** "so they can only be written by the settlement service".
These two columns are the per-selection settlement result store. Nothing needs to be
created.

**Capability 13 — the market key of a selection is already recorded.**
`App\Services\Betting\BetPurchaseItemService::metadataFor()` writes, for every item:

```php
'market'            => $context->marketKey,   // '3d_direct' | '3d_tod' | '2d_top' | '2d_bottom' | 'run_top' | 'run_bottom'
'bet_type'          => $context->betType->value,
'side'              => $context->side->value,
'selection_type'    => $context->selectionType->value,
'position'          => $context->position,
'stake'             => $context->stake->amount(),
'payout_multiplier' => $context->multiplier->value(),
'potential_payout'  => $context->potentialPayout->toString(),
// Tod only:
'covered_numbers'      => [...],   // unique permutations
'covered_number_count' => int,
'charges'              => 1,       // ONE charge, regardless of permutation count
```

So settlement can recover the exact market without guessing. A secondary,
independent derivation also exists and is used as a cross-check (section 8.2).

### 3.6 `payouts` — REAL MONEY, OUT OF BOUNDS

| Column | Type |
|---|---|
| `reference_number` | varchar(64) **unique** |
| `draw_id`, `user_id` | FK restrictOnDelete |
| `bet_id`, `ticket_id`, **`wallet_id`**, **`financial_transaction_id`** | FK nullOnDelete |
| `status` | varchar(32) default `pending` |
| `amount` | decimal(20,2), CHECK `amount > 0` |
| `multiplier` | decimal(20,4) nullable, CHECK `>= 0` |
| `processed_at` | timestamp nullable |

The migration docblock is explicit: "The obligation to pay a winner, and the record
that it was paid", `processed_at` is "the moment the money actually moved", and
idempotency there exists so "crediting a winner" is safely repeatable.

**A `payouts` row is a real-money obligation carrying a `wallet_id` and a
`financial_transaction_id`. Phase 5.1 will NOT create, update or delete a single
`payouts` row.** Requirement G forbids exactly this. A second consequence: the
`payouts_amount_positive` CHECK means a *losing* selection could not be recorded
there anyway, so `payouts` is not even structurally a simulation log.

---

## 4. Existing Phase 4.2 rule engine — reused verbatim, never re-implemented

Requirement D says winners must be determined using **only** the verified Phase 4.2
market rules. Those rules already exist in full and Phase 5.1 calls them. No match
logic, digit rule, permutation rule or payout rate is re-derived.

| Existing component | What Phase 5.1 uses it for |
|---|---|
| `MarketRuleResolver::resolve($marketKey)` | the 7-field rule set: digits, match mode, result type, multiplier **source path**, pays-once |
| `MarketResultResolver::resolveForType($drawId, $type)` | reads the drawn value: `substr(first_prize,-3)`, `substr(first_prize,-2)`, `metadata['bottom_two']` |
| `ThreeDigitMatchService::match($sel,$win,'3d_direct')` | 3D Direct decision |
| `TodMatchService::match($sel,$win,'3d_tod')` | 3D Tod decision, unique permutations |
| `TwoDigitMatchService::match($sel,$win,'2d_top'\|'2d_bottom')` | 2D Top / 2D Bottom decision |
| `RunMatchService::match($sel,$win,'run_top'\|'run_bottom')` | Run Top / Run Bottom decision, `digit_contains` |
| `TodPermutationService::countFor($digits)` | unique permutation counts |
| `MarketPayoutService::multiplierFor($marketKey)` | the authoritative configured rate |
| `MarketPayoutService::payoutForMatch($stake,$match)` | the simulated prize, priced **once** |
| `MarketPayoutService::ratesExceedingBetItemColumn()` | refuse a rate the integer column cannot hold exactly |
| `LotteryNumberService::parseStrict($raw,$marketKey)` | strict digit-width validation, no padding |
| `BetCalculationService::calculateOnce()` | BCMath product, reached through `MarketPayoutService` |
| `App\Services\Finance\Money` | exact decimal accumulation of simulated totals |
| `PayoutMultiplier::toBetItemColumn()` | throws instead of truncating a fractional rate |

Verified guarantees these already carry, quoted from the code:

- `MarketRuleResolver::guarantees()`: `run_is_one_digit`, `run_no_permutation`,
  `tod_unique`, `pays_once`, `no_money` ("MarketRuleData carries a multiplier SOURCE
  PATH, never a rate").
- `MarketMatchResult::payoutCount()` returns **1 when matched, 0 when not** — "never
  the permutation count and never the occurrence count".
- `MarketResultResolver` guarantees `no_fake_value`: an absent or malformed bottom
  number throws `MarketResultUnavailableException`; "no default, zero or padded value
  is ever returned".

Consequence for requirement D: the Tod permutation counts (123→6, 112→3, 111→1,
007→3) and the "one selection is one ticket item, stake never multiplied" rule are
**already enforced** by `TodPermutationService` and `MarketMatchResult`. Phase 5.1
asserts them in tests rather than re-implementing them.

Consequence for the Run rule: `config('lottery.markets.run_top.payout_multiplier')`
is **3** and `run_bottom` is **4**, while the legacy family enum
`BetType::Run->payoutMultiplier()` returns **12**. `MarketRuleResolver::legacyDivergences()`
already reports this deliberately. Phase 5.1 reads the rate through
`MarketPayoutService` only, so the legacy 12 can never be used — and a test asserts
that the simulated Run prize is not derived from 12.

---

## 5. Schema limitations found

| # | Limitation | Consequence for Phase 5.1 | Migration needed? |
|---|---|---|---|
| L1 | No `bottom_two` column on `draw_results` | bottom number read from and written to `draw_results.metadata['bottom_two']`, the project's own declared abstraction | **No** |
| L2 | `winning_numbers.payout_multiplier` is `unsignedInteger` | a fractional rate cannot be stored exactly; publication is **refused** via `PayoutMultiplier::toBetItemColumn()` rather than rounded. All six configured rates (900, 45, 90, 90, 3, 4) are integral today | **No** |
| L3 | `bet_items.payout_multiplier` is `unsignedInteger` | identical treatment; `MarketPayoutService::ratesExceedingBetItemColumn()` already reports this | **No** |
| L4 | `draw_results.first_prize` is `varchar(16)` | a first prize longer than 16 characters is rejected by validation, not truncated | **No** |
| L5 | `winning_numbers.number` is `varchar(16)` | same, rejected not truncated | **No** |
| L6 | `winning_numbers` unique key excludes `position` | two rows for the same `(draw, bet_type, number, tier)` are impossible even on different sides, so the tier must distinguish them — see 8.1 | **No** |
| L7 | `payouts.amount` CHECK `> 0` | a losing selection is unrepresentable in `payouts`; irrelevant here since `payouts` is untouched | **No** |
| L8 | `DrawStatus` has no `result_published` case | **see section 6** | **No** (varchar column) |
| L9 | No dedicated `settlement_simulations` table exists | simulation output is stored in the settlement columns the schema already provides (`bet_items.is_winner`, `bet_items.actual_payout`, `bets.status`, `bets.actual_payout`, `winning_numbers.total_winners`/`total_payout`, `draw_results.total_winners`/`total_payout`, `draws.total_payout`) plus one `audit_logs` row. See 8.3 | **No** |
| L10 | `draws.status` is not a DB `ENUM` | a new status string is storable without DDL | **No** |

### SCHEMA CHANGE REQUIRED: none

**No migration is created in Phase 5.1.** Every requirement A–I is satisfiable with
the 23 existing migrations. `php artisan migrate --pretend` must still report
`Nothing to migrate` after implementation, and section 12 lists that as a mandatory
verification command.

---

## 6. CONFLICT — the one existing file that must change

```
CONFLICT:
FILE:
  app/Enums/DrawStatus.php  (Phase 1)

CURRENT BEHAVIOR:
  DrawStatus declares exactly six cases:
      Scheduled = 'scheduled'
      Open      = 'open'
      Closed    = 'closed'
      Drawing   = 'drawing'
      Completed = 'completed'
      Cancelled = 'cancelled'
  App\Models\Draw casts `status` to DrawStatus, so `draws.status` can only ever
  hold one of those six strings without the cast throwing on hydration.

REQUIRED BEHAVIOR:
  Phase 5.1 requirement A demands a SEVEN state lifecycle:
      Draft, Open, Closed, ResultPending, ResultPublished, Settled, Cancelled
  Six of the seven already exist under the project's own names:

      spec state        existing persisted DrawStatus
      ----------------  -----------------------------
      Draft          -> Scheduled   (config('lottery.draw.initial_status') === 'scheduled')
      Open           -> Open
      Closed         -> Closed
      ResultPending  -> Drawing     (the state between closed and published)
      ResultPublished-> *** MISSING ***
      Settled        -> Completed
      Cancelled      -> Cancelled

  ResultPublished is genuinely absent. It is a distinct, necessary state: a draw
  whose official result is published but whose selections have not yet been
  settled. Without it, "result published" and "settled" collapse into `Completed`
  and requirement A ("prevent duplicate result publication", "enforce valid state
  transitions") cannot be enforced, because there is no state to transition FROM
  when settlement runs.

WHY IT MATTERS:
  Collapsing publication and settlement into one state would mean the transition
  guard cannot distinguish "already published, not yet settled" from "already
  settled". Settlement idempotency (requirement E) would then have to rely on
  inspecting derived data rather than on an explicit state, and a second
  settlement run could not be refused deterministically. Encoding the missing
  state in `metadata` instead would store one fact in two places and leave
  `draws.status` lying about the draw.

SAFE FIX:
  Add ONE new case to App\Enums\DrawStatus:

      case ResultPublished = 'result_published';

  This change is purely ADDITIVE and requires NO migration, because
  `draws.status` is `varchar(32)` (Capability 2) and 'result_published' is 16
  characters.

  Two existing methods, label() and color(), use exhaustive `match ($this)`, so a
  new case without a new arm would raise \UnhandledMatchError. One arm is added to
  each. No existing arm is altered.

  NO existing case is renamed, no backing value is changed, no method is removed
  and no method signature is changed. The four existing behavioural methods keep
  returning exactly what they returned before for all six original cases:
      canAcceptBets() -> Open only            (ResultPublished: false)
      canClose()      -> Open, Scheduled      (ResultPublished: false)
      canDraw()       -> Closed only          (ResultPublished: false)
      isFinal()       -> Completed, Cancelled (ResultPublished: false)

  A regression test (`draw_status_enum_is_backward_compatible`) asserts the exact
  previous return value of all six original cases across all four methods plus
  label() and color(), so the additive claim is proved rather than asserted.

  Side effect, verified as safe: config('lottery.draw.statuses') is
  array_column(DrawStatus::cases(), 'value'), so the new value appears there
  automatically with no config edit.
```

**This is the ONLY existing file Phase 5.1 modifies.** No Phase 1, 2.1, 2.2, 3.1,
4.1, 4.2, 4.3 or 4.4 **service**, **model**, **migration**, **config**, **policy**,
**controller**, **request**, **resource** or **test** is touched. If the reviewer
rejects even this additive enum case, Phase 5.1 cannot deliver a `ResultPublished`
state and I would need a new instruction; I am not going to fake the state in
`metadata`.

### 6.1 The spec-named lifecycle, and why a second enum

The specification names its states `Draft`, `ResultPending`, `ResultPublished`,
`Settled`. The project persists `scheduled`, `drawing`, `result_published`,
`completed`. Rather than rename Phase 1 vocabulary — which would break
`DrawFactory`, `Draw::scopeScheduled()`, `Draw::scopeCompleted()`,
`Draw::isCompleted()`, `config('lottery.draw.initial_status')` and every existing
test — Phase 5.1 introduces a **new** enum `App\Enums\DrawLifecycleState` holding the
seven spec names, with a total bidirectional mapping onto `DrawStatus`. The
transition table lives there. Persistence stays `DrawStatus`. Nothing is duplicated:
`DrawLifecycleState` owns the *transition rules*, `DrawStatus` owns the *stored
value*, and `DrawLifecycleState::fromDrawStatus()` / `toDrawStatus()` are total
functions with no default branch, so a new `DrawStatus` case can never be silently
mapped to something plausible.

### 6.2 Transition table Phase 5.1 will enforce

```
Draft            -> Open, Cancelled
Open             -> Closed, Cancelled
Closed           -> ResultPending, Cancelled
ResultPending    -> ResultPublished, Cancelled
ResultPublished  -> Settled
Settled          -> (terminal)
Cancelled        -> (terminal)
```

Deliberate decisions, stated rather than buried:

- **`ResultPublished -> Cancelled` is refused.** Once the official numbers are
  public, cancelling the draw is a financial reversal, and requirement G forbids
  Phase 5.1 from doing anything reversal-shaped. Cancellation is allowed only up to
  and including `ResultPending`.
- **`Settled` and `Cancelled` are terminal.** No un-settle, no re-open. This also
  satisfies "prevent modification of a closed/published draw".
- **No `force`, `override`, `skip` or `bypass` parameter exists on any transition
  method.** Requirement H. The static audit in section 10 greps for these words and
  must report zero hits.
- `Draft -> Closed`, `Open -> ResultPublished`, `Closed -> Settled` and every other
  unlisted pair are invalid and throw.

### 6.3 "Prevent modification of a closed/published draw"

`config('lottery.results.lock_after_publication')` is already `true`. The guard is
implemented as an explicit assertion (`assertMutable()`) that refuses when the
lifecycle state is `Closed`, `ResultPending`, `ResultPublished`, `Settled` or
`Cancelled` — i.e. mutation of the draw's schedule/definition is permitted only in
`Draft` and `Open`. Lifecycle transitions themselves are not "modification" and go
through the transition guard instead.

---

## 7. Result validation plan (requirement B)

Inputs accepted: `first_prize` (string) and the bottom two number (string). Nothing
else is required; `second_prize`, `third_prize`, `consolation_prizes` and
`all_numbers` are optional and are stored verbatim as JSON if supplied.

Rules, all enforced on **strings**:

1. `first_prize` must match `/^[0-9]+$/`. A leading `+`, a space, `'1e3'`, `'007.0'`
   and `''` are all rejected.
2. `strlen($first_prize)` must equal `config('lottery.results.first_prize_digits')`,
   which is **6**. Not "at least 6". Not padded to 6.
3. `strlen($first_prize)` must fit `varchar(16)` (L4). 6 ≤ 16, checked anyway.
4. The **three-digit rule** required by requirement B: `substr($first_prize, -3)` is
   validated through the existing
   `LotteryNumberService::parseStrict($value, '3d_direct')`, which resolves the digit
   width from `config('lottery.markets.3d_direct.digits')` (= 3) and refuses anything
   else. This is what "according to the existing 3-digit rules" means in this
   codebase — the rule is not restated, it is invoked.
5. The two-digit top, `substr($first_prize, -2)`, is validated the same way through
   `parseStrict(..., '2d_top')`.
6. The **bottom two** is validated through the existing Phase 4.2 abstraction:
   `MarketResultType::TwoDigitBottom`, whose `isMetadataBacked()` is `true` and whose
   `digits()` is `2`; the value must match `/^[0-9]{2}$/` and is validated through
   `parseStrict(..., '2d_bottom')`. It is written to
   `draw_results.metadata[config('lottery.results.bottom_two_metadata_key')]`.
7. **If the bottom two is absent or malformed, publication is REFUSED** with a domain
   error. No zero, no `'00'`, no empty string, no value derived from the first prize,
   and nothing padded from an integer. `MarketResultResolver` already refuses to read
   an integer `7` as `'07'` — it describes it as "integer 7" and throws — and Phase
   5.1 refuses to *write* one for the same reason.

Forbidden throughout: `intval()`, `floatval()`, `(int)`, `(float)`, `(double)`,
`round()`, `floor()`, `ceil()`, `number_format()`, `+`, `-`, `*`, `/` on money.
Decimal arithmetic uses BCMath via `App\Services\Finance\Money` and
`BetCalculationService`. `Money::assertExactArithmeticIsAvailable()` already exists
and is called.

**`'007'` can never become integer `7`** because no digit string is ever passed
through an integer conversion: comparisons are `===` on strings, lengths are
`strlen()`/`mb_strlen()`, and slicing is `substr()`.

---

## 8. Persistence plan

### 8.1 Winning numbers — six rows, one per sellable market

The unique key is `(draw_id, bet_type, number, prize_tier)` and excludes `position`
(L6). `prize_tier` is therefore set to the **`MarketResultType` backing value**,
which is existing project vocabulary (`config('lottery.markets.*.source')` uses the
same three strings), not a new invented tier name:

| Market | `bet_type` | `number` written | `prize_tier` | `position` | `payout_multiplier` |
|---|---|---|---|---|---|
| `3d_direct` | `3d` | 3-digit top, e.g. `007` | `first_prize_last_three` | `top` | 900 |
| `3d_tod` | `tod` | 3-digit top, e.g. `007` | `first_prize_last_three` | `top` | 45 |
| `2d_top` | `2d` | 2-digit top, e.g. `07` | `first_prize_last_two` | `top` | 90 |
| `2d_bottom` | `2d` | bottom two, e.g. `07` | `bottom_two` | `bottom` | 90 |
| `run_top` | `run` | 3-digit top, e.g. `007` | `first_prize_last_three` | `top` | 3 |
| `run_bottom` | `run` | bottom two, e.g. `07` | `bottom_two` | `bottom` | 4 |

Collision analysis, because L6 makes this non-obvious. The six `(bet_type,
prize_tier)` pairs are `(3d,f3)`, `(tod,f3)`, `(2d,f2)`, `(2d,b2)`, `(run,f3)`,
`(run,b2)` — **all distinct**. So the six rows never violate the unique key, *even in
the worst case where the bottom two equals the last two digits of the first prize*
(e.g. first prize `100007`, bottom two `07`): rows 3 and 4 differ by `prize_tier`.
This is tested explicitly.

Two things stated plainly rather than glossed:

- **Run rows store the containing drawn value, not a one-digit winner.** Run is a
  one-digit selection tested for containment; there is no single winning digit for a
  Run market, so inventing one would be a fabrication. The row records the drawn
  value the market is decided against, and `metadata` on the row says so
  (`'match_mode' => 'digit_contains'`, `'selection_digits' => 1`). The 1-digit
  selection width is still enforced at match time by `RunMatchService` through
  `MarketRuleResolver::assertSelectionWidth()`.
- `payout_multiplier` is written via `PayoutMultiplier::toBetItemColumn()`, which
  **throws** on a fractional rate instead of truncating (L2). Publication is refused
  as a whole in that case; no row is written.

`published_at` is set on all six rows and on `draw_results`, inside the same
transaction.

### 8.2 Resolving the market of an existing bet item

Two independent sources, cross-checked, never guessed:

1. **Primary:** `bet_items.metadata['market']`, written at purchase time by
   `BetPurchaseItemService` (Capability 13), accepted only if
   `MarketRuleResolver::supports()` returns true for it.
2. **Fallback / cross-check:** derivation from `(bets.type, bet_items.position)` via
   `BetMarket`. The mapping is a bijection over the six markets:

   | `bets.type` | `bet_items.position` | market |
   |---|---|---|
   | `3d` | `top` | `3d_direct` |
   | `tod` | `top` | `3d_tod` |
   | `2d` | `top` | `2d_top` |
   | `2d` | `bottom` | `2d_bottom` |
   | `run` | `top` | `run_top` |
   | `run` | `bottom` | `run_bottom` |

If the metadata market and the derived market **disagree**, the selection is not
settled and the whole settlement run is refused with a domain error. If neither
resolves, same refusal. Nothing is defaulted, and no selection is quietly skipped.

### 8.3 Where the simulation result is stored (L9)

There is no `settlement_simulations` table, and Phase 5.1 does not create one,
because the schema already provides settlement columns that the models' own
docblocks reserve for "the settlement service". Requirement H asks the output to
show: ticket, selected number, market, winning status, configured multiplier,
calculated simulated prize amount, settlement status. Every one of those has a home:

| Required output field | Stored in | Returned in DTO |
|---|---|---|
| ticket | `bets.ticket_id` → `tickets.ticket_number` | `ticketNumber` |
| selected number | `bet_items.number` (string, zeros intact) | `selection` |
| market | `bet_items.metadata['market']` (already present) | `marketKey` |
| winning status | **`bet_items.is_winner`** | `matched` |
| configured multiplier | **`bet_items.payout_multiplier`** (re-read from config at settlement time and rewritten) | `multiplier` |
| calculated simulated prize | **`bet_items.actual_payout`** | `simulatedPrize` |
| settlement status | **`bets.status`** = `Won`/`Lost`, `bets.won_at` | `settlementStatus` |
| drawn value compared against | `bet_items.metadata['settlement']['winning_value']` | `winningValue` |
| aggregate winners / simulated total | `winning_numbers.total_winners`/`total_payout`, `draw_results.total_winners`/`total_payout`, `draws.total_payout` | totals on the aggregate DTO |
| audit trail | one `audit_logs` row, `action = AuditAction::Update`, `auditable = Draw` | — |

Every settled item additionally receives a
`bet_items.metadata['settlement']` block containing
`['mode' => 'simulation', 'monetary' => false, 'multiplier_source' => '<config path>', ...]`
so the row is self-documenting as non-monetary and the multiplier's provenance is
recorded, never the rate as a literal in code.

**`bets.payout_id` is left NULL, always.** Writing it would point at a real-money
`payouts` row. Requirement G.

`draws.house_profit` is written as `total_amount_wagered - total_payout` using
BCMath through `Money`, as a simulated figure. It is a cached aggregate on the draw,
not anybody's balance.

### 8.4 What is NEVER written — requirement G

| Table | Phase 5.1 access |
|---|---|
| `wallets` | **none** — no read for mutation, no write, no lock |
| `ledger_entries` | **none** |
| `ledger_accounts` | **none** |
| `financial_transactions` | **none** |
| `payouts` | **none** |
| `payments` | **none** |
| `deposits` | **none** |
| `withdrawals` | **none** |
| `agents`, `agent_commissions` | **none** |

No `App\Services\Finance\WalletService`, `WalletHoldService`, `WalletLockService`,
`LedgerPostingService`, `FinancialTransactionService`, `DepositService`,
`WithdrawalService`, `DepositCompletionService`, `WithdrawalCompletionService` or
`FinancialReversalService` is injected into, or called from, any Phase 5.1 class.
`App\Services\Finance\Money` **is** used — it is a pure BCMath value object with no
database access and no balance concept, and using it is how exact decimals are
guaranteed. The static audit (section 10) asserts the distinction: `Money` is
allowed, every other `Finance` service is a violation. Tests 21–24 assert
row-count-and-balance immutability of `wallets`, `ledger_entries`,
`financial_transactions` and `payouts` across a full settlement, including a
before/after byte comparison of every wallet balance column.

### 8.5 Idempotency (requirement E)

Three layers, none of them cache:

1. **Database unique constraints.** `draw_results.draw_id` unique (Capability 5) and
   `winning_numbers_unique` (Capability 8). A second publication attempt collides at
   database level, not merely at application level. The collision is caught and
   converted into a deterministic domain refusal, and the transaction rolls back.
2. **Lifecycle state.** Publication requires state `ResultPending`; a draw already in
   `ResultPublished` or `Settled` is refused by the transition guard. Settlement
   requires state `ResultPublished`; a draw already `Settled` returns the recorded
   result with `alreadySettled = true` and performs **zero** writes.
3. **Row-level guard.** Each selection is settled only from a non-final `BetStatus`
   (`Pending` or `Active`). An already-`Won`/`Lost`/`Refunded`/`Cancelled` bet is not
   re-settled, so re-running cannot double a figure.

`Cache` is not used for idempotency anywhere in Phase 5.1. The static audit greps
for `Cache::` in the new code and must report zero hits.

### 8.6 Atomicity and lock ordering (requirement F)

Publication and settlement each run inside a single `DB::transaction()`. Either the
`draw_results` row, all six `winning_numbers` rows, the aggregates and the status
change all commit, or none do. Partial publication and partial settlement are
therefore impossible.

**Lock ordering.** The existing finance and betting services document the order
`WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS`, and
`BetPurchaseTransactionService` locks the wallet "first, always, on every path".
Phase 5.1 locks **no wallet**, because it touches no wallet, so it cannot invert that
order. Its own order is `DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS`, strictly
downward through the ownership hierarchy, acquired with
`Draw::whereKey($id)->lockForUpdate()`. The draw row is the single serialisation
point, which is what makes concurrent settlement safe (test 20).

**One known interaction, reported not hidden.**
`BetPurchaseTransactionService::assertNotAlreadyInTransaction()` throws when
`DB::transactionLevel() > 0`. Phase 5.1 never calls the purchase engine, so it never
trips that guard, and the guard is not modified.

`SELECT ... FOR UPDATE` on a specific primary key is the only locking construct used.
No `DB::statement()`, no `DB::raw()`, no `whereRaw()` and no string-built SQL appears
in Phase 5.1 code (requirement H).

### 8.7 Determinism

Given the same `(draw, first_prize, bottom_two, set of bet items)`, the output is
byte-identical: selections are read in `ORDER BY bet_items.id`, every comparison is a
string comparison, every amount is a BCMath string with scale 2, no `rand()`, no
`shuffle()`, no `now()`-dependent branch, and no float anywhere. Test 18 runs
settlement twice and diffs the full result structure.

---

## 9. Files to be created and modified

### 9.1 To be MODIFIED — 1 file

| # | Path | Why strictly necessary |
|---|---|---|
| 1 | `app/Enums/DrawStatus.php` | Add the single missing `ResultPublished` case plus one arm each in the two exhaustive `match()` bodies. Section 6 is the full conflict report. Purely additive; no migration; backward compatibility proved by test. |

### 9.2 To be CREATED — 13 source files

**Enums (2)**

| # | Path | Why necessary |
|---|---|---|
| 2 | `app/Enums/DrawLifecycleState.php` | The seven spec-named lifecycle states and the authoritative transition table, mapped bidirectionally onto the persisted `DrawStatus`. Keeps transition rules out of the Phase 1 enum and out of a service `switch`. |
| 3 | `app/Enums/SettlementSimulationStatus.php` | The per-selection settlement status required by requirement H (`pending`, `won`, `lost`, `not_settleable`). No existing enum expresses it: `BetStatus` is the bet aggregate's own status and `PayoutStatus` describes a real-money payout row. |

**Exceptions (3)**

| # | Path | Why necessary |
|---|---|---|
| 4 | `app/Exceptions/DrawLifecycleException.php` | Invalid transition, locked/immutable draw, duplicate publication. No equivalent exists: `InvalidFinancialStateTransitionException` is the finance domain's, and reusing it would let a `catch` intended to unwind money handle a draw refusal. |
| 5 | `app/Exceptions/DrawResultValidationException.php` | Malformed first prize, wrong digit width, missing or malformed bottom two. `InvalidLotteryNumberException` covers a *selection*, not a published *result*, and `MarketResultUnavailableException` covers *reading* an absent result, not *rejecting a proposed* one. |
| 6 | `app/Exceptions/SettlementSimulationException.php` | Draw not in a settleable state, unresolvable market for an item, contradictory market metadata, multiplier that cannot be stored exactly. |

All three carry the same `(message, errorCode, context)` shape as
`BetDomainException`, hold no models, are safe to throw inside a transaction about to
roll back, and never place credentials or raw payloads in `context`.

**DTOs (3)**

| # | Path | Why necessary |
|---|---|---|
| 7 | `app/DTOs/DrawResultData.php` | Immutable, already-validated result input. Constructible only through `DrawResultValidator`, so an unvalidated first prize cannot reach persistence, and no user-controlled winner field can be smuggled in (requirement H). |
| 8 | `app/DTOs/SettlementSelectionResult.php` | One selection's simulation outcome carrying exactly the seven fields requirement H enumerates, plus the drawn value compared against. |
| 9 | `app/DTOs/SettlementSimulationResult.php` | The aggregate outcome: counts, exact decimal totals as strings, `alreadySettled`, and the ordered list of selection results. |

**Services (5), new directory `app/Services/Draw/`**

| # | Path | Why necessary |
|---|---|---|
| 10 | `app/Services/Draw/DrawLifecycleService.php` | Requirement A. Owns transitions, the immutability guard, the lifecycle timestamps and the row lock. |
| 11 | `app/Services/Draw/DrawResultValidator.php` | Requirement B. Validates first prize and bottom two against the existing rules and produces `DrawResultData`. No persistence. |
| 12 | `app/Services/Draw/DrawResultPublicationService.php` | Requirement C + E + F. Atomically writes the `draw_results` row, the six `winning_numbers` rows and the `ResultPending -> ResultPublished` transition. |
| 13 | `app/Services/Draw/SelectionSettlementResolver.php` | Requirement D. Resolves a bet item's market (8.2), calls the correct Phase 4.2 match service, prices the simulated prize through `MarketPayoutService`. Contains **no** match logic and **no** rate of its own. |
| 14 | `app/Services/Draw/DrawSettlementSimulationService.php` | Requirements D–G. Orchestrates the run, records results onto the existing settlement columns, writes the aggregates and the audit row, guarantees idempotency and atomicity, and touches no money table. |

### 9.3 To be CREATED — test files

| # | Path | Purpose |
|---|---|---|
| 15 | `tests/Feature/Settlement/SettlementTestCase.php` | Shared base: disposable-database assertion, lifecycle-state draw construction (without modifying `DrawFactory`), real bet/bet-item construction, money-table snapshot helper. |
| 16 | `tests/Feature/Settlement/DrawLifecycleTest.php` | Points 1, 2, 3, 17 |
| 17 | `tests/Feature/Settlement/DrawResultPublicationTest.php` | Points 4, 5, 14, 15, 16, 17 |
| 18 | `tests/Feature/Settlement/SettlementSimulationTest.php` | Points 6–13, 18, 19, 20, 25, 26, 30 |
| 19 | `tests/Feature/Settlement/NonMonetarySettlementTest.php` | Points 21, 22, 23, 24 |
| 20 | `tests/Unit/Settlement/SettlementSafetyAuditTest.php` | Points 27, 28, 29 as executable static assertions over the Phase 5.1 source, plus the `DrawStatus` backward-compatibility regression test |

### 9.4 Explicitly NOT created

No migration. No payment gateway. No withdrawal system. No real-money payout
processor. No agent commission. No Filament admin. No WebSocket/Reverb. No frontend
UI. No HTTP route, controller, form request or API resource — Phase 5.1 is a
**domain** phase, and the specification's scope list (A–I) contains no HTTP surface.
No new config file and no config edit: the simulation mode is a hard-coded constant
(`DrawSettlementSimulationService::MODE = 'simulation'`) precisely so that no
configuration switch exists that could ever turn real-money payout on.

---

## 10. Static audit to be run

A shell script kept **outside** the project (so it is never packaged as a temp
harness) greps only the Phase 5.1 files listed in 9.1–9.2 and reports a count per
check. Every count must be `0`.

| Check | Pattern |
|---|---|
| float cast | `(float)`, `(double)`, `floatval(` |
| int cast on domain values | `(int)`, `intval(` |
| rounding | `round(`, `floor(`, `ceil(`, `number_format(` |
| wallet mutation | `Wallet::`, `WalletService`, `WalletHoldService`, `WalletLockService`, `->balance`, `wallets` |
| ledger mutation | `LedgerEntry`, `LedgerAccount`, `LedgerPostingService`, `ledger_entries` |
| financial transaction | `FinancialTransaction`, `FinancialTransactionService`, `financial_transactions` |
| real payout | `Payout::`, `payouts`, `payout_id`, `PayoutStatus` |
| payment gateway | `Payment`, `Deposit`, `Withdrawal`, `Stripe`, `crypto`, `bkash`, `nagad`, `gateway` |
| bypass flags | `force`, `override`, `bypass`, `skip_`, `unsafe` |
| user id from body | `request()->input('user_id')`, `$request->user_id`, `input('user_id')` |
| raw SQL | `DB::statement`, `DB::raw`, `whereRaw`, `selectRaw`, `->update([` with raw |
| cache idempotency | `Cache::` |
| exception leakage | `getMessage()` in a response/return path, `getTraceAsString`, `getTrace(` |
| hard-coded rate | `900`, `45`, `90`, ` 3 `, ` 4 ` as a literal multiplier |

The full script and its real output go into the final report. Any non-zero count is
reported as a hit, not filed off.

One documented limitation carried over from Phase 4.4: the hard-coded-rate scan can
only reliably detect multi-digit rates (900, 45, 90). Single-digit rates (Run's 3 and
4) are indistinguishable from any small integer by grep, so they are covered
behaviourally instead — tests 12, 13 and 26 read the rate from
`config('lottery.markets.run_*.payout_multiplier')` at assertion time and compare it
against what settlement produced, and additionally assert the simulated Run prize is
**not** the legacy `BetType::Run->payoutMultiplier()` value of 12.

---

## 11. Compatibility position

| Phase | Files touched by 5.1 | Risk |
|---|---|---|
| 1 (schema, models, enums) | `app/Enums/DrawStatus.php` only, one additive case | Backward compatibility proved by an explicit regression test over all six original cases × six methods |
| 2.1 / 2.2 (finance, ledger) | **none** | Zero. No finance service is injected; only the pure `Money` value object is used |
| 3.1 (risk, number limits) | **none** | Zero. Settlement neither reserves nor releases a number limit |
| 4.1 (number/payout value objects) | **none**, consumed read-only | Zero |
| 4.2 (market rules) | **none**, consumed read-only | Zero. All six market decisions delegate to the existing match services |
| 4.3 (atomic purchase) | **none** | Zero. The purchase engine is never called, so its in-transaction guard is never tripped |
| 4.4 (HTTP/API) | **none** | Zero. No route, controller, request or resource is added or changed |

---

## 12. Verification commands to be run after implementation

```bash
cd /home/user/workspace/proj/Thai-lottery
sudo service mariadb start

# 1. Every new and modified file parses
php -l app/Enums/DrawStatus.php
php -l app/Enums/DrawLifecycleState.php
php -l app/Enums/SettlementSimulationStatus.php
php -l app/Exceptions/DrawLifecycleException.php
php -l app/Exceptions/DrawResultValidationException.php
php -l app/Exceptions/SettlementSimulationException.php
php -l app/DTOs/DrawResultData.php
php -l app/DTOs/SettlementSelectionResult.php
php -l app/DTOs/SettlementSimulationResult.php
php -l app/Services/Draw/DrawLifecycleService.php
php -l app/Services/Draw/DrawResultValidator.php
php -l app/Services/Draw/DrawResultPublicationService.php
php -l app/Services/Draw/SelectionSettlementResolver.php
php -l app/Services/Draw/DrawSettlementSimulationService.php

# 2. No migration was introduced
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
       DB_DATABASE=thai_lottery_test DB_USERNAME=lottery DB_PASSWORD=lottery
php artisan migrate --pretend          # MUST print: Nothing to migrate.
ls database/migrations | wc -l         # MUST print: 23

# 3. No HTTP surface was added
php artisan route:list --path=api      # MUST still show exactly 4 routes

# 4. Phase 5.1 suite
php artisan test --testsuite=Feature --filter='DrawLifecycleTest|DrawResultPublicationTest|SettlementSimulationTest|NonMonetarySettlementTest'
php artisan test --filter=SettlementSafetyAuditTest

# 5. Full suite, no regression against the 121-test baseline
php artisan test

# 6. Static audit
bash /home/user/workspace/audit_phase51.sh

# 7. Package integrity
composer validate --no-check-publish
unzip -t Thai-lottery-PHASE-5.1-DRAW-SETTLEMENT.zip
```

---

## 13. Honest statement of what this audit does and does not establish

- The baseline in section 1 was **executed**, and its output is reproduced verbatim.
- Every schema fact in section 3 was read from the migration files, and every code
  guarantee quoted in section 4 was read from the source. None is recalled or assumed.
- Sections 6–10 are a **plan**. Nothing in them has been implemented at the time of
  writing. Whether the plan survives contact with the code will be reported in the
  final Phase 5.1 report, including anything that turns out to be wrong here.
- The one required change to existing code is reported in the mandated conflict
  format in section 6 rather than made silently. If it is rejected, the
  `ResultPublished` state cannot be delivered and I will stop and ask rather than
  encode the state somewhere it does not belong.
- **No migration is required.** If implementation uncovers a genuine schema blocker,
  I will stop and report `SCHEMA CHANGE REQUIRED` instead of creating one.
