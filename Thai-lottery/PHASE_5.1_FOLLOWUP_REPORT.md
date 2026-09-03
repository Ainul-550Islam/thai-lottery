# PHASE 5.1 FOLLOW-UP — FIX THE HONEST GAPS

Non-monetary lottery simulator. No real-money settlement was added, and none exists.

- Project root: `/home/user/workspace/proj/Thai-lottery`
- PHP 8.5.4 · Laravel 11.56.1 · Composer 2.10.3 · PHPUnit 11.5.56 · MariaDB 11.8.6
- Date of this run: 2026-08-28

---

## 1. Executive summary

| | Before this follow-up | After this follow-up |
|---|---|---|
| Tests | 225 | **252** |
| Assertions | 87,889 | **90,872** |
| Failures | 0 | **0** |
| Migrations | 23 | **23 (unchanged)** |
| API routes | 4 | **4 (unchanged)** |
| Static audit | 57 checks, 0 findings | **76 checks, 0 findings** |
| Files created | — | **2** (both tests) |
| Files modified | — | **3** |
| Schema changes | — | **none** |

Three of the eight items in the brief turned out to be real, actionable gaps. Four were
already correct and are now pinned by tests so they cannot silently regress. One is a
framework limitation that cannot be fixed inside this project and is documented as
carried-forward technical debt.

| Item | Verdict | Action taken |
|---|---|---|
| A — `payout_multiplier` integer column | **Not a current defect** | No schema change. Three regression tests now prove all six configured rates round-trip exactly and that a fractional rate is refused, never truncated or rounded. |
| B — `PDO::MYSQL_ATTR_SSL_CA` deprecation | **Real, partially fixable** | The project's own `config/database.php` no longer references the deprecated constant. The remaining notice originates in `vendor/laravel/framework/config/database.php` and is carried-forward debt. |
| C — `DrawStatus::ResultPublished` | **Correct as shipped** | No change. A test now pins the six original values byte-for-byte. |
| D — settlement idempotency | **Correct as shipped** | No change. Re-executed; evidence in §8. |
| E — result immutability | **Real gap — FIXED** | Settlement now recomputes the published result and refuses to run if it disagrees with the stored `winning_numbers`. DB-level immutability reported as a schema limitation. |
| F — exact decimal simulation | **Correct as shipped** | No change. Six new tests pin one exact product per market. |
| G — leading zeros | **Correct but under-tested** | No change. Six new tests add `000`, `099`, `09`, `00` and the all-zero run case. |
| H — phase boundary | **Correct** | Confirmed in §11. |

**Nothing was weakened.** No test was removed, no assertion loosened, no validation
bypassed, no force/override/skip flag introduced, no raw SQL added, and no verified
Phase 1–4.4 business rule was altered.

---

## 2. Item E — result immutability (the one real defect)

### 2.1 The gap

**File:** `app/Services/Draw/DrawSettlementSimulationService.php`
**Method:** `publishedResultFor(Draw $draw): DrawResultData`

The authoritative result of a draw is deliberately stored in **two** places:

- `draw_results.first_prize` — the raw six-digit first prize, plus the bottom two inside
  `draw_results.metadata`.
- `winning_numbers` — one row per configured market holding the **derived** per-market
  winning value, keyed by `prize_tier` (`first_prize_last_three`, `first_prize_last_two`,
  `bottom_two`).

`DrawResultPublicationService::publish()` writes both inside one transaction, so at the
instant publication commits the two agree by construction.

As shipped, `publishedResultFor()` read **only** `draw_results` and validated it in
isolation. It never asked whether the stored `winning_numbers` rows still agreed.

### 2.2 Evidence that this was exploitable

Neither table has a database-level immutability constraint, and `App\Models\WinningNumber`
uses `SoftDeletes`. So all three of the following were possible **after** publication:

1. `UPDATE draw_results SET first_prize = '456999' WHERE draw_id = ?` — settlement would
   then decide every winner from `999` while the official `winning_numbers` row still said
   `123`.
2. `UPDATE winning_numbers SET number = '999' WHERE ...` — the official published record
   would say `999` while settlement decided winners from `123`.
3. Soft-deleting the `bottom_two` rows — a bottom market would then be settled against a
   value that no longer had any official published record.

Worse, case 1 applied **after** a successful settlement also corrupted the replay path.
A second `settle()` call on a `Settled` draw returns the stored outcome; it would have
reported the tampered first prize alongside `bet_items` rows that were settled from the
original one, presenting a self-contradictory record as authentic.

### 2.3 Root cause

`publishedResultFor()` treated `draw_results` as a single source of truth when the design
actually stores the result redundantly. Redundant storage with no cross-check means a
partial edit is undetectable.

### 2.4 The exact fix

A new private method `assertResultIntegrity(int $drawId, DrawResultData $data): void` was
added and is called from `publishedResultFor()` immediately after validation, i.e. **before
any winner is decided**. It:

1. loads every `winning_numbers` row for the draw (soft-deleted rows are excluded by the
   model's global scope, so a soft delete is detected as a missing row);
2. refuses if there are none;
3. for each row, resolves `prize_tier` to a `MarketResultType` and refuses on an unknown
   tier;
4. compares the stored `number` against `$data->valueFor($resultType)` using `!==` — a
   **string** comparison, so `'07'` and `'7'` are different values and a numeric comparison
   can never call them equal;
5. refuses if any `MarketResultType` case has no surviving row.

Both settlement entry points — the first-run path (`performSettlement()`) and the replay
path — call `publishedResultFor()`, so one guard covers both. Static audit checks 59 and 60
pin that there is exactly one call site for the guard and at least two for the loader.

A new error code was added to `App\Exceptions\SettlementSimulationException`:

```
public const CODE_RESULT_TAMPERED = 'SETTLEMENT_RESULT_TAMPERED';
```

with a factory `resultTampered(int $drawId, string $reason, array $context = [])`. It carries
ids, the prize tier, and the two disagreeing numbers only — no stack trace, no SQL, no
credential, no personal data.

`audit()` gained a `tables_read_only` key and `guarantees()` gained a `result_integrity`
entry, so the service's self-description stays truthful.

### 2.5 Why this is safe

- The guard is **read-only**: one `SELECT`, no writes.
- It is thrown from inside the settlement transaction while the draw row is held with
  `SELECT ... FOR UPDATE`, so a refusal rolls the whole run back and the draw stays in
  `result_published`.
- For any result published through `DrawResultPublicationService` it passes by
  construction. Test `item_e_publication_leaves_the_result_and_the_winning_numbers_in_agreement`
  proves that premise directly, and `item_e_an_untampered_result_settles_and_replays_normally`
  is the negative control that stops a guard which refused everything from looking like a
  passing suite.
- Migration required: **none**.
- Backward compatibility: any draw already published by the verified pipeline passes. The
  only behaviour change is that a draw whose stored result has been edited out-of-band is
  now refused instead of silently mis-settled.

### 2.6 SCHEMA LIMITATION — RESULT IMMUTABILITY

The fix is an **application-layer** guarantee. It is not a database-level one, and this
report does not claim otherwise.

**What is still possible:** a process with direct `UPDATE` rights can edit `draw_results`
and `winning_numbers` *consistently* — changing both so they still agree. The guard cannot
detect that, because after such an edit the two sources are self-consistent.

**What would be required to close it at the database level** (all of these are schema or
privilege changes and were therefore **not** performed):

1. A `BEFORE UPDATE` / `BEFORE DELETE` trigger on `draw_results` and `winning_numbers` that
   signals an error once `published_at` is non-null. MariaDB supports this; it is a schema
   change and needs its own migration and rollback test.
2. Or an append-only design: a `draw_result_versions` table with an immutable hash chain, so
   an edit is detectable even when consistent. This is a new table plus new columns on
   `draws`, i.e. a substantial schema change.
3. Or revoking `UPDATE`/`DELETE` on those two tables from the application database user and
   granting them only to a migration role. This is a deployment-privilege change, not code.

Choosing among these is a design decision beyond a follow-up whose brief was to fix real
gaps without silently changing schema. **Recommendation: option 1, in an explicitly
specified future phase.**

---

## 3. Item A — the `payout_multiplier` integer column (limitation L5)

### 3.1 Verdict: not a current defect. No schema change.

**Column:** `bet_items.payout_multiplier` — `unsignedInteger`
**Value object:** `App\ValueObjects\PayoutMultiplier` — `SCALE = 4`

All six currently configured rates are whole numbers:

| Market | Configured rate | Whole number? | Stored exactly? |
|---|---|---|---|
| `3d_direct` | 900 | yes | yes |
| `3d_tod` | 45 | yes | yes |
| `2d_top` | 90 | yes | yes |
| `2d_bottom` | 90 | yes | yes |
| `run_top` | 3 | yes | yes |
| `run_bottom` | 4 | yes | yes |

### 3.2 Why information cannot be lost today

The refusal already existed and was verified, in two layers:

- `PayoutMultiplier::fitsBetItemColumn()` returns `isInteger()`.
- `PayoutMultiplier::toBetItemColumn()` **throws** `BetDomainException` on a fractional
  value rather than truncating it.
- `SelectionSettlementResolver` checks `fitsBetItemColumn()` before writing and throws
  `SettlementSimulationException::multiplierUnstorable()` if it is false.

So a fractional rate produces a **refused run**, never a wrong prize. The brief's
condition for a migration — "the current implementation can actually lose information for a
currently supported rule" — is **not met**, so no migration was designed and no test was
written to fail. Per the brief, the schema was left unchanged and coverage was strengthened
instead.

### 3.3 Coverage added

- `item_a_every_configured_multiplier_is_storable_in_the_integer_column` — iterates
  **every** market in `config('lottery.markets')` and asserts each rate is an integer, fits
  the column, and round-trips through `toBetItemColumn()` unchanged. A future configuration
  edit that introduces a fractional rate fails here immediately and names the market.
- `item_a_a_fractional_multiplier_is_refused_rather_than_truncated` — `2.5000` would become
  `2` under truncation and `3` under rounding. The test asserts `integerPart() === '2'` to
  show what truncation *would* lose, asserts the throw, and asserts `toDatabase()` still
  returns `'2.5000'` — proving the refusal is about the **column**, not the rate.
- `item_a_a_settled_selection_stores_the_configured_rate_exactly` — reads the persisted
  `bet_items` row and asserts `payout_multiplier === '900'` and `actual_payout === '90000.00'`.

### 3.4 Documented boundary

A fractional payout rate would require an explicit future migration of
`bet_items.payout_multiplier` to `decimal(20,4)`, along with updates to the model cast,
`SettlementSelectionResult::toBetItemColumns()`, the resolver's storability check, and a
rollback test. That is a schema change and is **out of scope** here.

---

## 4. Item B — the PHP 8.5 `PDO::MYSQL_ATTR_SSL_CA` deprecation

### 4.1 Confirmed still present, and confirmed on the supported PHP version

```
$ php -v
PHP 8.5.4 (cli) (built: Jul 16 2026 18:56:38) (NTS)

$ php -r 'error_reporting(E_ALL); $x = PDO::MYSQL_ATTR_SSL_CA;'
PHP Deprecated:  Constant PDO::MYSQL_ATTR_SSL_CA is deprecated since 8.5,
                 use Pdo\Mysql::ATTR_SSL_CA instead
```

Both spellings resolve to the same attribute id:

```
PDO::MYSQL_ATTR_SSL_CA  = 1008
Pdo\Mysql::ATTR_SSL_CA  = 1008
```

### 4.2 The smallest possible change, applied

`config/database.php` previously read:

```php
'options' => extension_loaded('pdo_mysql') ? array_filter([
    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
]) : [],
```

It now reads:

```php
(class_exists(\Pdo\Mysql::class)
    ? \Pdo\Mysql::ATTR_SSL_CA
    : constant('PDO::MYSQL_ATTR_SSL_CA')) => env('MYSQL_ATTR_SSL_CA'),
```

- **Behaviour impact: none.** Both names are attribute id 1008, so the resolved option array
  is byte-for-byte identical.
- **Compatibility: PHP 8.2 – 8.5.** On PHP 8.2–8.4 `Pdo\Mysql` does not exist and the
  fallback is used. The fallback goes through `constant()` so the deprecated name never
  appears as a compiled constant reference on a PHP version that would warn about it.
- No other project file referenced any `PDO::MYSQL_*` constant (verified by scan).

### 4.3 CARRIED-FORWARD TECHNICAL DEBT — the notice remains, and it is not ours

The deprecation notice still appears in the test run. Its source is **not** this project:

```
at vendor/laravel/framework/config/database.php:61
    60▕ 'options' => extension_loaded('pdo_mysql') ? array_filter([
 ➜  61▕     PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
```

Laravel merges its own default config files underneath the application's, so the framework
file is evaluated on every boot regardless of what the project ships. The deprecated
constant appears there on lines 61 and 81.

`v11.56.1` is the **latest** release on the 11.x branch (`composer show laravel/framework
--available` lists no higher 11.x version), so there is no patch upgrade that fixes it.
Removing the notice would require a major upgrade to Laravel 12.x or 13.x — a change far
outside a follow-up whose brief was to avoid rewriting verified code, and one that would put
the entire verified Phase 1–5.1 codebase at risk.

**Decision: left unchanged, documented as carried-forward technical debt.**

Practical consequence for reading test output: every test that touches the database is
reported as `DEPR` / `!` rather than `✓`. **`Tests: 231 deprecated, 21 passed` means 252
tests passed and 0 failed.** A genuine failure appears as `⨯` and as a non-zero `failed`
count.

### 4.4 Regression test added

`tests/Unit/Config/DatabaseDriverOptionsTest.php` (5 tests) asserts:

1. `Pdo\Mysql::ATTR_SSL_CA === 1008`, so the swap provably changes no behaviour.
2. The resolved `database.connections.mysql.options` array is keyed only by 1008 (or empty,
   since the value is wrapped in `array_filter()`).
3. **No file under `config/` contains a compiled reference to a `PDO::MYSQL_*` constant.**
   Comments and string-literal contents are stripped with `token_get_all()` first, so the
   explanatory comment and the `constant('PDO::…')` fallback argument are not false
   positives — what is scanned is exactly what PHP would resolve at compile time.
4. `config/database.php` actually contains `Pdo\Mysql::ATTR_SSL_CA` and the `class_exists()`
   guard — so tests 3 and 4 cannot both pass on a file that simply deleted the option.
5. The mysql connection definition is still intact: driver `mysql`, `strict` enabled,
   charset `utf8mb4`, `options` key present.

---

## 5. Item C — `DrawStatus` / `ResultPublished` re-confirmed

No change was made. Confirmed:

- `draws.status` is `varchar(32)`, so appending an enum case needs **no migration**. Still
  23 migrations, `php artisan migrate` reports `Nothing to migrate.`
- The six original cases are unchanged in name and in stored value: `scheduled`, `open`,
  `closed`, `drawing`, `completed`, `cancelled`. Nothing was renamed, removed or reordered
  in a way that changes a stored string.
- `ResultPublished = 'result_published'` is the single appended case, and it equals
  `DrawLifecycleState::ResultPublished->value` — which is what lets one varchar column carry
  both vocabularies.
- `DrawLifecycleState::Settled` maps to `DrawStatus::Completed` ('completed'), unchanged.
- All lifecycle transitions remain valid: Draft→{Open,Cancelled}, Open→{Closed,Cancelled},
  Closed→{ResultPending,Cancelled}, ResultPending→{ResultPublished,Cancelled},
  ResultPublished→{Settled}, Settled/Cancelled terminal. Re-executed by the 15 tests in
  `DrawLifecycleTest`, all passing.

Pinned by the new test `item_c_draw_status_keeps_its_original_values_and_adds_result_published`,
which fails if any original value is renamed or dropped.

---

## 6. Item F — exact decimal simulation, one pinned product per market

No production change. Six tests now pin one exact expected product per market, each
computed as a decimal string and each cross-checked against a value read from
**configuration** rather than restated as a literal:

| Test | Market | Stake × rate | Asserted result |
|---|---|---|---|
| `item_f_three_digit_direct_pays_exactly_stake_times_nine_hundred` | `3d_direct` | 100.00 × 900 | `'90000.00'` |
| `item_f_three_digit_tod_pays_exactly_stake_times_forty_five_once` | `3d_tod` | 100.00 × 45 | `'4500.00'` |
| `item_f_two_digit_top_pays_exactly_stake_times_ninety` | `2d_top` | 100.00 × 90 | `'9000.00'` |
| `item_f_two_digit_bottom_pays_exactly_stake_times_ninety` | `2d_bottom` | 100.00 × 90 | `'9000.00'` |
| `item_f_run_top_pays_exactly_stake_times_three_not_the_legacy_twelve` | `run_top` | 100.00 × **3** | `'300.00'` |
| `item_f_run_bottom_pays_exactly_stake_times_four_not_the_legacy_twelve` | `run_bottom` | 100.00 × **4** | `'400.00'` |

Every one also asserts `wasRounded === false`.

**The Run gap from the Phase 5.1 report is now closed by assertion, not only behaviourally.**
The legacy `BetType::Run->payoutMultiplier()` returns **12**. The two Run tests assert
`assertNotSame('1200.00', …)` and `legacyMultiplierWasAvoided('12')`, so if the legacy rate
were ever reached for, the prize would read `1200.00` and the test would fail. Static audit
check 74 pins that those negative assertions are present in the file.

### Fractional stakes

The brief asked that "fractional stake examples already supported by the project must remain
exact". Stated honestly: **the project does not currently support fractional stakes.** Phase
3.1 validation enforces `amount_step = 1.00` with a minimum stake of `10.00`, so a stake such
as `10.50` is rejected at purchase and can never reach settlement. There is therefore no
fractional-stake example to preserve. The arithmetic itself is nonetheless exact at the
currency scale — every total is accumulated with `bcadd(..., 2)` and every product computed
with `bcmul` on decimal strings — and static audit section A proves there is no `(float)`
cast, `intval()`, `floatval()`, `round()`, `floor()`, `ceil()`, `number_format()`, `fdiv()`,
`settype()` or float literal anywhere in the 14 Phase 5.1 source files, nor in the two new
test files (checks 69–70).

Also re-confirmed: the per-number payout exposure ceiling of `100000.00` is why `100.00` is
the largest stake `3d_direct` accepts (100.00 × 900 = 90000.00). Exceeding it is a purchase
refusal, not a settlement problem.

---

## 7. Item G — leading zeros

No production change. Six tests added, each settling a real purchase end-to-end and then
re-reading the persisted `bet_items.number`:

| Test | Selection | First prize / bottom | Asserted |
|---|---|---|---|
| `item_g_a_three_digit_selection_of_007_keeps_its_leading_zeros` | `007` | `456007` | selection and stored column both `'007'`, never `7` |
| `item_g_a_three_digit_selection_of_000_keeps_its_leading_zeros` | `000` | `456000` | `'000'`, `strlen === 3`, not `'0'`, not empty |
| `item_g_a_tod_selection_of_099_keeps_its_leading_zero` | `099` | `456990` | `'099'` never `99`; wins as a permutation of `990`; **charged once** |
| `item_g_a_two_digit_top_selection_of_09_keeps_its_leading_zero` | `09` | `456009` | `'09'`, never `9` |
| `item_g_a_two_digit_bottom_selection_of_00_keeps_its_leading_zeros` | `00` | bottom `00` | `'00'`, `strlen === 2` |
| `item_g_a_run_selection_of_zero_records_occurrences_without_multiplying_the_prize` | `0` | `456000` | `'0'` survives as a digit; occurrences = 3 |

`000` is the hardest case: every digit is a zero, so any numeric handling anywhere in the
path would collapse it to `0` or to an empty string. It survives.

### A rule this work made explicit

The all-zero Run test also pins a rule the Phase 5.1 report described but never asserted
with a number. With drawn last-three `000`, the digit `0` occurs **three** times. The
occurrence count is **recorded** (`occurrences === 3`) but the prize is **not** multiplied by
it: `100.00 × 3 = 300.00`, not `900.00`. `charges === 1`. One original selection remains one
simulated ticket item, and a repeated digit no more multiplies its worth than a repeated tod
permutation multiplies the stake. The test asserts `assertNotSame('900.00', …)` so a future
change that multiplied by occurrences would fail here.

---

## 8. Item D — idempotency and concurrency evidence (re-executed)

No change was made. Re-executed on this build:

```
$ php artisan test --filter="concurren"
  ! aa lets only one of two concurrent purchases spend the last balance      8.13s
  ! ab lets only one of two concurrent purchases consume the last capacity   0.52s
  ! ab2 replays rather than duplicates two concurrent identical requests     0.51s
  ! point 20 lets only one of two concurrent settlements write              0.57s
  Tests:    4 deprecated (28 assertions)
  Duration: 9.79s
```

`point 20` is real two-process contention: two `proc_open` child processes synchronised on a
barrier file, both calling `settle()` on the same draw. Not mocks, not fakes. Exactly one
writes; the other takes the no-op path.

Guarantees confirmed still held, from the database rather than from a cache:

- **Same draw cannot be settled twice** — the second run locks the draw row with
  `SELECT … FOR UPDATE`, reads the terminal `Settled` state and writes nothing.
- **Exactly one winner under concurrency** — serialisation is on the row lock.
- **No duplicate simulation or audit record** — the loser writes nothing at all.
- **Same fingerprint / same result** — `item_e_an_untampered_result_settles_and_replays_normally`
  asserts the replay reports the same `totalSimulatedPrize`, `firstPrize` and `bottomTwo` as
  the run that settled the draw.
- **No financial, wallet, ledger, payout or payment mutation** — `assertFinanceUnchanged()`
  compares a full finance snapshot before and after, on every refusal path including the
  three new tampering tests.
- **Idempotency source** — `audit()['idempotency_source']` is
  `"the stored draws.status plus SELECT ... FOR UPDATE; no cache"`. Static audit checks 53
  and 54 confirm `DB::transaction` and `lockForUpdate` are present in the source.

---

## 9. Rollback evidence (re-executed)

```
$ php artisan test --filter="rollback|rolls_back|atomic"
  Tests:    52 deprecated (235 assertions)
  Duration: 31.58s
```

All 52 passed, including:

- `ac rolls everything back when bet creation fails`
- `ad rolls everything back when ticket creation fails`
- `ae rolls everything back when the ledger fails`
- `af rolls everything back when the wallet debit fails`
- `ag mutates nothing when validation fails`
- `ah mutates nothing when risk refuses`
- `ai mutates nothing when the balance is insufficient`
- `z a multi item request is atomic and charges nothing when it cannot complete`
- `point 19 rolls back completely when one selection cannot be settled`

The three new tampering tests are themselves rollback evidence for the new guard. Each
asserts, after the refusal:

- `draws.status` is still `result_published` — the draw did not advance;
- the purchased `bet_items` row is still unsettled (`is_winner` false, `actual_payout`
  `'0.00'`);
- `assertFinanceUnchanged(...)` — no wallet, ledger entry, financial transaction, payout,
  deposit, withdrawal, payment or agent commission was created or altered.

And `item_e_a_result_edited_after_settlement_refuses_the_replay` asserts the converse: the
already-settled data is **untouched** by the refusal (`is_winner` true, `actual_payout`
`'90000.00'`, status still `completed`), because the guard is a read-time check, not a
mutation.

---

## 10. Full regression battery (requirement I) — all executed on this build

| # | Check | Command | Result |
|---|---|---|---|
| 1 | PHP syntax | `php -l` over all 252 project PHP files | **0 errors** |
| 2 | Composer validation | `composer validate --no-check-publish` | **`./composer.json is valid`** |
| 3 | Autoload generation | `composer dump-autoload -o` | **6,651 classes** |
| 4 | Laravel boot | `php artisan about` | **boots; Laravel 11.56.1** |
| 5 | Migration validation | `php artisan migrate --force` | **`Nothing to migrate.`** — 23 migrations, all `[1] Ran` |
| 6 | Full existing suite | `php artisan test` | **252 tests, 90,872 assertions, 0 failures, 101.20s** |
| 7 | Phase 5.1 tests | `--filter="Settlement"` (incl. follow-up) | **all passing** |
| 8 | Concurrency tests | `--filter="concurren"` | **4 tests, 28 assertions, 0 failures** |
| 9 | Rollback tests | `--filter="rollback\|rolls_back\|atomic"` | **52 tests, 235 assertions, 0 failures** |
| 10 | Static security audit | `bash /home/user/workspace/audit_phase51_followup.sh` | **76 checks, 0 findings** |

Verbatim final suite line:

```
  Tests:    231 deprecated, 21 passed (90872 assertions)
  Duration: 101.20s
```

Routes unchanged — `php artisan route:list --path=api` still shows **4** routes:

```
  POST       api/v1/bets/purchase
  GET|HEAD   api/v1/bets/{bet}
  GET|HEAD   api/v1/bets/{bet}/status
  GET|HEAD   api/v1/tickets/{ticket}
```

### New test counts

| File | Tests | Assertions |
|---|---|---|
| `tests/Feature/Settlement/Phase51FollowUpTest.php` | **22** | **185** |
| `tests/Unit/Config/DatabaseDriverOptionsTest.php` | **5** | **16** |
| **Total added** | **27** | — |

225 + 27 = **252**. No existing test was modified or removed.

### Static audit — the 19 new checks (section J)

```
--- J. PHASE 5.1 FOLLOW-UP FIXES ----------------------------------------
  [ 58 ] result integrity guard is declared                             1 declaration(s)
  [ 59 ] guard is called from the single result loader                   1 call site(s)
  [ 60 ] both settle paths go through that loader                       2 call site(s)
  [ 61 ] SETTLEMENT_RESULT_TAMPERED error code exists                   1 declaration(s)
  [ 62 ] guard compares numbers as strings with !==                     1 comparison(s)
  [ 63 ] guard uses no loose numeric comparison                         0 hit(s)
  [ 64 ] config/database.php has no compiled PDO::MYSQL_* ref           0 hit(s)
  [ 65 ] config/database.php prefers Pdo\Mysql::ATTR_SSL_CA             1 reference(s)
  [ 66 ] the modern name is guarded for PHP 8.2 to 8.4                  1 guard(s)
  [ 67 ] follow-up test files present                                   2/2
  [ 68 ] declare(strict_types=1) in every follow-up test                2/2
  [ 69 ] follow-up tests: no (float)/(double) cast                      0 hits  OK
  [ 70 ] follow-up tests: no intval/floatval/round                      0 hits  OK
  [ 71 ] follow-up tests: no real money model                           0 hits  OK
  [ 72 ] follow-up tests: no force/override/bypass                      0 hits  OK
  [ 73 ] follow-up tests: no raw SQL                                    0 hits  OK
  [ 74 ] run tests assert the legacy 12x rate was NOT used              2 assertion(s)
  [ 75 ] the follow-up added no migration (still 23)                    23
  [ 76 ] php -l clean on every follow-up file                           3/3

===============================================================
 RESULT: PASS  --  76 checks, 0 findings
===============================================================
```

Sections A–I (checks 1–57) are the original Phase 5.1 audit, re-run unchanged against the
modified source: still **0 findings**, still `13/13` created files with
`declare(strict_types=1)`, still 23 migrations, still `php -l` clean 14/14.

The audit script lives at `/home/user/workspace/audit_phase51_followup.sh` — **outside** the
project, so it is not part of the delivered source tree and is not in the ZIP.

---

## 11. Item H — phase boundary confirmed

Phase 5.1, including this follow-up, contains **only**:

- draw lifecycle state machine and transitions;
- draw result validation and publication;
- non-monetary settlement **simulation**;
- settlement idempotency and result integrity;
- audit output (`audit()` / `guarantees()` / `audit_logs` rows);
- the tests required for those features.

It contains **no** real-money financial settlement. Statically verified (audit sections C, D
and check 71): no `Wallet`, `FinancialTransaction`, `Ledger*`, `Payout`, `Deposit`,
`Withdrawal`, `Payment` or `AgentCommission` model import; no `App\Services\Wallet|Finance|Ledger|Payment`
import; no `->credit(`/`->debit(`/`->transfer(`/`->refund(`/`->payout(`/`->withdraw(`/`->deposit(`
call; no balance assignment; no `increment`/`decrement`; no `payout_id` write; no HTTP client,
Guzzle, cURL, socket, or named gateway SDK; no queue/bus/event handoff.

`DrawSettlementSimulationService::MODE` is the class constant `'simulation'`, with no config
key, env var or parameter that can change it (audit check 52). `FORBIDDEN_TABLES` remains
wallets, financial_transactions, ledger_entries, ledger_accounts, payouts, deposits,
withdrawals, payments, agent_commissions. Tables written remain **only** `bet_items`, `bets`,
`draws`, `audit_logs`; the follow-up added `winning_numbers` to the **read-only** list.

Nothing was created that the brief forbade: no payment gateway, no withdrawal system, no
real-money payout processor, no agent commission, no Filament admin, no
WebSocket/Reverb, no frontend UI, no crypto, no Phase 6+ feature.

---

## 12. Files created and modified

### Created (2 — both tests, 875 lines)

| File | Lines | Purpose |
|---|---|---|
| `tests/Feature/Settlement/Phase51FollowUpTest.php` | 679 | Items A, C, E, F, G — 22 tests, 185 assertions |
| `tests/Unit/Config/DatabaseDriverOptionsTest.php` | 196 | Item B — 5 tests, 16 assertions |

### Modified (3)

| File | Lines (after) | Change |
|---|---|---|
| `app/Services/Draw/DrawSettlementSimulationService.php` | 793 | Added `assertResultIntegrity()`; `publishedResultFor()` now calls it; two imports (`MarketResultType`, `WinningNumber`); `audit()` gained `tables_read_only`; `guarantees()` gained `result_integrity`. No existing method's behaviour changed on an untampered result. |
| `app/Exceptions/SettlementSimulationException.php` | 367 | Added `CODE_RESULT_TAMPERED` constant and the `resultTampered()` factory. Purely additive; no existing factory or code touched. |
| `config/database.php` | 98 | The mysql `options` SSL CA key now prefers `Pdo\Mysql::ATTR_SSL_CA`, guarded by `class_exists()`, with a `constant()` fallback for PHP 8.2–8.4. Same attribute id, zero behaviour change. |

### Migrations

**None.** 23 before, 23 after. `php artisan migrate` → `Nothing to migrate.`
No column was added, altered, renamed or dropped. No table was created.

### Compatibility impact

| Area | Impact |
|---|---|
| Existing rows | None. No schema change, no data migration, no backfill. |
| Phase 1–2.2 (users, wallets, finance, ledger) | Untouched. Zero imports from those services in any changed file. |
| Phase 3.1 (risk, number limits) | Untouched. |
| Phase 4.1–4.2 (bet types, market rules, match services) | Untouched. Every settlement decision still comes from the verified match services and every rate from configuration. |
| Phase 4.3 (atomic purchase) | Untouched. All new tests buy through the real `BetPurchaseService`. |
| Phase 4.4 (secure API) | Untouched. 4 routes before, 4 after. |
| Phase 5.1 (lifecycle, publication, settlement) | One additive guard on the shared result loader. Every result published by the verified pipeline passes it by construction. |
| Public API of any class | No signature changed. No constructor changed. No method removed. |
| PHP version range | `config/database.php` now runs correctly on PHP 8.2 through 8.5, where previously it emitted a deprecation on 8.5. |
| Behaviour change, total | Exactly one: a draw whose stored result has been edited out-of-band is now **refused** instead of silently mis-settled. |

---

## 13. Remaining limitations (stated honestly)

1. **SCHEMA LIMITATION — RESULT IMMUTABILITY.** The new guard is application-level. A
   *consistent* out-of-band edit of both `draw_results` and `winning_numbers` is still
   undetectable. Closing this requires a DB trigger, an append-only versioned design, or a
   privilege change. See §2.6. **Not fixed. Requires an explicit future specification.**
2. **CARRIED-FORWARD DEBT — the `PDO::MYSQL_ATTR_SSL_CA` notice.** Originates in
   `vendor/laravel/framework/config/database.php` lines 61 and 81. `v11.56.1` is the latest
   11.x release, so no patch upgrade fixes it. Requires a major framework upgrade. See §4.3.
   **Not fixed by design.**
3. **LIMITATION L5 — `bet_items.payout_multiplier` is `unsignedInteger`.** Safe for all six
   current whole-number rates, and a fractional rate is refused rather than truncated. A
   fractional rate in future needs a `decimal(20,4)` migration plus cast/DTO/service/test
   updates. See §3.4. **Correctly out of scope.**
4. **No fractional stakes exist to test.** `amount_step = 1.00`, minimum `10.00`. The
   arithmetic is exact regardless, but the brief's "fractional stake examples already
   supported by the project" is an empty set today. See §6.
5. **`app/Enums/DrawStatus.php` still lacks `declare(strict_types=1)`.** It is a pre-existing
   Phase 1 file that Phase 5.1 only appended a case to. Adding the declaration is an
   unrelated edit to verified code that could change type-coercion behaviour for every
   existing caller of that enum, so it was deliberately **not** made. The audit therefore
   requires the declaration in the **13 files Phase 5.1 created** (13/13) and excludes this
   one, which the script documents inline. **Deliberately unfixed; low risk; flag it if you
   want it changed and it should be its own reviewed change.**
6. **Static audit is a text scan.** It strips comments and, where needed, string-literal
   contents with PHP's own tokeniser, and the suite carries a negative control that feeds the
   detector `1.5`, `(float)` and `round(1.4)` to prove it is not silently broken. It is still
   a scan, not a proof. The behavioural tests are the primary evidence.

---

## 14. Phase 5.2 boundary

**This follow-up is complete and work has STOPPED here.** Phase 5.2 has **not** been started.

Not built, and not to be built without an explicit specification:

- payment gateway, deposits, withdrawals, real-money payout processing, crypto settlement,
  financial transfers;
- agent commission;
- Filament admin;
- WebSocket / Reverb;
- frontend UI;
- any Phase 6+ feature.

Open decisions handed back to you, in priority order:

1. Whether to close the result-immutability limitation at the database level, and with which
   of the three approaches in §2.6. **Recommendation: the trigger, option 1.**
2. Whether a fractional payout multiplier is ever expected. If yes, the L5 migration should
   be specified before more settlement code is built on top of the integer column.
3. Whether a Laravel major upgrade is on the roadmap, which would clear the deprecation
   notice and let the suite report clean `✓` passes.

---

## 15. Appendix A — complete source of every file created or modified

Complete and unabridged. No placeholders, no elisions, no "existing code here".

Contents:

- A.1 `config/database.php` (modified, 98 lines)
- A.2 `app/Exceptions/SettlementSimulationException.php` (modified, 367 lines)
- A.3 `app/Services/Draw/DrawSettlementSimulationService.php` (modified, 793 lines)
- A.4 `tests/Feature/Settlement/Phase51FollowUpTest.php` (created, 679 lines)
- A.5 `tests/Unit/Config/DatabaseDriverOptionsTest.php` (created, 196 lines)
- A.6 `audit_phase51_followup.sh` (static audit script, kept outside the project)

---

### A.1 — `config/database.php` (MODIFIED, 98 lines)

```php
<?php

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'thai_lottery'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                // PHP 8.5 deprecated the PDO::MYSQL_* class constants in favour of the
                // dedicated Pdo\Mysql class. Both names resolve to the same integer
                // attribute id (1008), so preferring the new name preserves the
                // connection behaviour exactly while removing the deprecation notice.
                //
                // The fallback is reached only on PHP 8.2 to 8.4, where Pdo\Mysql does
                // not exist and the old constant is not deprecated. It is fetched with
                // constant() rather than written as PDO::MYSQL_ATTR_SSL_CA so that the
                // deprecated name appears nowhere in this file as a compiled constant
                // reference on a PHP version that would warn about it.
                (class_exists(\Pdo\Mysql::class)
                    ? \Pdo\Mysql::ATTR_SSL_CA
                    : constant('PDO::MYSQL_ATTR_SSL_CA')) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'thai_lottery'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'thai_lottery_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

];
```

---

### A.2 — `app/Exceptions/SettlementSimulationException.php` (MODIFIED, 367 lines)

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused simulated settlement run.
 *
 * Thrown when the run cannot be completed correctly and must roll back whole:
 * a selection whose market cannot be resolved, a recorded market that contradicts
 * the market derived from the bet type and position, a configured multiplier that
 * cannot be stored exactly in the existing column, a caller supplied prize amount,
 * or a lock that could not be acquired.
 *
 * WHY A NEW CLASS AND NOT AN EXISTING ONE
 * ---------------------------------------
 * App\Exceptions\PayoutException (if reached for) describes a REAL MONEY payout
 * failure and is caught by finance code that then compensates a wallet. A simulated
 * settlement has nothing to compensate, and must never be routed into a code path
 * that touches a balance.
 *
 * App\Exceptions\BetDomainException carries App\Enums\BetValidationCode, whose cases
 * describe a bet being PURCHASED. Settlement happens long after purchase and its
 * failures have no BetValidationCode.
 *
 * ROLLBACK CONTRACT
 * Every factory here names a condition detected INSIDE the settlement transaction,
 * while the draw row is held with SELECT ... FOR UPDATE. Throwing therefore unwinds
 * the whole run: no bet_items row keeps a partial is_winner, no bet keeps a partial
 * status, and the draw stays in result_published rather than becoming settled. This
 * class holds no models and issues no queries, so throwing it is always safe from
 * inside that transaction.
 *
 * NON-MONETARY
 * No factory refers to a wallet, a balance, a ledger account or a financial
 * transaction, because a simulated settlement never touches one. simulatedOnly() is
 * the explicit guard for the case where something asks settlement to move money.
 *
 * SECURITY
 * Context carries ids and market keys. No stack trace, SQL, credential or personal
 * datum is ever placed in it.
 */
class SettlementSimulationException extends RuntimeException
{
    public const CODE_MARKET_UNRESOLVED = 'SETTLEMENT_MARKET_UNRESOLVED';

    public const CODE_MARKET_MISMATCH = 'SETTLEMENT_MARKET_MISMATCH';

    public const CODE_MULTIPLIER_UNSTORABLE = 'SETTLEMENT_MULTIPLIER_UNSTORABLE';

    public const CODE_PAYOUT_UNSTORABLE = 'SETTLEMENT_PAYOUT_UNSTORABLE';

    public const CODE_CLIENT_SUPPLIED_PAYOUT = 'SETTLEMENT_CLIENT_SUPPLIED_PAYOUT';

    public const CODE_SIMULATED_ONLY = 'SETTLEMENT_SIMULATED_ONLY';

    public const CODE_LOCK_UNAVAILABLE = 'SETTLEMENT_LOCK_UNAVAILABLE';

    public const CODE_ALREADY_RUNNING = 'SETTLEMENT_ALREADY_RUNNING';

    public const CODE_SELECTION_UNREADABLE = 'SETTLEMENT_SELECTION_UNREADABLE';

    public const CODE_RESULT_TAMPERED = 'SETTLEMENT_RESULT_TAMPERED';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A selection carries no resolvable market.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function marketUnresolved(
        int $betItemId,
        ?string $attempted,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'Selection %d has no resolvable market (attempted key: %s), so the settlement run is '
                .'refused rather than guessing one.',
                $betItemId,
                $attempted === null || $attempted === '' ? 'none recorded' : '"'.$attempted.'"',
            ),
            self::CODE_MARKET_UNRESOLVED,
            $context + [
                'bet_item_id' => $betItemId,
                'attempted_market' => $attempted,
            ],
            $previous,
        );
    }

    /**
     * The market recorded on a selection contradicts the market derived from its
     * stored bet type and position.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function marketMismatch(
        int $betItemId,
        string $recorded,
        string $derived,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Selection %d records market "%s" but its stored bet type and position derive market '
                .'"%s". The run is refused because settling under either market could be wrong.',
                $betItemId,
                $recorded,
                $derived,
            ),
            self::CODE_MARKET_MISMATCH,
            $context + [
                'bet_item_id' => $betItemId,
                'recorded_market' => $recorded,
                'derived_market' => $derived,
            ],
        );
    }

    /**
     * The configured multiplier cannot be written to the integer column without loss.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function multiplierUnstorable(
        int $betItemId,
        string $market,
        string $multiplier,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'The configured multiplier %s for market "%s" cannot be stored exactly in the integer '
                .'payout_multiplier column of selection %d. The run is refused rather than rounding '
                .'or truncating it.',
                $multiplier,
                $market,
                $betItemId,
            ),
            self::CODE_MULTIPLIER_UNSTORABLE,
            $context + [
                'bet_item_id' => $betItemId,
                'market' => $market,
                'multiplier' => $multiplier,
            ],
            $previous,
        );
    }

    /**
     * The simulated prize does not fit the decimal(20,2) column exactly.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function payoutUnstorable(
        int $betItemId,
        string $amount,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'The calculated simulated prize %s for selection %d cannot be stored exactly in a '
                .'decimal(20,2) column. The run is refused rather than rounding it.',
                $amount,
                $betItemId,
            ),
            self::CODE_PAYOUT_UNSTORABLE,
            $context + [
                'bet_item_id' => $betItemId,
                'amount' => $amount,
            ],
            $previous,
        );
    }

    /**
     * A caller tried to dictate a prize amount, a winner or a multiplier.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function clientSuppliedPayout(string $field, array $context = []): self
    {
        return new self(
            sprintf(
                'The field "%s" was supplied to the settlement run. Winning status, multipliers and '
                .'simulated prize amounts are computed server side from the published result, the '
                .'verified market rules and configuration. Caller supplied values are refused '
                .'outright and there is no override, force or bypass parameter.',
                $field,
            ),
            self::CODE_CLIENT_SUPPLIED_PAYOUT,
            $context + ['field' => $field],
        );
    }

    /**
     * Something asked settlement to perform a real money effect.
     *
     * A defensive guard. Phase 5.1 contains no code path that credits a wallet,
     * writes a ledger entry, creates a financial transaction or calls a payment
     * gateway, so this should be unreachable; it exists so that a future edit which
     * tries to add one fails loudly.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function simulatedOnly(string $attemptedEffect, array $context = []): self
    {
        return new self(
            sprintf(
                'Refused: "%s". Draw settlement is a NON-MONETARY SIMULATION. It records a simulated '
                .'prize amount for audit and never credits or debits a wallet, writes a ledger entry, '
                .'creates a financial transaction, creates a payouts row or calls a payment gateway.',
                $attemptedEffect,
            ),
            self::CODE_SIMULATED_ONLY,
            $context + ['attempted_effect' => $attemptedEffect],
        );
    }

    /**
     * The draw row lock could not be acquired.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function lockUnavailable(int $drawId, array $context = [], ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Could not acquire the settlement lock on draw %d. A concurrent settlement run is '
                .'likely in progress; this run is refused and wrote nothing.',
                $drawId,
            ),
            self::CODE_LOCK_UNAVAILABLE,
            $context + ['draw_id' => $drawId],
            $previous,
        );
    }

    /**
     * Settlement was invoked while another database transaction was already open.
     *
     * Mirrors the guard in App\Services\Betting\BetPurchaseTransactionService: a
     * settlement run must own its own transaction, or its rollback guarantee would
     * belong to an outer caller instead.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyRunning(int $drawId, int $transactionLevel, array $context = []): self
    {
        return new self(
            sprintf(
                'Simulated settlement of draw %d was invoked while a database transaction was already '
                .'open (level %d). Settlement must own its transaction so that a failure rolls the '
                .'whole run back, so this call is refused.',
                $drawId,
                $transactionLevel,
            ),
            self::CODE_ALREADY_RUNNING,
            $context + [
                'draw_id' => $drawId,
                'transaction_level' => $transactionLevel,
            ],
        );
    }

    /**
     * A selection is missing data settlement needs and cannot be read.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function selectionUnreadable(int $betItemId, string $reason, array $context = []): self
    {
        return new self(
            sprintf('Selection %d cannot be settled: %s. The run is refused.', $betItemId, $reason),
            self::CODE_SELECTION_UNREADABLE,
            $context + [
                'bet_item_id' => $betItemId,
                'reason' => $reason,
            ],
        );
    }

    /**
     * The published result of a draw no longer agrees with itself.
     *
     * The authoritative result of a draw is stored twice by design: the raw six
     * digit first prize plus the bottom two live on draw_results, and the derived
     * per market winning value of each configured market lives on winning_numbers.
     * Publication writes both inside one transaction, so immediately after
     * publication the two agree by construction.
     *
     * Neither table has a database level immutability constraint, so a later
     * UPDATE, a soft delete of a winning_numbers row, or an edit of the bottom two
     * inside draw_results.metadata can make the pair disagree. Settling against a
     * result in that condition would decide winners from a number that the
     * published record does not actually contain, so the run is refused instead.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function resultTampered(int $drawId, string $reason, array $context = []): self
    {
        return new self(
            sprintf(
                'The published result of draw %d is not self consistent: %s. The stored result and the '
                .'stored winning numbers must agree before settlement can decide any winner, so this '
                .'run is refused and wrote nothing.',
                $drawId,
                $reason,
            ),
            self::CODE_RESULT_TAMPERED,
            $context + [
                'draw_id' => $drawId,
                'reason' => $reason,
            ],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function contextValue(string $key): string|int|float|bool|null
    {
        return $this->context[$key] ?? null;
    }

    /**
     * @return array{type: string, error_code: string, message: string, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'type' => static::class,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
```

---

### A.3 — `app/Services/Draw/DrawSettlementSimulationService.php` (MODIFIED, 793 lines)

```php
<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\DrawResultData;
use App\DTOs\SettlementSelectionResult;
use App\DTOs\SettlementSimulationResult;
use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DrawLifecycleState;
use App\Enums\MarketResultType;
use App\Enums\RiskLevel;
use App\Enums\SettlementSimulationStatus;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\DrawResultValidationException;
use App\Exceptions\SettlementSimulationException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\WinningNumber;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;

/**
 * Settles every selection of a published draw as a NON-MONETARY SIMULATION.
 *
 * WHAT THIS IS NOT
 * It is not a payout processor. It does not credit a wallet, debit a wallet, create
 * a wallet hold, write a ledger entry, create a financial transaction, create a
 * payouts row, create a deposit, create a withdrawal, call a payment gateway or
 * transfer crypto. There is no import of anything under App\Services\Finance except
 * the pure BCMath value type App\Services\Finance\Money reached indirectly through
 * App\ValueObjects\BetAmount, and bets.payout_id is left NULL forever, because a
 * payouts row is a REAL MONEY obligation carrying a wallet_id and a
 * financial_transaction_id.
 *
 * MODE IS A CONSTANT, NOT A SETTING
 * self::MODE is 'simulation' and is a class constant. There is deliberately no
 * configuration key, environment variable or method parameter that could switch this
 * service into a real money mode, so no deployment can turn it into one by accident.
 *
 * WHAT IT WRITES, AND WHY EACH IS NOT MONEY
 *   bet_items.is_winner           the decision, a reporting flag on the bet line
 *   bet_items.actual_payout       the SIMULATED prize, a reporting decimal column
 *   bet_items.payout_multiplier   the configured rate that was applied
 *   bets.status                   Won or Lost
 *   bets.actual_payout            the summed simulated prize of the bet's selections
 *   bets.won_at                   when a winning bet was settled
 *   draws.status                  ResultPublished -> Settled, plus completed_at
 *   audit_logs                    one row recording the run
 * None of those is a balance, a ledger account, a financial transaction or a payout
 * obligation. The wallets, financial_transactions, ledger_entries, ledger_accounts,
 * payouts, deposits, withdrawals, payments and agent_commissions tables are NEVER
 * written by this service. wallets.balance, wallets.locked_balance and wallets.total_won
 * are left exactly as the purchase left them.
 *
 * ATOMIC: ALL OR NOTHING
 * The whole run is one database transaction. A failure anywhere - an unresolvable
 * market, a contradictory market, a rate that will not fit its column, a stake that
 * cannot be read - rolls back every bet_items update, every bets update and the state
 * change together. There is no partial settlement. Because the rollback guarantee
 * must belong to this service, it REFUSES to run inside a caller's transaction, the
 * same guard the verified App\Services\Betting\BetPurchaseTransactionService applies.
 *
 * IDEMPOTENT, FROM THE DATABASE AND NOT FROM A CACHE
 * Settled is a terminal lifecycle state reachable only from ResultPublished. A second
 * call locks the draw, sees Settled, WRITES NOTHING, and returns the stored outcome
 * with alreadySettled = true and selectionsWritten = 0. Nothing here consults a cache,
 * a lock file or an in-memory flag. The guarantee rests on the stored draw status, the
 * row lock, and the unique keys on draw_results.draw_id and winning_numbers.
 *
 * CONCURRENCY
 * Two simultaneous runs serialise on SELECT ... FOR UPDATE of the draw row. The first
 * settles and commits; the second then reads Settled and becomes the no-op path. Lock
 * order is DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS, strictly narrower than the
 * existing finance order WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS, and disjoint
 * from it, so it cannot deadlock against a purchase.
 *
 * NO CLIENT INPUT AT ALL
 * settle() takes an integer draw id and nothing else. There is no amount parameter,
 * no winner parameter, no multiplier parameter and no force, override, skip or bypass
 * parameter. Decisions come from the verified Phase 4.2 rules, prizes from
 * configuration, and the drawn numbers from the published draw_results row.
 *
 * NO RAW SQL
 * Every read and write goes through Eloquent. There is no DB::statement, DB::raw,
 * DB::select, DB::update or DB::unprepared call.
 */
class DrawSettlementSimulationService
{
    /**
     * The only mode this service has. A constant, so nothing can change it.
     */
    public const MODE = 'simulation';

    /**
     * Tables this service is forbidden to write, named so the intent is testable.
     *
     * Every name here is a table this project actually creates, so a test can assert
     * both that the table exists and that the run left it untouched.
     *
     * @var list<string>
     */
    public const FORBIDDEN_TABLES = [
        'wallets',
        'financial_transactions',
        'ledger_entries',
        'ledger_accounts',
        'payouts',
        'deposits',
        'withdrawals',
        'payments',
        'agent_commissions',
    ];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DrawLifecycleService $lifecycle,
        private readonly DrawResultValidator $validator,
        private readonly SelectionSettlementResolver $selections,
    ) {}

    /**
     * Settle one published draw as a simulation.
     *
     * Takes a draw id and nothing else, on purpose.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    public function settle(int $drawId): SettlementSimulationResult
    {
        if (DB::transactionLevel() > 0) {
            // The rollback guarantee must belong to this run. Inside a caller's
            // transaction a failure here could be swallowed by an outer catch and the
            // partial writes committed anyway.
            throw SettlementSimulationException::alreadyRunning($drawId, DB::transactionLevel());
        }

        return DB::transaction(function () use ($drawId): SettlementSimulationResult {
            $draw = $this->lifecycle->lockForUpdate($drawId);
            $stateBefore = $this->lifecycle->currentState($draw);

            if ($stateBefore->isSettled()) {
                // Already settled: read back, write nothing.
                return $this->replayStoredSettlement($draw, $stateBefore);
            }

            $this->lifecycle->assertCanSettle($draw, ['stage' => 'settlement']);

            return $this->performSettlement($draw, $stateBefore);
        });
    }

    /**
     * The stored outcome of an already settled draw, without writing.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    public function storedSettlement(int $drawId): SettlementSimulationResult
    {
        $draw = Draw::query()->whereKey($drawId)->first();

        if (! $draw instanceof Draw) {
            throw DrawLifecycleException::notFound($drawId);
        }

        $state = $this->lifecycle->currentState($draw);

        if (! $state->isSettled()) {
            throw DrawLifecycleException::notSettleable($drawId, $state, ['stage' => 'stored_settlement']);
        }

        return $this->replayStoredSettlement($draw, $state);
    }

    /**
     * The published result of a draw, rebuilt as a validated value object.
     *
     * Re-validated on the way out of the database rather than trusted, so a row that
     * was somehow written with a malformed first prize refuses settlement instead of
     * settling every selection against a broken value.
     *
     * @throws DrawLifecycleException
     * @throws DrawResultValidationException
     */
    public function publishedResultFor(Draw $draw): DrawResultData
    {
        $drawId = (int) $draw->getKey();

        $result = DrawResult::query()->where('draw_id', $drawId)->first();

        if (! $result instanceof DrawResult) {
            throw DrawLifecycleException::resultMissing($drawId, $this->lifecycle->currentState($draw));
        }

        $metadata = is_array($result->metadata) ? $result->metadata : [];
        $bottomTwoKey = $this->validator->bottomTwoMetadataKey();

        $data = $this->validator->validate([
            'first_prize' => $result->first_prize,
            'bottom_two' => $metadata[$bottomTwoKey] ?? null,
        ]);

        $this->assertResultIntegrity($drawId, $data);

        return $data;
    }

    /**
     * Refuse a result that no longer agrees with its own published winning numbers.
     *
     * WHY THIS EXISTS
     * ---------------
     * The authoritative result of a draw is deliberately stored in two places.
     * draw_results holds the raw six digit first prize and, in metadata, the bottom
     * two. winning_numbers holds the DERIVED per market winning value of every
     * configured market. App\Services\Draw\DrawResultPublicationService writes both
     * inside a single transaction, so the moment publication commits, the derived
     * values in winning_numbers are exactly what this method recomputes.
     *
     * Neither table carries a database level immutability constraint, and
     * App\Models\WinningNumber is soft deletable. So an UPDATE on draw_results, an
     * edit of the bottom two inside draw_results.metadata, an UPDATE on a
     * winning_numbers row, or a soft delete of one, can all leave the pair
     * disagreeing. Settling in that condition would decide winners from a number
     * the published record does not contain, and the replay path would then compare
     * stored bet_items against a different result than the one they were settled
     * from. Both paths call publishedResultFor(), so both are covered by this one
     * check.
     *
     * The check is read only and issues one SELECT. It changes no passing rule: for
     * every result published through DrawResultPublicationService it passes by
     * construction, which the Phase 5.1 suite plus the follow-up suite both prove.
     *
     * @throws SettlementSimulationException
     */
    private function assertResultIntegrity(int $drawId, DrawResultData $data): void
    {
        /** @var list<WinningNumber> $rows */
        $rows = WinningNumber::query()
            ->where('draw_id', $drawId)
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            throw SettlementSimulationException::resultTampered(
                $drawId,
                'no published winning number row exists for it, so the published result cannot be '
                .'confirmed against the per market numbers it was published with',
            );
        }

        $tiersSeen = [];

        foreach ($rows as $row) {
            $tier = $row->prize_tier;

            $resultType = is_string($tier) ? MarketResultType::tryFrom($tier) : null;

            if (! $resultType instanceof MarketResultType) {
                throw SettlementSimulationException::resultTampered(
                    $drawId,
                    sprintf(
                        'published winning number %d carries prize tier %s, which is not a known market '
                        .'result type',
                        (int) $row->getKey(),
                        $tier === null ? 'NULL' : $tier,
                    ),
                    ['winning_number_id' => (int) $row->getKey()],
                );
            }

            $tiersSeen[$resultType->value] = true;

            $expected = $data->valueFor($resultType);
            $stored = (string) $row->number;

            // Strict string comparison. '07' and '7' are different published numbers
            // and a numeric comparison would call them equal.
            if ($stored !== $expected) {
                throw SettlementSimulationException::resultTampered(
                    $drawId,
                    sprintf(
                        'published winning number %d for prize tier %s stores %s, but the stored result '
                        .'derives %s for that tier',
                        (int) $row->getKey(),
                        $resultType->value,
                        $stored,
                        $expected,
                    ),
                    [
                        'winning_number_id' => (int) $row->getKey(),
                        'prize_tier' => $resultType->value,
                        'stored_number' => $stored,
                        'derived_number' => $expected,
                    ],
                );
            }
        }

        foreach (MarketResultType::cases() as $resultType) {
            if (! isset($tiersSeen[$resultType->value])) {
                throw SettlementSimulationException::resultTampered(
                    $drawId,
                    sprintf(
                        'no published winning number remains for prize tier %s, so a market of that tier '
                        .'has no official number to be settled against',
                        $resultType->value,
                    ),
                    ['missing_prize_tier' => $resultType->value],
                );
            }
        }
    }

    /**
     * The settlement rules in report form.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        return [
            'mode' => self::MODE,
            'mode_is_constant' => true,
            'mode_config_key' => null,
            'requires_state' => DrawLifecycleState::ResultPublished->value,
            'produces_state' => DrawLifecycleState::Settled->value,
            'tables_written' => ['bet_items', 'bets', 'draws', 'audit_logs'],
            'tables_read_only' => ['draw_results', 'winning_numbers', 'markets', 'market_rules'],
            'tables_forbidden' => self::FORBIDDEN_TABLES,
            'bets_payout_id' => 'left NULL always; a payouts row is a real money obligation',
            'lock_order' => 'DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS',
            'idempotency_source' => 'the stored draws.status plus SELECT ... FOR UPDATE; no cache',
            'guarantees' => $this->guarantees(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'non_monetary' => 'No wallet balance is credited or debited, no wallet hold is created, no '
                .'ledger entry is written, no financial transaction is created, no payouts row is '
                .'created, no deposit or withdrawal is created and no payment gateway is called.',
            'mode_constant' => 'self::MODE is a class constant equal to "simulation". There is no '
                .'configuration key, environment variable or parameter that can change it.',
            'atomic' => 'The entire run is one transaction. A failure rolls back every bet_items '
                .'update, every bets update and the state change together.',
            'own_transaction' => 'The run refuses to start inside a caller transaction, so its rollback '
                .'guarantee cannot be captured by an outer catch.',
            'idempotent' => 'A second run locks the draw, sees the terminal Settled state, writes '
                .'nothing and returns the stored outcome. The guarantee comes from the database, not '
                .'from a cache.',
            'concurrent_safe' => 'Concurrent runs serialise on SELECT ... FOR UPDATE of the draw row; '
                .'the loser becomes the no-op path.',
            'no_client_input' => 'settle() accepts a draw id only. There is no amount, winner, '
                .'multiplier, force, override, skip or bypass parameter.',
            'verified_rules' => 'Every decision comes from the Phase 4.2 match services and every rate '
                .'from configuration through MarketPayoutService.',
            'exact_decimals' => 'Totals are summed with bcadd on decimal strings. There is no float '
                .'cast, intval(), floatval() or round() in this class.',
            'no_raw_sql' => 'Every statement goes through Eloquent; there is no DB::statement, DB::raw, '
                .'DB::select, DB::update or DB::unprepared call.',
            'no_leakage' => 'Failures carry a stable error code and safe identifiers only; no stack '
                .'trace, SQL string or credential is placed in the context.',
            'result_integrity' => 'Before any winner is decided, the stored result is recomputed into '
                .'its per market winning values and compared as strings against the published '
                .'winning_numbers rows. A disagreement, an unknown prize tier or a missing tier '
                .'refuses the run. The comparison is a string comparison, so 07 and 7 are different.',
        ];
    }

    /**
     * Do the settlement. Called with the draw already locked, inside the transaction.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    private function performSettlement(Draw $draw, DrawLifecycleState $stateBefore): SettlementSimulationResult
    {
        $drawId = (int) $draw->getKey();
        $result = $this->publishedResultFor($draw);

        $records = [];
        $selectionsWritten = 0;
        $betsUpdated = 0;
        $winners = 0;
        $totalStake = '0.00';
        $totalPrize = '0.00';
        $currency = null;

        foreach ($this->lockedBets($drawId) as $bet) {
            $betPrize = '0.00';
            $betHasWinner = false;
            $betTouched = false;

            $currency = $this->assertSingleCurrency($drawId, $currency, $bet);

            foreach ($this->lockedItems((int) $bet->getKey()) as $item) {
                $record = $this->selections->resolve($item, $bet, $result);

                $this->writeSelection($item, $record);

                $records[] = $record;
                $selectionsWritten++;
                $betTouched = true;

                $totalStake = bcadd($totalStake, $record->stake, 2);
                $totalPrize = bcadd($totalPrize, $record->simulatedPrize, 2);
                $betPrize = bcadd($betPrize, $record->simulatedPrize, 2);

                if ($record->isWinner()) {
                    $betHasWinner = true;
                    $winners++;
                }
            }

            if ($betTouched) {
                $this->writeBet($bet, $betHasWinner, $betPrize);
                $betsUpdated++;
            }
        }

        $draw = $this->lifecycle->markSettled($draw, [
            'stage' => 'settlement',
            'mode' => self::MODE,
            'selections' => $selectionsWritten,
        ]);

        $stateAfter = $this->lifecycle->currentState($draw);

        $simulation = new SettlementSimulationResult(
            drawId: $drawId,
            drawNumber: (string) $draw->draw_number,
            stateBefore: $stateBefore,
            stateAfter: $stateAfter,
            firstPrize: $result->firstPrize(),
            bottomTwo: $result->bottomTwo(),
            selections: $records,
            selectionsEvaluated: count($records),
            selectionsWritten: $selectionsWritten,
            betsUpdated: $betsUpdated,
            winningSelections: $winners,
            totalStake: $totalStake,
            totalSimulatedPrize: $totalPrize,
            currency: ($currency ?? $this->defaultCurrency())->value,
            alreadySettled: false,
            mode: self::MODE,
            settledAt: now()->toIso8601String(),
            context: [
                'lock_order' => 'DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS',
                'wrote_wallet' => false,
                'wrote_ledger' => false,
                'wrote_financial_transaction' => false,
                'wrote_payout' => false,
            ],
        );

        $this->recordAudit($draw, $simulation);

        return $simulation;
    }

    /**
     * Rebuild the outcome of an already settled draw, writing nothing.
     *
     * The selections are recomputed from the published result through the same
     * resolver, which performs no writes, and each recomputed record is checked
     * against what is actually stored on the row. That makes the returned value
     * identical to the original run's, which is what
     * SettlementSimulationResult::idempotencyFingerprint() compares, and it turns any
     * drift between the stored outcome and the rules into a refusal rather than a
     * quietly different second answer.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    private function replayStoredSettlement(Draw $draw, DrawLifecycleState $state): SettlementSimulationResult
    {
        $drawId = (int) $draw->getKey();
        $result = $this->publishedResultFor($draw);

        $records = [];
        $winners = 0;
        $totalStake = '0.00';
        $totalPrize = '0.00';
        $currency = null;

        foreach ($this->betsFor($drawId) as $bet) {
            $currency = $this->assertSingleCurrency($drawId, $currency, $bet);

            foreach ($this->itemsFor((int) $bet->getKey()) as $item) {
                $record = $this->selections->resolve($item, $bet, $result);

                $this->assertStoredMatchesRecomputed($item, $record);

                $records[] = $record;
                $totalStake = bcadd($totalStake, $record->stake, 2);
                $totalPrize = bcadd($totalPrize, $record->simulatedPrize, 2);

                if ($record->isWinner()) {
                    $winners++;
                }
            }
        }

        return new SettlementSimulationResult(
            drawId: $drawId,
            drawNumber: (string) $draw->draw_number,
            stateBefore: $state,
            stateAfter: $state,
            firstPrize: $result->firstPrize(),
            bottomTwo: $result->bottomTwo(),
            selections: $records,
            selectionsEvaluated: count($records),
            // Zero, because this path wrote nothing at all.
            selectionsWritten: 0,
            betsUpdated: 0,
            winningSelections: $winners,
            totalStake: $totalStake,
            totalSimulatedPrize: $totalPrize,
            currency: ($currency ?? $this->defaultCurrency())->value,
            alreadySettled: true,
            mode: self::MODE,
            settledAt: $draw->completed_at?->toIso8601String() ?? now()->toIso8601String(),
            context: [
                'source' => 'stored settlement re-read; no row was written',
                'wrote_wallet' => false,
                'wrote_ledger' => false,
                'wrote_financial_transaction' => false,
                'wrote_payout' => false,
            ],
        );
    }

    /**
     * Write the three settlement columns of one selection.
     *
     * Assigned directly rather than through fill(), because is_winner,
     * actual_payout and payout_multiplier are excluded from BetItem::$fillable
     * precisely so that only a settlement service can set them.
     */
    private function writeSelection(BetItem $item, SettlementSelectionResult $record): void
    {
        $columns = $record->toBetItemColumns();

        $item->is_winner = $columns['is_winner'];
        $item->actual_payout = $columns['actual_payout'];
        $item->payout_multiplier = $columns['payout_multiplier'];

        $item->save();
    }

    /**
     * Write the settlement columns of one bet.
     *
     * bets.payout_id is deliberately NOT set. A payouts row carries a wallet_id and a
     * financial_transaction_id and is a real money obligation; a simulation creates
     * none, so the column stays NULL.
     */
    private function writeBet(Bet $bet, bool $hasWinner, string $simulatedPrize): void
    {
        $bet->status = $hasWinner ? BetStatus::Won : BetStatus::Lost;
        $bet->actual_payout = $simulatedPrize;

        if ($hasWinner && $bet->won_at === null) {
            $bet->won_at = now();
        }

        $bet->save();
    }

    /**
     * Refuse when a stored settlement disagrees with the recomputed one.
     *
     * @throws SettlementSimulationException
     */
    private function assertStoredMatchesRecomputed(BetItem $item, SettlementSelectionResult $record): void
    {
        $itemId = (int) $item->getKey();

        if ((bool) $item->is_winner !== $record->isWinner()) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                sprintf(
                    'the stored winner flag (%s) disagrees with the recomputed decision (%s) for '
                    .'market %s; the stored settlement is not reproducible and is reported rather '
                    .'than overwritten',
                    $item->is_winner ? 'true' : 'false',
                    $record->isWinner() ? 'true' : 'false',
                    $record->marketKey,
                ),
            );
        }

        if (bccomp((string) $item->actual_payout, $record->simulatedPrize, 2) !== 0) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                sprintf(
                    'the stored simulated prize %s disagrees with the recomputed %s for market %s; '
                    .'the stored settlement is not reproducible and is reported rather than overwritten',
                    (string) $item->actual_payout,
                    $record->simulatedPrize,
                    $record->marketKey,
                ),
            );
        }
    }

    /**
     * The bets of a draw, locked for update, in a stable order.
     *
     * Ordered by primary key so concurrent runs take the row locks in the same
     * sequence and cannot deadlock against one another.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Bet>
     */
    private function lockedBets(int $drawId)
    {
        return Bet::query()
            ->with('ticket')
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * The selections of a bet, locked for update, in a stable order.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BetItem>
     */
    private function lockedItems(int $betId)
    {
        return BetItem::query()
            ->where('bet_id', $betId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * The bets of a draw, unlocked, for the read-only replay path.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Bet>
     */
    private function betsFor(int $drawId)
    {
        return Bet::query()
            ->with('ticket')
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The selections of a bet, unlocked, for the read-only replay path.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BetItem>
     */
    private function itemsFor(int $betId)
    {
        return BetItem::query()
            ->where('bet_id', $betId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Keep the run to one currency.
     *
     * The run reports one total, so mixing currencies inside a draw would produce a
     * meaningless sum. Refused rather than silently added together.
     *
     * @throws SettlementSimulationException
     */
    private function assertSingleCurrency(int $drawId, ?Currency $current, Bet $bet): Currency
    {
        $betCurrency = $bet->currency;

        if (! $betCurrency instanceof Currency) {
            throw SettlementSimulationException::selectionUnreadable(
                (int) $bet->getKey(),
                'the bet has no usable currency',
                ['draw_id' => $drawId],
            );
        }

        if ($current !== null && $current !== $betCurrency) {
            throw SettlementSimulationException::selectionUnreadable(
                (int) $bet->getKey(),
                sprintf(
                    'draw %d mixes currencies (%s and %s); one settlement run reports one total, so '
                    .'this is refused rather than summed',
                    $drawId,
                    $current->value,
                    $betCurrency->value,
                ),
                ['draw_id' => $drawId],
            );
        }

        return $betCurrency;
    }

    /**
     * The configured default betting currency, used only when a draw has no bets.
     */
    private function defaultCurrency(): Currency
    {
        $configured = $this->config->get('lottery.betting.currency');

        if (is_string($configured)) {
            $currency = Currency::tryFrom($configured);

            if ($currency instanceof Currency) {
                return $currency;
            }
        }

        return Currency::THB;
    }

    /**
     * Record the run in audit_logs.
     *
     * AuditAction::Update is used, not AuditAction::Payout: Payout describes a real
     * money payout and this run pays nothing. The metadata states the simulation
     * explicitly so an auditor reading the log cannot mistake it for a payment.
     */
    private function recordAudit(Draw $draw, SettlementSimulationResult $simulation): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => Draw::class,
            'auditable_id' => $draw->getKey(),
            'description' => sprintf(
                'Simulated settlement of draw %s: %d selection(s) evaluated, %d winning, simulated '
                .'prize total %s %s. NON-MONETARY: no wallet, ledger entry, financial transaction or '
                .'payout was created.',
                (string) $draw->draw_number,
                $simulation->selectionsEvaluated,
                $simulation->winningSelections,
                $simulation->totalSimulatedPrize,
                $simulation->currency,
            ),
            'old_values' => ['status' => $simulation->stateBefore->toDrawStatus()->value],
            'new_values' => ['status' => $simulation->stateAfter->toDrawStatus()->value],
            'metadata' => [
                'mode' => self::MODE,
                'draw_id' => $simulation->drawId,
                'first_prize' => $simulation->firstPrize,
                'bottom_two' => $simulation->bottomTwo,
                'selections_evaluated' => $simulation->selectionsEvaluated,
                'selections_written' => $simulation->selectionsWritten,
                'bets_updated' => $simulation->betsUpdated,
                'winning_selections' => $simulation->winningSelections,
                'total_stake' => $simulation->totalStake,
                'total_simulated_prize' => $simulation->totalSimulatedPrize,
                'currency' => $simulation->currency,
                'status_counts' => $simulation->statusCounts(),
                'non_monetary' => true,
                'wallet_modified' => false,
                'ledger_modified' => false,
                'financial_transaction_created' => false,
                'payout_created' => false,
                'payment_gateway_called' => false,
                'settlement_statuses' => SettlementSimulationStatus::values(),
            ],
        ]);

        $log->save();
    }
}
```

---

### A.4 — `tests/Feature/Settlement/Phase51FollowUpTest.php` (CREATED, 679 lines)

```php
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
```

---

### A.5 — `tests/Unit/Config/DatabaseDriverOptionsTest.php` (CREATED, 196 lines)

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the project's own database configuration against the PHP 8.5 PDO
 * constant deprecation.
 *
 * WHAT WAS WRONG
 * --------------
 * PHP 8.5 deprecated the PDO::MYSQL_* class constants in favour of the dedicated
 * Pdo\Mysql class. config/database.php referenced PDO::MYSQL_ATTR_SSL_CA directly,
 * so simply loading the project's configuration emitted:
 *
 *   Constant PDO::MYSQL_ATTR_SSL_CA is deprecated since 8.5,
 *   use Pdo\Mysql::ATTR_SSL_CA instead
 *
 * WHAT WAS CHANGED
 * ----------------
 * The project file now prefers Pdo\Mysql::ATTR_SSL_CA when that class exists and
 * falls back to PDO::MYSQL_ATTR_SSL_CA when it does not. Both names resolve to the
 * same integer attribute id, 1008, so the connection behaviour is byte for byte
 * identical and the file still runs on PHP 8.2 to 8.4, where Pdo\Mysql is absent.
 *
 * WHAT IS STILL CARRIED FORWARD
 * -----------------------------
 * Laravel merges its own vendor/laravel/framework/config/database.php underneath
 * the application file, and that vendor file still references the deprecated
 * constant on lines 61 and 81. The remaining deprecation notice in the test run
 * therefore originates in the framework, not in this project, and cannot be removed
 * without a major framework upgrade. This test deliberately asserts only what the
 * project controls: no file under config/ may contain a compiled reference to a
 * deprecated PDO::MYSQL_* constant. The PHP 8.2 to 8.4 fallback in
 * config/database.php reaches its constant through constant() precisely so that no
 * such compiled reference exists, and this test also pins that the file prefers the
 * modern Pdo\Mysql name.
 */
final class DatabaseDriverOptionsTest extends TestCase
{
    /**
     * Both spellings name the same PDO attribute, so the swap changes no behaviour.
     */
    #[Test]
    public function the_modern_and_legacy_ssl_ca_attribute_ids_are_identical(): void
    {
        $this->assertTrue(
            class_exists(\Pdo\Mysql::class),
            'This assertion is only meaningful on a PHP build that provides Pdo\\Mysql.',
        );

        $this->assertSame(
            1008,
            \Pdo\Mysql::ATTR_SSL_CA,
            'Pdo\\Mysql::ATTR_SSL_CA must remain attribute id 1008.',
        );
    }

    /**
     * The resolved mysql option array is keyed by the SSL CA attribute id, or empty.
     *
     * The option is wrapped in array_filter(), so with no MYSQL_ATTR_SSL_CA set in
     * the environment the array is legitimately empty. Either shape is correct; a
     * key that is not the SSL CA attribute id is not.
     */
    #[Test]
    public function the_mysql_options_array_is_keyed_by_the_ssl_ca_attribute_id(): void
    {
        $options = config('database.connections.mysql.options');

        $this->assertIsArray($options);

        foreach (array_keys($options) as $key) {
            $this->assertSame(
                1008,
                $key,
                'The only option this project sets on the mysql connection is the SSL CA path.',
            );
        }
    }

    /**
     * No project configuration file may reference a deprecated PDO::MYSQL_* constant.
     *
     * Scans the real files rather than the resolved configuration, because the
     * deprecation is emitted at the moment the constant is referenced, which the
     * resolved array cannot show.
     */
    #[Test]
    public function no_project_config_file_references_a_deprecated_pdo_mysql_constant(): void
    {
        $directory = config_path();

        $this->assertDirectoryExists($directory);

        $files = glob($directory.'/*.php');

        $this->assertIsArray($files);
        $this->assertNotEmpty($files, 'The project must ship configuration files.');

        $offenders = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                $this->fail(sprintf('Could not read %s.', basename($file)));
            }

            // Comments and string literal contents are stripped first. A comment
            // that explains the deprecation, and the constant('PDO::...') fallback
            // whose argument is a plain string, are not compiled constant
            // references and must not be reported as offenders. What remains is
            // exactly the set of references PHP would resolve at compile time and
            // warn about.
            $code = '';

            $ignored = [
                T_COMMENT,
                T_DOC_COMMENT,
                T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,
            ];

            foreach (token_get_all($contents) as $token) {
                if (is_array($token) && in_array($token[0], $ignored, true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match('/PDO\s*::\s*MYSQL_/i', $code) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These config files still reference a PDO::MYSQL_* constant deprecated in PHP 8.5: '
                .implode(', ', $offenders),
        );
    }

    /**
     * config/database.php prefers the modern Pdo\Mysql attribute name.
     *
     * The previous test proves the deprecated name is absent. This one proves the
     * modern name is actually present, so the pair cannot both pass on a file that
     * simply dropped the option altogether.
     */
    #[Test]
    public function the_database_config_prefers_the_modern_pdo_mysql_attribute_name(): void
    {
        $file = config_path('database.php');

        $this->assertFileExists($file);

        $contents = file_get_contents($file);

        $this->assertIsString($contents);
        $this->assertStringContainsString(
            'Pdo\Mysql::ATTR_SSL_CA',
            $contents,
            'config/database.php must reach the SSL CA attribute through Pdo\Mysql on PHP 8.5.',
        );
        $this->assertStringContainsString(
            'class_exists(\Pdo\Mysql::class)',
            $contents,
            'The modern name must be guarded so the file still runs on PHP 8.2 to 8.4.',
        );
    }

    /**
     * The mysql connection still resolves and still points at the mysql driver.
     *
     * A configuration edit that silently broke the connection definition would be
     * far worse than the deprecation it removed.
     */
    #[Test]
    public function the_mysql_connection_definition_is_still_intact(): void
    {
        $connection = config('database.connections.mysql');

        $this->assertIsArray($connection);
        $this->assertSame('mysql', $connection['driver'] ?? null);
        $this->assertTrue($connection['strict'] ?? false, 'Strict mode must stay enabled.');
        $this->assertSame('utf8mb4', $connection['charset'] ?? null);
        $this->assertArrayHasKey('options', $connection);
    }
}
```

---

### A.6 — `audit_phase51_followup.sh` (STATIC AUDIT SCRIPT (outside the project), 386 lines)

```bash
#!/usr/bin/env bash
# =============================================================================
# PHASE 5.1 FOLLOW-UP STATIC SECURITY AUDIT  --  requirement I.10
#
# Kept OUTSIDE the project so it is not part of the delivered source tree.
#
#   bash /home/user/workspace/audit_phase51_followup.sh
#
# Every check reports its hit count. A check that is expected to be zero and is
# not zero is printed with its matches so nothing is hidden.
#
# IMPORTANT: comments and doc blocks are stripped with PHP's own tokeniser
# before the code is searched. Several Phase 5.1 classes document what they do
# NOT do ("there is no round() in this class"), and a naive grep would report
# those sentences as hits.
# =============================================================================
set -uo pipefail

PROJECT="/home/user/workspace/proj/Thai-lottery"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

FILES=(
  "app/Enums/DrawStatus.php"
  "app/Enums/DrawLifecycleState.php"
  "app/Enums/SettlementSimulationStatus.php"
  "app/Exceptions/DrawLifecycleException.php"
  "app/Exceptions/DrawResultValidationException.php"
  "app/Exceptions/SettlementSimulationException.php"
  "app/DTOs/DrawResultData.php"
  "app/DTOs/SettlementSelectionResult.php"
  "app/DTOs/SettlementSimulationResult.php"
  "app/Services/Draw/DrawLifecycleService.php"
  "app/Services/Draw/DrawResultValidator.php"
  "app/Services/Draw/DrawResultPublicationService.php"
  "app/Services/Draw/SelectionSettlementResolver.php"
  "app/Services/Draw/DrawSettlementSimulationService.php"
)

FAILURES=0
CHECK=0

echo "==============================================================="
echo " PHASE 5.1 FOLLOW-UP STATIC AUDIT"
echo " project : $PROJECT"
echo " files   : ${#FILES[@]} Phase 5.1 source files"
echo " date    : $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "==============================================================="
echo

# -----------------------------------------------------------------------------
# Build two stripped copies of each file:
#   $WORK/code/     comments removed        (for construct checks)
#   $WORK/exec/     comments AND string literal contents removed
#                   (for checks whose needle appears inside documentation
#                    strings the classes return from audit()/guarantees())
# -----------------------------------------------------------------------------
mkdir -p "$WORK/code" "$WORK/exec"

cd "$PROJECT" || { echo "FATAL: project not found"; exit 1; }

for f in "${FILES[@]}"; do
  if [ ! -f "$f" ]; then
    echo "FATAL: missing Phase 5.1 file: $f"
    exit 1
  fi

  flat="$(echo "$f" | tr '/' '_')"

  php -r '
    $src = file_get_contents($argv[1]);
    $code = ""; $exec = "";
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $text = is_array($t) ? $t[1] : $t;
        $code .= $text;
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $exec .= "@@STR@@";
        } else {
            $exec .= $text;
        }
    }
    file_put_contents($argv[2], $code);
    file_put_contents($argv[3], $exec);
  ' "$f" "$WORK/code/$flat" "$WORK/exec/$flat"
done

# $1 = label, $2 = dir (code|exec), $3 = ERE pattern
check() {
  CHECK=$((CHECK + 1))
  local label="$1" dir="$2" pattern="$3"
  local hits
  hits="$(grep -nE "$pattern" "$WORK/$dir"/* 2>/dev/null)"
  local n
  n="$(printf '%s' "$hits" | grep -c . )"

  if [ "$n" -eq 0 ]; then
    printf '  [ %2d ] %-62s %s\n' "$CHECK" "$label" "0 hits  OK"
  else
    printf '  [ %2d ] %-62s %s\n' "$CHECK" "$label" "$n hits  <-- REVIEW"
    printf '%s\n' "$hits" | sed 's#^'"$WORK"'/[a-z]*/#         #'
    FAILURES=$((FAILURES + 1))
  fi
}

echo "--- A. FLOATING POINT AND PRECISION (requirement B, I.27-29) ------------"
check "(float) cast"                              exec '\(float\)'
check "(double) cast"                             exec '\(double\)'
check "(real) cast"                               exec '\(real\)'
check "floatval()"                                exec '\bfloatval[[:space:]]*\('
check "doubleval()"                               exec '\bdoubleval[[:space:]]*\('
check "intval()"                                  exec '\bintval[[:space:]]*\('
check "round()"                                   exec '(^|[^_[:alnum:]])round[[:space:]]*\('
check "floor()"                                   exec '(^|[^_[:alnum:]])floor[[:space:]]*\('
check "ceil()"                                    exec '(^|[^_[:alnum:]])ceil[[:space:]]*\('
check "number_format()"                           exec '\bnumber_format[[:space:]]*\('
check "fdiv()"                                    exec '\bfdiv[[:space:]]*\('
check "settype()"                                 exec '\bsettype[[:space:]]*\('
check "float literal (digit.digit)"               exec '[^A-Za-z0-9_.@]-?[0-9]+\.[0-9]+'
check "float type declaration"                    exec '(:[[:space:]]*\??float|\bfloat[[:space:]]+\\\$)'
echo

echo "--- B. NATIVE ARITHMETIC IN THE MONEY PATH (requirement B) --------------"
CHECK=$((CHECK + 1))
ARITH="$(grep -nE '[^*/[:space:]][[:space:]]*[*/%][[:space:]]*[^*/[:space:]=]' \
  "$WORK/exec/app_Services_Draw_SelectionSettlementResolver.php" \
  "$WORK/exec/app_Services_Draw_DrawSettlementSimulationService.php" 2>/dev/null)"
ARITH_N="$(printf '%s' "$ARITH" | grep -c .)"
if [ "$ARITH_N" -eq 0 ]; then
  printf '  [ %2d ] %-62s %s\n' "$CHECK" "* / %% operator in the two calculating services" "0 hits  OK"
else
  printf '  [ %2d ] %-62s %s\n' "$CHECK" "* / %% operator in the two calculating services" "$ARITH_N hits  <-- REVIEW"
  printf '%s\n' "$ARITH" | sed 's#^'"$WORK"'/exec/#         #'
  FAILURES=$((FAILURES + 1))
fi

CHECK=$((CHECK + 1))
BC="$(grep -ohE '\bbc(add|sub|mul|div|comp|pow|mod|sqrt)[[:space:]]*\(' "$WORK/code"/* | sort | uniq -c | tr '\n' ' ')"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "BCMath is the arithmetic used instead" "${BC:-none}"
if [ -z "$BC" ]; then FAILURES=$((FAILURES + 1)); fi
echo

echo "--- C. REAL MONEY MUTATION (requirement G, I.21-24) --------------------"
check "wallet balance assignment"                 exec '(balance[[:space:]]*=|->balance)'
check "wallet increment/decrement"                exec '\b(increment|decrement)[[:space:]]*\('
check "Wallet model use"                          code 'use[[:space:]]+App\\Models\\Wallet'
check "FinancialTransaction model use"            code 'use[[:space:]]+App\\Models\\FinancialTransaction'
check "Ledger model use"                          code 'use[[:space:]]+App\\Models\\Ledger'
check "Payout model use"                          code 'use[[:space:]]+App\\Models\\Payout'
check "Deposit/Withdrawal/Payment model use"      code 'use[[:space:]]+App\\Models\\(Deposit|Withdrawal|Payment|AgentCommission)'
check "wallet/finance/payment service use"        code 'use[[:space:]]+App\\Services\\(Wallet|Finance|Ledger|Payment)'
check "credit/debit/transfer/refund method call"  exec '->(credit|debit|transfer|refund|payout|withdraw|deposit)[[:space:]]*\('
check "payout_id write"                           exec "payout_id'?[[:space:]]*=>"
echo

echo "--- D. PAYMENT GATEWAY AND NETWORK (requirement G, I.24) ---------------"
check "Laravel HTTP client"                        code 'Http::'
check "Guzzle"                                     code 'GuzzleHttp'
check "cURL"                                       code '\bcurl_[a-z]+[[:space:]]*\('
check "socket / stream transport"                  code '\b(fsockopen|stream_socket_client|socket_create)[[:space:]]*\('
check "remote file read"                           code '\bfile_get_contents[[:space:]]*\([[:space:]]*.https?'
check "named gateway SDK"                          code '(Stripe|PayPal|Omnipay|Razorpay|Midtrans|Adyen|Braintree)'
check "queue / bus / event handoff"                code '(dispatch[[:space:]]*\(|Bus::|Queue::|Event::dispatch)'
echo

echo "--- E. OVERRIDE AND BYPASS PATHS (requirement H) -----------------------"
check "force flag as identifier"                   exec '(\\\$force|[\x27\"]force|force[[:space:]]*:)'
check "override as identifier"                     exec '(\\\$override|override[[:space:]]*:)'
check "bypass as identifier"                       exec '(\\\$bypass|bypass[[:space:]]*:)'
check "skip_validation / skipValidation"           exec '(skip_validation|skipValidation)'
check "unsafe / no_check / ignore_rules"           exec '(unsafe|no_check|noCheck|ignore_rules|ignoreRules)'
check "admin override / debug mode"                exec '(admin_override|adminOverride|debug_mode|debugMode)'
echo

echo "--- F. CLIENT CONTROLLED OUTCOME (requirement H, I.30) -----------------"
check "user_id read from an array/request"         exec "(\\\$request|request\\(\\)|input\\()"
check "Auth facade or auth() helper"               exec '(Auth::|auth\(\))'
check "amount/prize/payout as a parameter"         exec '\\\$(amount|prize|payoutAmount|winner|winningNumber)\b'
check "multiplier supplied as a parameter"         exec 'function[^\(]*\([^)]*\\\$multiplier'
echo

echo "--- G. RAW SQL (requirement H) -----------------------------------------"
check "DB::statement / unprepared"                 exec 'DB::(statement|unprepared)'
check "DB::raw"                                    exec 'DB::raw'
check "DB::select / insert / update / delete"       exec 'DB::(select|insert|update|delete)'
check "whereRaw / selectRaw / orderByRaw"           exec '(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw)'
echo

echo "--- H. INFORMATION LEAKAGE (requirement H) -----------------------------"
check "stack trace exposure"                       exec '(getTraceAsString|getTrace[[:space:]]*\()'
check "previous exception exposure"                exec 'getPrevious[[:space:]]*\('
check "driver internals exposure"                  exec '(errorInfo|getBindings|getSql|PDOException)'
check "debug output"                               exec '(^|[^_[:alnum:]>])(dd|dump|var_dump|print_r|var_export|error_log)[[:space:]]*\('
echo

echo "--- I. POSITIVE INVARIANTS ---------------------------------------------"
CHECK=$((CHECK + 1))
MODE="$(grep -c "public const MODE = 'simulation';" "$WORK/code/app_Services_Draw_DrawSettlementSimulationService.php")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "MODE is a hard coded constant equal to 'simulation'" "$MODE declaration(s)"
[ "$MODE" -eq 1 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
TXN="$(grep -c 'DB::transaction' "$WORK/code/app_Services_Draw_DrawSettlementSimulationService.php")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "settlement runs inside DB::transaction" "$TXN occurrence(s)"
[ "$TXN" -ge 1 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
LOCK="$(grep -c 'lockForUpdate' "$WORK/code/app_Services_Draw_DrawLifecycleService.php")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "the draw row is locked with SELECT ... FOR UPDATE" "$LOCK occurrence(s)"
[ "$LOCK" -ge 1 ] || FAILURES=$((FAILURES + 1))

# app/Enums/DrawStatus.php is a PRE-EXISTING Phase 1 file that Phase 5.1 only
# appended a case to. It was already written without declare(strict_types=1) and
# adding one would be an unrelated change to verified code, so it is excluded
# here and the 13 files Phase 5.1 CREATED are required to have it.
CHECK=$((CHECK + 1))
CREATED=$(( ${#FILES[@]} - 1 ))
STRICT="$(grep -l 'declare(strict_types=1);' "$WORK/code"/* | grep -vc 'app_Enums_DrawStatus.php')"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "declare(strict_types=1) in every file Phase 5.1 created" "$STRICT/$CREATED"
[ "$STRICT" -eq "$CREATED" ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
MIG="$(ls -1 "$PROJECT/database/migrations" | wc -l)"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "migration count (Phase 4.4 baseline was 23)" "$MIG"
[ "$MIG" -eq 23 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
LINT_BAD=0
for f in "${FILES[@]}"; do
  php -l "$f" >/dev/null 2>&1 || LINT_BAD=$((LINT_BAD + 1))
done
printf '  [ %2d ] %-62s %s\n' "$CHECK" "php -l clean" "$((${#FILES[@]} - LINT_BAD))/${#FILES[@]}"
[ "$LINT_BAD" -eq 0 ] || FAILURES=$((FAILURES + 1))
echo

echo "--- J. PHASE 5.1 FOLLOW-UP FIXES ----------------------------------------"

SETTLE_CODE="$WORK/code/app_Services_Draw_DrawSettlementSimulationService.php"
EXC_CODE="$WORK/code/app_Exceptions_SettlementSimulationException.php"

CHECK=$((CHECK + 1))
GUARD="$(grep -c 'private function assertResultIntegrity' "$SETTLE_CODE")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "result integrity guard is declared" "$GUARD declaration(s)"
[ "$GUARD" -eq 1 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
CALLED="$(grep -c 'this->assertResultIntegrity(' "$SETTLE_CODE")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "guard is called from the single result loader" "$CALLED call site(s)"
[ "$CALLED" -eq 1 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
LOADER="$(grep -c 'this->publishedResultFor(' "$SETTLE_CODE")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "both settle paths go through that loader" "$LOADER call site(s)"
[ "$LOADER" -ge 2 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
TAMPCODE="$(grep -c "CODE_RESULT_TAMPERED = 'SETTLEMENT_RESULT_TAMPERED';" "$EXC_CODE")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "SETTLEMENT_RESULT_TAMPERED error code exists" "$TAMPCODE declaration(s)"
[ "$TAMPCODE" -eq 1 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
STRICTCMP="$(grep -c 'stored !== \$expected' "$SETTLE_CODE")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "guard compares numbers as strings with !==" "$STRICTCMP comparison(s)"
[ "$STRICTCMP" -eq 1 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
LOOSECMP="$(grep -cE '\$stored[[:space:]]*(!=|==)[^=]' "$SETTLE_CODE")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "guard uses no loose numeric comparison" "$LOOSECMP hit(s)"
[ "$LOOSECMP" -eq 0 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
DBCFG="$PROJECT/config/database.php"
CFGCODE="$(php -r '
  $src = file_get_contents($argv[1]);
  $out = "";
  foreach (token_get_all($src) as $t) {
      if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { continue; }
      $out .= is_array($t) ? $t[1] : $t;
  }
  echo $out;
' "$DBCFG" | grep -cE 'PDO[[:space:]]*::[[:space:]]*MYSQL_')"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "config/database.php has no compiled PDO::MYSQL_* ref" "$CFGCODE hit(s)"
[ "$CFGCODE" -eq 0 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
MODERN="$(grep -c 'Pdo\\Mysql::ATTR_SSL_CA' "$DBCFG")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "config/database.php prefers Pdo\\Mysql::ATTR_SSL_CA" "$MODERN reference(s)"
[ "$MODERN" -ge 1 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
GUARDED="$(grep -c 'class_exists(\\Pdo\\Mysql::class)' "$DBCFG")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "the modern name is guarded for PHP 8.2 to 8.4" "$GUARDED guard(s)"
[ "$GUARDED" -eq 1 ] || FAILURES=$((FAILURES + 1))

FOLLOWUP_TESTS=(
  "tests/Feature/Settlement/Phase51FollowUpTest.php"
  "tests/Unit/Config/DatabaseDriverOptionsTest.php"
)

CHECK=$((CHECK + 1))
MISSING=0
for t in "${FOLLOWUP_TESTS[@]}"; do
  [ -f "$PROJECT/$t" ] || MISSING=$((MISSING + 1))
done
printf '  [ %2d ] %-62s %s\n' "$CHECK" "follow-up test files present" "$((${#FOLLOWUP_TESTS[@]} - MISSING))/${#FOLLOWUP_TESTS[@]}"
[ "$MISSING" -eq 0 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
TSTRICT=0
for t in "${FOLLOWUP_TESTS[@]}"; do
  grep -q 'declare(strict_types=1);' "$PROJECT/$t" && TSTRICT=$((TSTRICT + 1))
done
printf '  [ %2d ] %-62s %s\n' "$CHECK" "declare(strict_types=1) in every follow-up test" "$TSTRICT/${#FOLLOWUP_TESTS[@]}"
[ "$TSTRICT" -eq "${#FOLLOWUP_TESTS[@]}" ] || FAILURES=$((FAILURES + 1))

# The follow-up tests are held to the same numeric discipline as the source: no
# float cast, no intval(), no round(). Comments and string literals are stripped
# first, because the tests document the forbidden constructs by name.
mkdir -p "$WORK/tests"
for t in "${FOLLOWUP_TESTS[@]}"; do
  tflat="$(echo "$t" | tr '/' '_')"
  php -r '
    $src = file_get_contents($argv[1]);
    $out = "";
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $text = is_array($t) ? $t[1] : $t;
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $out .= "@@STR@@";
        } else {
            $out .= $text;
        }
    }
    file_put_contents($argv[2], $out);
  ' "$PROJECT/$t" "$WORK/tests/$tflat"
done

tcheck() {
  CHECK=$((CHECK + 1))
  local label="$1" pattern="$2"
  local hits n
  hits="$(grep -nE "$pattern" "$WORK/tests"/* 2>/dev/null)"
  n="$(printf '%s' "$hits" | grep -c .)"
  if [ "$n" -eq 0 ]; then
    printf '  [ %2d ] %-62s %s\n' "$CHECK" "$label" "0 hits  OK"
  else
    printf '  [ %2d ] %-62s %s\n' "$CHECK" "$label" "$n hits  <-- REVIEW"
    printf '%s\n' "$hits" | sed 's#^'"$WORK"'/tests/#         #'
    FAILURES=$((FAILURES + 1))
  fi
}

tcheck "follow-up tests: no (float)/(double) cast"    '\((float|double|real)\)'
tcheck "follow-up tests: no intval/floatval/round"    '\b(intval|floatval|doubleval|round|number_format)[[:space:]]*\('
tcheck "follow-up tests: no real money model"         'App\\Models\\(Wallet|FinancialTransaction|Ledger|Payout|Deposit|Withdrawal|Payment)'
tcheck "follow-up tests: no force/override/bypass"    '(\$force|\$override|\$bypass|skipValidation|skip_validation)'
tcheck "follow-up tests: no raw SQL"                  'DB::(statement|unprepared|raw|select|insert|update|delete)'

CHECK=$((CHECK + 1))
NEGCTRL="$(grep -c "assertNotSame('1200.00'" "$PROJECT/tests/Feature/Settlement/Phase51FollowUpTest.php")"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "run tests assert the legacy 12x rate was NOT used" "$NEGCTRL assertion(s)"
[ "$NEGCTRL" -ge 2 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
NEWMIG="$(ls -1 "$PROJECT/database/migrations" | wc -l)"
printf '  [ %2d ] %-62s %s\n' "$CHECK" "the follow-up added no migration (still 23)" "$NEWMIG"
[ "$NEWMIG" -eq 23 ] || FAILURES=$((FAILURES + 1))

CHECK=$((CHECK + 1))
FLINT=0
for t in "${FOLLOWUP_TESTS[@]}"; do
  php -l "$PROJECT/$t" >/dev/null 2>&1 || FLINT=$((FLINT + 1))
done
php -l "$DBCFG" >/dev/null 2>&1 || FLINT=$((FLINT + 1))
printf '  [ %2d ] %-62s %s\n' "$CHECK" "php -l clean on every follow-up file" "$(( ${#FOLLOWUP_TESTS[@]} + 1 - FLINT ))/$(( ${#FOLLOWUP_TESTS[@]} + 1 ))"
[ "$FLINT" -eq 0 ] || FAILURES=$((FAILURES + 1))
echo

echo "==============================================================="
if [ "$FAILURES" -eq 0 ]; then
  echo " RESULT: PASS  --  $CHECK checks, 0 findings"
else
  echo " RESULT: $FAILURES check(s) need review  --  $CHECK checks total"
fi
echo "==============================================================="
exit 0
```
