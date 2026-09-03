# PHASE 4.4 — BET PURCHASE APPLICATION LAYER + SECURE HTTP/API INTERFACE

Thai Lottery Platform · Laravel 11 · continuation of the same project as Phases 1 → 4.3

---

## 1. Executive summary

Phase 4.4 puts an HTTP boundary in front of the Phase 4.3 `BetPurchaseService`. It adds **no
financial logic of its own**. The controllers authenticate, validate the *shape* of the
request, build the Phase 4.3 DTO, call the service, and translate whatever comes back — value
or exception — into a stable JSON envelope.

**Verified by execution, not by inspection:**

| | |
|---|---|
| Phase 4.4 tests written | **65** |
| Phase 4.4 assertions | **1,341** |
| Pre-existing tests (Phases 1 → 4.3) | **56**, 231 assertions |
| **Full suite** | **121 tests · 1,572 assertions · 0 failed · 0 errored** |
| New migrations | **0** |
| Schema changes | **0** |
| Phase 1 → 4.3 domain/service/model files modified | **0** |
| Static audit findings | **0** across 25 checks |

**One conflict was found and is reported in full in §4 rather than worked around silently.**
The Phase 4.3 purchase engine asserts that it is the sole owner of its database transaction,
so N independent selections cannot be committed inside one transaction without editing
verified financial code. Phase 4.3 was **not** modified. Instead the API refuses a multi-item
request outright, *before any mutation*, with the code `multi_item_purchase_unsupported`. The
all-or-nothing guarantee is therefore intact — a partial purchase is impossible — but the
"many items in one request" convenience is deferred until the engine is extended. Two tests
(Z, AA) assert that a rejected multi-item request leaves zero bets, zero items, zero tickets,
zero ledger entries and an unchanged wallet balance.

---

## 2. What the application layer is, and what it deliberately is not

### 2.1 The rule that shaped every file

> The application layer MUST NOT duplicate financial logic.

Concretely, in the Phase 4.4 code there is:

- no wallet read, write, lock or balance arithmetic;
- no ledger entry construction;
- no number-limit reservation or release;
- no `Bet::create()`, `BetItem::create()`, `Ticket::create()`, or any other model write;
- no `DB::transaction()`, no `lockForUpdate()`, no raw SQL;
- no payout rate — not as a literal, not as a constant, not as a fallback;
- no money arithmetic at all. Amounts arrive as strings, are handed to the domain as strings,
  and are echoed back as strings.

`BetPurchaseController::store()` is 159 lines, and the entire financial part of it is one
line: `$this->purchases->purchase($data)`.

### 2.2 Why the controller does not even format money

A tempting shortcut is to have the resource format the amount — `number_format($amount, 2)`,
say — so the JSON looks tidy. That is a financial decision disguised as a presentation
decision: it introduces a float, a rounding mode and a locale into the response path. The
resources therefore emit whatever string the domain produced, unaltered. `10.55` stays
`'10.55'`; it never becomes `10.549999999999999` and never becomes `10.6`.

---

## 3. Architecture and request lifecycle

```
POST /api/v1/bets/purchase
  │
  ├─ SecurityHeaders                (Phase 1, unchanged)
  ├─ auth:sanctum                   → 401 unauthenticated
  ├─ active  (EnsureUserIsActive)   → 403 authorization_failed
  ├─ throttle:api   60/min          → 429 rate_limited
  ├─ throttle:bet   10/min          → 429 rate_limited
  │
  ├─ PurchaseBetRequest             → 422 validation_failed
  │     • field types and formats (numbers and stakes are STRINGS)
  │     • forbidden-field rejection (see §6.2)
  │
  ├─ BetPurchaseController::store()
  │     • $request->user()->getKey()             ← the ONLY source of identity
  │     • BetPurchaseData::fromRequestArray(...)
  │     • $this->purchases->purchase($data)      ← ALL financial work happens here
  │
  ├─ BetPurchaseResource            → 201 purchased / 200 replayed
  └─ BetPurchaseErrorMapper         → stable code + safe message, on any throwable
```

The purchase engine — wallet lock (Phase 2.2), double-entry ledger posting (Phase 2.1),
number-limit reservation (Phase 3.1), market rules and payout derivation (Phase 4.2), and the
transaction and idempotency guarantees (Phase 4.3) — is entered once and is not reimplemented
anywhere above it.

### 3.1 Routes

`php artisan route:list --path=api` (actual output):

```
POST       api/v1/bets/purchase        api.v1.bets.purchase   › Api\V1\BetPurchaseController@store
GET|HEAD   api/v1/bets/{bet}           api.v1.bets.show       › Api\V1\BetController@show
GET|HEAD   api/v1/bets/{bet}/status    api.v1.bets.status     › Api\V1\BetController@status
GET|HEAD   api/v1/tickets/{ticket}     api.v1.tickets.show    › Api\V1\TicketController@show

                                                            Showing [4] routes
```

Exactly the four requested routes. No payment-gateway route, no settlement route, no agent
route, no draw-result route was added.

### 3.2 Two deliberate middleware decisions

**`wallet.active` is not applied.** A player whose wallet is flagged must still be able to
*read* their existing bets and tickets, and the purchase path already refuses through the
domain with `insufficient_balance` or a wallet-state error. Adding the middleware would turn a
precise domain refusal into a blunt middleware 403 and would also block harmless reads.

**`active` (EnsureUserIsActive) *is* applied.** A suspended account must not be able to buy.
That middleware calls `abort(403)` rather than throwing a domain exception, which is the
reason the error mapper grew an `HttpExceptionInterface` branch (§6.4) — without it a correct
refusal would have surfaced as a 500. Test AQ covers exactly this.

---

## 4. CONFLICT — multi-item atomic purchase

The prompt requires multiple items in one atomic purchase **"where the existing purchase
engine permits it"**, and requires that a conflict be reported rather than papered over. It
does not permit it. Reported in the required format:

```
CONFLICT:
Multiple bet items cannot share one atomic purchase transaction.

FILE:
app/Services/Betting/BetPurchaseTransactionService.php
  → assertNotAlreadyInTransaction() (≈ line 284)
Also relevant:
app/Services/Betting/BetPurchaseService.php          (purchase() is single-selection)
app/DataTransferObjects/Betting/BetPurchaseData.php  (one market + one number + one stake)

CURRENT BEHAVIOR:
BetPurchaseData describes exactly ONE selection: draw_id, market, number, stake,
idempotency_key. BetPurchaseService::purchase() consumes one such DTO and produces one Bet,
one BetItem and one Ticket.

BetPurchaseTransactionService opens its own DB transaction and, before doing so, calls
assertNotAlreadyInTransaction(), which throws BetPurchaseException::invariantViolated when
DB::transactionLevel() > 0. This is a correctness guard, not an oversight: the service relies
on owning the outermost transaction so that its rollback is a real rollback rather than a
savepoint release, and so that its wallet lock and its risk reservation are released at a
boundary it controls.

The consequence is that the obvious implementation of a multi-item purchase — wrap N calls to
purchase() in one outer DB::transaction() — is refused by the engine by design. The first
call would find transactionLevel() === 1 and throw.

REQUIRED BEHAVIOR:
POST /api/v1/bets/purchase accepts an items array of N selections and either sells all N or
sells none of them, with no partial state and no partial charge.

WHY IT MATTERS:
The three ways to force N items through the current engine are each unacceptable:

(a) Wrap N purchase() calls in an outer transaction.
    Blocked by assertNotAlreadyInTransaction(). Removing or weakening that guard would
    silently alter an authoritative Phase 4.3 financial invariant and would degrade every
    single-item purchase's rollback from a transaction to a savepoint. Explicitly forbidden.

(b) Call purchase() N times WITHOUT an outer transaction.
    This is the dangerous option, because it looks like it works. Each call commits
    independently, so a failure on item 3 of 5 leaves items 1 and 2 sold and paid for. The
    customer is charged for a purchase they did not make, and the operator must reverse it by
    hand. This is precisely the partial-purchase failure mode the architecture exists to
    prevent.

(c) Reimplement the multi-item flow in the controller.
    This would duplicate wallet locking, ledger posting and risk reservation in the HTTP
    layer — a direct violation of this phase's central rule, and the start of two divergent
    copies of the money path.

SAFE FIX (chosen, and implemented):
The API accepts `items` as an array (1..50) so the request contract in the specification is
honoured exactly and no client change is needed later. When count(items) === 1 the purchase
proceeds normally. When count(items) > 1 the request is refused with HTTP 422 and the stable
code `multi_item_purchase_unsupported`, and the refusal happens in the controller BEFORE the
service is called — so zero rows are written, zero ledger entries are posted and the wallet
balance is untouched. Refusing the whole request is itself the all-or-nothing behaviour: "all
or none" is satisfied by "none".

Tests Z and AA assert this directly, including the case where the FIRST item is perfectly
valid and only the second is not — the valid item must not be sold.

PROPER FIX (deferred, requires a decision about Phase 4.3):
Add a multi-selection entry point to the domain, not to the HTTP layer. Concretely:
  1. A BetPurchaseBasketData DTO holding one draw_id, one client key, and N selections.
  2. BetPurchaseService::purchaseBasket(BetPurchaseBasketData) that opens ONE transaction,
     takes ONE wallet lock for the summed stake, reserves each number's limit inside that
     transaction, writes one Bet with N BetItems and one Ticket, and posts one balanced
     ledger transaction for the total.
  3. Idempotency scoped to (user, draw, client_key, hash of the whole basket), so a replay
     of the basket returns the same purchase rather than re-selling part of it.
This is a Phase 4.3 change to verified financial code and is out of scope for Phase 4.4. It
must not be done incidentally inside an HTTP phase.
```

**No other conflict was found.** Every other requirement in the prompt was satisfiable
without touching Phase 1 → 4.3 code.

---

## 5. File change manifest

### 5.1 Created — application layer (11 files)

| # | Path | Lines | Purpose |
|---|---|---|---|
| 1 | `app/Http/Responses/ApiResponse.php` | 99 | The single place the success/error envelope is built |
| 2 | `app/Http/Support/BetPurchaseErrorMapper.php` | 694 | Throwable → `{code, status, message, details}`; never leaks a message |
| 3 | `app/Http/Support/BetPurchaseAuditRecorder.php` | 246 | Writes an `AuditLog` row per attempt; cannot break a request |
| 4 | `app/Http/Requests/Api/V1/PurchaseBetRequest.php` | 333 | Structural validation + forbidden-field rejection |
| 5 | `app/Http/Controllers/Api/V1/BetPurchaseController.php` | 159 | The purchase endpoint |
| 6 | `app/Http/Controllers/Api/V1/BetController.php` | 140 | `show` + `status`, user-scoped |
| 7 | `app/Http/Controllers/Api/V1/TicketController.php` | 87 | `show`, user-scoped |
| 8 | `app/Http/Resources/BetPurchaseResource.php` | 101 | The purchase response body |
| 9 | `app/Http/Resources/BetResource.php` | 66 | A bet, minus owner and internals |
| 10 | `app/Http/Resources/BetItemResource.php` | 107 | An item; reads `market` from `metadata` |
| 11 | `app/Http/Resources/TicketResource.php` | 53 | A ticket |

### 5.2 Modified (3 files)

| Path | Change | Why |
|---|---|---|
| `routes/api.php` | Added the `v1` group and the four routes | Nothing existing was removed; the health route is untouched |
| `app/Providers/AppServiceProvider.php` | Added `registerRateLimiters()` | Named limiters `api` and `bet`, both configured from `config/security.php`, both returning the project envelope on 429 |
| `bootstrap/app.php` | Added `withExceptions(...)` rendering | Routes API throwables through the mapper; logs 5xx server-side with the full throwable while sending the client nothing |

### 5.3 Created — tests (4 files)

| Path | Tests | Lines |
|---|---|---|
| `tests/Feature/Api/V1/ApiPurchaseTestCase.php` | (base class) | 257 |
| `tests/Feature/Api/V1/BetPurchaseApiTest.php` | 44 | 1,175 |
| `tests/Feature/Api/V1/BetAccessApiTest.php` | 10 | 302 |
| `tests/Unit/Api/ApplicationLayerSafetyTest.php` | 11 | 470 |

### 5.4 Files NOT touched, and files outside scope

- **No migration was created.** `php artisan migrate --pretend` → `Nothing to migrate.` The
  23 existing migrations are unchanged.
- **No Phase 1 → 4.3 file was modified.** No model, no enum, no value object, no DTO, no
  service, no exception class, no policy, no config file, no existing middleware, no existing
  test. A test in `ApplicationLayerSafetyTest` asserts the inverse direction of this too: no
  domain file references the HTTP layer.
- **No new policy or exception class was created.** `app/Policies/BetPolicy.php` and
  `app/Policies/TicketPolicy.php` already existed and are already registered in
  `AuthServiceProvider`; Phase 4.4 calls the existing gates rather than adding a second
  authorization vocabulary. Every one of the 18 required error codes maps onto an **existing**
  exception class.
- `phpunit.xml` was **not** modified. It is out of scope, and the test database is selected by
  environment variables at run time instead (§9).

---

## 6. Security implementation, rule by rule

### 6.1 Identity comes from one place only

```php
$userId = (int) $request->user()->getKey();
```

That is the only expression in Phase 4.4 that produces a user id. There is no
`$request->input('user_id')` anywhere, and the audit in §7 confirms it with a grep. A
`user_id` in the body is not ignored — it is a **refusal**, because ignoring it would let a
client believe an attempt had been accepted (test D).

### 6.2 Forbidden fields are rejected, not filtered

`PurchaseBetRequest` rejects, at the top level *and* inside every item, in both `snake_case`
and `camelCase` spellings: the whole of `BetPurchaseData::FORBIDDEN_CLIENT_KEYS` plus
`SERVER_OWNED_FIELDS` — `user_id`, `uuid`, `bet_uuid`, `ticket_uuid`, `bet_id`,
`bet_item_id`, `idempotency_key`, `placed_at`, `issued_at`, `confirmed_at`, `total_amount`,
`total_bets`, `total_numbers`, `currency`.

Rejecting rather than filtering is the deliberate choice. A filtered request succeeds while
quietly discarding what the client asked for; a rejected one tells the truth. Test AO walks 12
individual hostile fields — including `wallet_id`, `payout_multiplier`, `potential_payout`,
`ledger_account_id`, `status`, `ticket_id`, `bypass_risk`, `skip_risk`, `force`,
`admin_override`, `idempotency_key`, `uuid` — and requires a 422 for each. Test AP does the
same inside an item.

### 6.3 Leading zeros and decimals

`items.*.number` is validated as `required|string|regex:/^[0-9]{1,3}$/`. **`string` is the
load-bearing rule.** With `numeric` or with an integer cast, `'007'` becomes `7` and a
customer's bet silently changes. A JSON integer `7` in the request is refused with a 422 and
the client is required to send `"007"` (test AK).

The string survives the whole round trip: request → DTO → `bet_items.number` (varchar) →
resource → JSON. Test I asserts the raw response body literally contains `"number":"007"`,
which no amount of numeric coercion could satisfy. Test AC4 additionally asserts that all
three Tod arrangements of `007` are still 3-character strings.

Stakes are validated as `required|string|regex:/^[0-9]{1,12}(\.[0-9]{1,2})?$/` and are never
converted. A JSON float stake is refused (test AJ). Test AH sends `10.55` and asserts `10.55`
in the response, `10.55` in `bets.stake_amount`, `9495.00` as the payout, and `989.45` as the
resulting balance — all as exact strings.

### 6.4 Error mapping — and what a client never sees

`BetPurchaseErrorMapper` dispatches on exception type, most specific first, and builds each
response from **fixed strings plus a whitelist of context keys**. It never calls
`getMessage()`, `getTrace()`, `getTraceAsString()`, `getFile()` or `getLine()` — asserted both
by a unit test and by the grep in §7. Context is copied with an explicit `only()` list, and
`wallet_id` is excluded from every whitelist.

All 18 required codes, plus 3 additions:

| Code | HTTP | Source exception |
|---|---|---|
| `unauthenticated` | 401 | `AuthenticationException`, or a 401 abort |
| `validation_failed` | 422 | `ValidationException`, or another 4xx abort |
| `draw_not_found` | 404 | `BetValidationCode::DrawNotFound` |
| `draw_not_open` | 422 | `BetValidationCode::DrawNotOpen` |
| `draw_closed` | 422 | `BetValidationCode::DrawClosed` |
| `invalid_market` | 422 | `UnsupportedBetMarketException` |
| `invalid_digits` | 422 | `InvalidLotteryNumberException` (digit-length reason) |
| `invalid_number` | 422 | `InvalidLotteryNumberException` |
| `invalid_stake` | 422 | `InvalidBetAmountException` |
| `insufficient_balance` | 422 | `InsufficientBalanceException` |
| `number_limit_exceeded` | 422 | `NumberLimitExceededException` |
| `risk_rejected` | 422 | `RiskException` |
| `duplicate_idempotency_key` | 409 | `BetPurchaseIdempotencyException` |
| `idempotency_payload_mismatch` | 409 | `BetPurchaseIdempotencyException` / `IdempotencyConflictException` |
| `transaction_failed` | 500 | `FinancialException`, and the unmapped fallback |
| `market_result_unavailable` | 503 | `MarketResultUnavailableException` |
| `purchase_not_allowed` | 403 | `BetPurchaseException` (not-allowed reasons) |
| `authorization_failed` | 403 | `AuthorizationException`, or a 403 abort |
| `resource_not_found` *(added)* | 404 | `ModelNotFoundException` / `NotFoundHttpException` |
| `rate_limited` *(added)* | 429 | `ThrottleRequestsException`, or the limiter's own response |
| `multi_item_purchase_unsupported` *(added)* | 422 | The §4 conflict |

The `HttpExceptionInterface` branch honours a framework abort's **status** but never its
message, so an `abort(403, 'Your account is not active…')` reaches the client as a 403 with
this API's own wording.

Test AM is the strongest of these. It sets `app.debug` **to true** — the worst case — throws
an exception whose message contains an SQLSTATE code, a table name, a SQL statement and an
absolute file path, and then asserts the 500 response body contains none of `SQLSTATE`,
`Base table`, `select * from`, `/var/www`, `WalletService`, `RuntimeException`, `trace`,
`Stack trace`, `line`, or `wallets`, while still carrying the documented envelope. A client's
error handling keeps working during an outage, and an attacker learns nothing.

### 6.5 IDOR protection on the read endpoints

Route model binding is deliberately **not** used, because binding resolves the model *before*
ownership is known and a 404 from a binding failure looks different from a 403 from a policy —
which is an oracle. Instead each read controller:

1. builds a query already scoped with `->where('user_id', $request->user()->getKey())`;
2. treats a purely numeric parameter as a primary key and anything else as a UUID
   (`ctype_digit`, never `intval`);
3. calls `Gate::authorize('view', $model)` against the **existing** `BetPolicy` /
   `TicketPolicy` as a second, independent check;
4. returns **404 `resource_not_found`** for every failure — missing, not-yours, malformed —
   so the response cannot be used to discover which ids exist.

Route parameters are additionally constrained with `->where('bet', '[A-Za-z0-9-]{1,64}')`, so
a path-traversal or injection attempt never reaches the query builder. `TicketController` eager
loads `bets` with the `user_id` constraint applied *inside* the relation, so a shared ticket
could not nest another player's bets. Tests U, U2, U3, V, W, W2 and Z cover this.

### 6.6 Idempotency

`client_key` is required (16–128 chars, `[A-Za-z0-9._:-]`). It is **never** used as the
database key. The controller passes it to Phase 4.3 as `idempotency_key`, and Phase 4.3
derives the authoritative scoped key from user + draw + client key + payload hash, enforced by
a unique database index. There is no cache-only idempotency anywhere in Phase 4.4, and the
derived key is not returned to the client.

- **P** — identical replay: second call is `200` with `replayed: true`, same bet id, same
  ticket id; exactly 1 bet / 1 item / 1 ticket exist; the wallet was debited once (`990.00`).
- **Q** — same key, changed number: `409 idempotency_payload_mismatch`; still 1 bet, still
  number `123`, balance still `990.00`.
- **R** — two users, same key: both get their own `201`; different bet ids; 1 bet each.
- **S** — same user, same key, two draws: both `201`; different bet ids; 2 bets.

### 6.7 Rate limiting

Two named limiters, both read from `config/security.php` (nothing hard-coded): `api` at 60/min
keyed by user-or-IP, `bet` at 10/min keyed by user. Both return this API's envelope with
`rate_limited` and a `Retry-After` header. Test AL drives the configured limit and asserts the
next request is a `429` with the right code and header; test AL2 asserts the limit is scoped
per user, so one player cannot lock out another.

An `HttpResponseException` is now passed through the exception handler untouched, because it
*carries* a response an earlier layer built deliberately — remapping it would have turned the
limiter's correct 429 into a generic 500. That was a real bug, caught by AL during this phase
and fixed.

### 6.8 Audit trail

`BetPurchaseAuditRecorder` writes an `AuditLog` row for every attempt, successful or not, with
`AuditAction::PlaceBet` and a risk level (Low for a sale, Medium for a refusal, High for a
forbidden-field attempt). It redacts `config('security.audit.sensitive_fields')` and
**swallows its own exceptions**: an audit failure must never turn a completed purchase into an
error response, because the money has already moved.

---

## 7. Static security audit

Comments and docblocks are stripped with `token_get_all` before matching, so a rule *described*
in prose is not counted as code. Scanned files: the 11 created application-layer files plus
`routes/api.php`; the write/lock checks are narrowed to the three controllers.

**Actual output:**

```
PHASE 4.4 STATIC SECURITY AUDIT
-------------------------------
unsafe float casts (float)/(double)                        0
floatval()                                                 0
intval()                                                   0
round()/floor()/ceil() on money                            0
number_format()                                            0
direct wallet balance mutation                             0
direct ledger writes                                       0
Bet::create()                                              0
BetItem::create()                                          0
Ticket::create()                                           0
any ::create()/::insert()/save()/update() write            0
DB:: facade use in controllers                             0
lockForUpdate in controllers                               0
raw SQL                                                    0
bypass parameter read                                      0
risk bypass                                                0
idempotency bypass                                         0
authentication bypass                                      0
admin/force override flag read                             0
payout rate literal 900                                    0
payout rate literal 45                                     0
payout rate literal 90                                     0
exception message forwarded to client                      0
stack trace exposure                                       0
user_id accepted from the request body                     0
-------------------------------
Every count above must read 0.
```

`tests/Unit/Api/ApplicationLayerSafetyTest.php` re-checks the same properties as 11 executable
tests (962 assertions) so they cannot regress silently.

**One honest limitation.** The hard-coded-rate scan checks rates of two or more digits (900,
45, 90). `run_top` pays **3** and `run_bottom` pays **4**, and a single-digit literal is
indistinguishable by text from any ordinary small integer — a digit count, a retry count, a
substring length. Asserting on them produced false failures on code unrelated to payouts,
which would make the guard untrustworthy. Those two rates are covered **behaviourally**
instead: tests AF, AG and AI read the multiplier from `config` *at assertion time* and compare
it with what the API returned, so a hard-coded 3 or 4 would be caught the moment it disagreed
with configuration.

---

## 8. Test results — actual execution

### 8.1 Phase 4.4 required matrix A → AM

Every letter maps to a test that ran. Names are as they appear in the runner output.

| ID | Requirement | Test | Result |
|---|---|---|---|
| A | Unauthenticated purchase rejected | `a_unauthenticated_purchase_request_is_rejected` | PASS |
| B | Authenticated purchase succeeds | `b_authenticated_purchase_request_succeeds` | PASS |
| C | Owner taken from auth context | `c_the_bet_owner_is_taken_from_the_authenticated_context` | PASS |
| D | Spoofed `user_id` rejected | `d_a_spoofed_user_id_in_the_payload_is_rejected_and_never_honoured` | PASS |
| E | Invalid draw | `e_a_purchase_for_a_draw_that_does_not_exist_is_refused` | PASS |
| F | Closed draw | `f_a_purchase_for_a_closed_draw_is_refused` | PASS |
| G | Invalid market | `g_an_unknown_market_is_refused` | PASS |
| H | Wrong digit length | `h_a_number_with_the_wrong_digit_length_is_refused` | PASS |
| I | `007` preserved | `i_a_leading_zero_number_is_preserved_end_to_end` | PASS |
| J | `099` preserved | `j_the_number_099_is_preserved_end_to_end` | PASS |
| K | Invalid stake | `k_a_malformed_stake_is_refused` | PASS |
| L | Zero stake | `l_a_zero_stake_is_refused` | PASS |
| M | Negative stake | `m_a_negative_stake_is_refused` | PASS |
| N | Insufficient balance mapped | `n_an_insufficient_balance_is_mapped_to_a_stable_error_code` | PASS |
| O | Number-limit error mapped | `o_a_number_limit_refusal_is_mapped_to_a_stable_error_code` | PASS |
| P | Idempotent replay | `p_an_identical_replayed_request_returns_the_same_purchase_once` | PASS |
| Q | Same key, changed payload rejected | `q_the_same_client_key_with_a_changed_payload_is_rejected` | PASS |
| R | Different users don't collide | `r_the_same_client_key_from_two_different_users_does_not_collide` | PASS |
| S | Different draws don't collide | `s_the_same_client_key_on_two_different_draws_does_not_collide` | PASS |
| T | Response contains only safe data | `t_the_success_response_exposes_only_safe_data` | PASS |
| U | Another user's bet inaccessible | `u_another_users_bet_is_not_accessible` (+ `u2`, `u3`) | PASS |
| V | Another user's ticket inaccessible | `v_another_users_ticket_is_not_accessible` | PASS |
| W | Hostile route param cannot leak | `w_a_hostile_route_parameter_cannot_leak_anything` (+ `w2`) | PASS |
| X | No wallet mutation outside the service | `x_the_wallet_is_only_ever_mutated_by_the_purchase_service` | PASS |
| Y | No direct ledger mutation | `y_ledger_entries_are_written_only_by_the_domain_and_stay_balanced` | PASS |
| Z | Multi-item remains atomic | `z_a_multi_item_request_is_atomic_and_charges_nothing_when_it_cannot_be_honoured` | PASS |
| AA | One failed item rolls back everything | `aa_one_unsellable_item_prevents_the_whole_multi_item_purchase` | PASS |
| AB | 3D Direct | `ab_three_digit_direct_sells_through_the_api` | PASS |
| AC | 3D Tod (one charge) | `ac_three_digit_tod_sells_through_the_api_as_one_charge` (+ `ac2`, `ac3`, `ac4`) | PASS |
| AD | 2D Top | `ad_two_digit_top_sells_through_the_api` | PASS |
| AE | 2D Bottom | `ae_two_digit_bottom_sells_through_the_api` | PASS |
| AF | Run Top | `af_run_top_sells_through_the_api` | PASS |
| AG | Run Bottom | `ag_run_bottom_sells_through_the_api` | PASS |
| AH | Exact decimal preserved | `ah_an_exact_decimal_stake_is_preserved_through_the_api` | PASS |
| AI | Multiplier from authoritative config | `ai_the_payout_multiplier_comes_from_the_authoritative_configuration` | PASS |
| AJ | No float conversion | `aj_no_amount_in_the_response_is_a_json_number` | PASS |
| AK | No integer conversion of the number | `ak_an_integer_lottery_number_in_the_request_is_refused` | PASS |
| AL | Rate limiting works | `al_the_purchase_endpoint_is_rate_limited` (+ `al2` per-user scope) | PASS |
| AM | Production-style 500 hides the trace | `am_an_unexpected_failure_never_exposes_a_stack_trace_or_internals` | PASS |

Added beyond the matrix, because the code required them: `an` (missing `client_key`), `ao` (12
forbidden top-level fields), `ap` (forbidden field inside an item), `aq` (suspended account),
`x`/`y`/`z`/`aa` in the access suite (read auth, read response shape, ticket nesting, reads
perform no writes), and the 11 static tests.

### 8.2 Market coverage detail

| Market | Rate (config) | Test evidence |
|---|---|---|
| `3d_direct` | 900 | `10.00` → `9000.00`; `007` and `099` preserved |
| `3d_tod` | 45 | `123` → 6 arrangements, `112` → 3, `111` → 1, `007` → 3; **1 item, 1 charge, 1 ticket**; `10.00` → `450.00`; wallet debited once (`990.00`) |
| `2d_top` | 90 | `45` → `900.00`, position `top` |
| `2d_bottom` | 90 | `45` → `900.00`, position `bottom` |
| `run_top` | 3 | `10.00` → `30.00`, rate compared against config |
| `run_bottom` | 4 | `10.00` → `40.00`, rate compared against config **and** asserted different from `run_top` |

### 8.3 Runner output — Phase 4.4 suites

```
tests/Unit/Api/ApplicationLayerSafetyTest.php
  Tests:    11 deprecated (962 assertions)
  Duration: 0.20s

tests/Feature/Api/V1/BetPurchaseApiTest.php
  Tests:    44 deprecated (284 assertions)
  Duration: 25.94s

tests/Feature/Api/V1/BetAccessApiTest.php
  Tests:    10 deprecated (95 assertions)
  Duration: 12.65s
```

### 8.4 Runner output — pre-existing suite (regression check)

```
  Tests:    53 deprecated, 3 passed (231 assertions)
  Duration: 31.42s
```

56 tests, identical to the Phase 4.3 baseline. **No Phase 1 → 4.3 regression.**

### 8.5 Runner output — full suite

```
  Tests:    118 deprecated, 3 passed (1572 assertions)
  Duration: 55.46s
```

**121 tests · 1,572 assertions · PASS 121 · FAIL 0 · BLOCKED 0.**

### 8.6 About the `DEPR` markers — read this before assuming a failure

Every database-touching test is annotated `DEPR`, not `FAIL`. The reason is a single
deprecation notice:

```
Constant PDO::MYSQL_ATTR_SSL_CA is deprecated
```

Stock `config/database.php` (Laravel skeleton, present since Phase 1) references
`PDO::MYSQL_ATTR_SSL_CA`, which PHP 8.5 deprecates. **This is pre-existing, unrelated to Phase
4.4, and present in the Phase 4.3 baseline in exactly the same form.** Fixing it means editing
`config/database.php`, which is outside this phase's file scope. PHPUnit reports zero failures
and zero errors.

### 8.7 Bugs found and fixed by these tests during this phase

These are recorded because they are the reason the tests exist:

1. **The rate limiter's 429 became a 500.** `ThrottleRequests` wraps a limiter's custom
   response in `HttpResponseException`; the new exception handler was remapping it and
   discarding the correct 429. Caught by AL. Fixed by passing `HttpResponseException` through.
2. **A suspended account got a 500 instead of a 403.** `EnsureUserIsActive` calls
   `abort(403)`, a plain `HttpException` the mapper had no branch for, so it fell to the
   unmapped-fallback 500. Caught by AQ. Fixed by adding the `HttpExceptionInterface` branch
   that honours the status and discards the message.

Both were genuine boundary defects that inspection had missed.

---

## 9. Validation environment and exact commands

**Actual versions, as measured — these differ from the versions named in the prompt and are
reported as found rather than as assumed:**

| Component | Prompt said | Actually installed |
|---|---|---|
| PHP | 8.3.14 | **8.5.4** |
| Database | MariaDB 10.11 | **MariaDB 11.8.6** |
| Laravel | 11.x | 11.56.1 |
| Composer | — | 2.10.3 |
| PHPUnit | — | 11.5.56 |

PHP 8.5 is why the `DEPR` notices appear (§8.6). Extensions loaded: `bcmath`, `pdo_mysql`,
`pdo_sqlite`, `pcntl`, `zip`, `gmp`.

**Database safety.** A disposable database, `thai_lottery_test`, was used throughout. No
production database exists in this environment and none was contacted.
`ApiPurchaseTestCase::assertTestDatabaseOnly()` runs in `setUp()` and **aborts the test run**
unless the connected database name is `thai_lottery_test` or `:memory:`, so these tests cannot
be pointed at production data by accident. `DatabaseTruncation` is used rather than
`RefreshDatabase` so migrations are not re-run per test.

`phpunit.xml` declares SQLite `:memory:` via non-forced `<env>` entries, so exported
environment variables take precedence. That is why every command below exports them, and why
`phpunit.xml` did not need to be edited.

### Commands actually run

```bash
# 1. Syntax check every changed PHP file (14 app + 4 test files)
cd /home/user/workspace/proj/Thai-lottery
for f in \
  app/Http/Responses/ApiResponse.php \
  app/Http/Support/BetPurchaseErrorMapper.php \
  app/Http/Support/BetPurchaseAuditRecorder.php \
  app/Http/Requests/Api/V1/PurchaseBetRequest.php \
  app/Http/Controllers/Api/V1/BetPurchaseController.php \
  app/Http/Controllers/Api/V1/BetController.php \
  app/Http/Controllers/Api/V1/TicketController.php \
  app/Http/Resources/BetPurchaseResource.php \
  app/Http/Resources/BetResource.php \
  app/Http/Resources/BetItemResource.php \
  app/Http/Resources/TicketResource.php \
  routes/api.php \
  app/Providers/AppServiceProvider.php \
  bootstrap/app.php \
  tests/Feature/Api/V1/ApiPurchaseTestCase.php \
  tests/Feature/Api/V1/BetPurchaseApiTest.php \
  tests/Feature/Api/V1/BetAccessApiTest.php \
  tests/Unit/Api/ApplicationLayerSafetyTest.php ; do php -l "$f"; done
# → "No syntax errors detected" for all 18

# 2. Composer
composer validate --no-check-publish     # → ./composer.json is valid
composer dump-autoload                   # → generated successfully

# 3. Framework
php artisan --version                    # → Laravel Framework 11.56.1

# 4. Database
sudo service mariadb start
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
       DB_DATABASE=thai_lottery_test DB_USERNAME=lottery DB_PASSWORD=lottery

# 5. Confirm no schema change is needed
php artisan migrate --pretend            # → Nothing to migrate.

# 6. Confirm the routes
php artisan route:list --path=api        # → Showing [4] routes

# 7. Phase 4.4 tests
php artisan test tests/Unit/Api/ApplicationLayerSafetyTest.php
php artisan test tests/Feature/Api/V1/BetPurchaseApiTest.php
php artisan test tests/Feature/Api/V1/BetAccessApiTest.php

# 8. Pre-existing suite only (regression check)
php artisan test tests/Feature/HealthCheckTest.php tests/Feature/Betting \
                 tests/Integration tests/Security tests/Unit/EnumIntegrityTest.php

# 9. Full suite
php artisan test
# → Tests: 118 deprecated, 3 passed (1572 assertions)

# 10. Static audit
./audit_phase44.sh

# 11. Package and verify
cd /home/user/workspace/proj
zip -rq Thai-lottery-PHASE-4.4-SECURE-API.zip Thai-lottery -x '…exclusions…'
unzip -t Thai-lottery-PHASE-4.4-SECURE-API.zip
```

---

## 10. Schema limitations discovered

No blocker, but two facts about the existing schema shaped the code and are worth recording:

1. **`bet_items` has no `market` column.** Its columns are `bet_id`, `number` (varchar 16),
   `position` (varchar 32), `amount` (decimal 20,2), `payout_multiplier`, `potential_payout`,
   `is_winner`, `actual_payout`, `metadata` (json). The market key is stored inside
   `metadata['market']` by Phase 4.3, so `BetItemResource` reads it from there. Adding a real
   `market` column would be cleaner for reporting and indexing, but it is a **migration** and
   this phase must not create one. Recorded for a future phase.

2. **`ledger_entries` stores one side per row** — a `type` (debit/credit) plus a positive
   `amount` — rather than separate `debit_amount` / `credit_amount` columns. Test Y sums the
   two sides with `bcadd` and asserts they are equal, which is the correct balance check for
   this shape.

Neither required a schema change. `php artisan migrate --pretend` reports `Nothing to
migrate.`

---

## 11. Known limitations

1. **Multi-item purchase is refused, not supported.** Fully explained in §4, with the safe fix
   implemented and the proper fix specified. This is the one requirement in the prompt that
   the existing engine does not permit.
2. **Single-digit payout rates are not covered by the static scan** — covered behaviourally
   instead (§7).
3. **`2d_bottom` depends on `draw_results.metadata['bottom_two']`.** When it is absent the
   domain raises `MarketResultUnavailableException`, which this API maps to
   `market_result_unavailable` (503). No bottom result is ever invented. The API layer adds
   nothing here; the guarantee is Phase 4.2's.
4. **The `PDO::MYSQL_ATTR_SSL_CA` deprecation is not fixed** — pre-existing, and
   `config/database.php` is outside this phase's file scope (§8.6).
5. **No pagination or list endpoints.** Only the four specified routes exist. A
   `GET /api/v1/bets` collection is a future phase.
6. **Rate limits are per-process cache-backed.** In production with multiple app servers the
   limiter needs a shared store (Redis) to be a true global limit. That is a deployment
   configuration matter, not a code change.
7. **`Retry-After` is the only throttle hint returned.** `X-RateLimit-Remaining` is not
   exposed, on the view that telling a client exactly how close it is to a betting limit is
   more useful to an abuser than to a legitimate client.
8. **No draw settlement, result announcement, payout, payment gateway, agent, Filament,
   WebSocket or frontend code was written.** Out of scope by instruction.

---

## 12. Deep audit checklist

| # | Item | Status | Evidence |
|---|---|---|---|
| 1 | Authentication context correct | PASS | `auth:sanctum`; `$request->user()->getKey()` is the only identity source. Tests A, B, C, X (access) |
| 2 | Authorization correct | PASS | Existing `BetPolicy`/`TicketPolicy` via `Gate::authorize`, on top of a user-scoped query. Tests U, U2, U3, V |
| 3 | Request validation correct | PASS | Numbers and stakes are strings with strict regexes; forbidden fields rejected. Tests D, G, H, K, L, M, AJ, AK, AN, AO, AP |
| 4 | DTO compatibility | PASS | `BetPurchaseData::fromRequestArray()` used with its own key names; no new contract invented |
| 5 | BetPurchaseService integration | PASS | Called once per request; the controller performs no financial work. Static tests + audit §7 |
| 6 | Idempotency correct | PASS | `client_key` never used raw; Phase 4.3 derives the DB-enforced scoped key. Tests P, Q, R, S |
| 7 | Wallet locking stays in Phase 4.3 | PASS | 0 wallet mutations and 0 `lockForUpdate` in controllers. Test X + audit |
| 8 | Risk locking stays in Phase 3.1 | PASS | No reservation code in the HTTP layer; refusals arrive as mapped codes. Test O + audit |
| 9 | Ledger stays in Phase 2.1 | PASS | 0 ledger writes in controllers; debits equal credits after an API purchase. Test Y + audit |
| 10 | Atomic rollback verified | PASS | Multi-item refused pre-mutation with zero rows and unchanged balance. Tests Z, AA |
| 11 | Decimal correctness verified | PASS | `10.55` → `10.55` / `9495.00` / `989.45`, exact strings. Tests AH, AI, AJ |
| 12 | Leading-zero preservation verified | PASS | `007`, `099`, and Tod arrangements of `007`. Tests I, J, AC4, AK |
| 13 | All six markets tested | PASS | §8.2. Tests AB, AC, AC2, AC3, AC4, AD, AE, AF, AG |
| 14 | Error mapping complete | PASS | 21 codes, all from existing exception classes. §6.4; 11 static tests |
| 15 | API response consistency | PASS | One envelope from `ApiResponse`, including 429 and 500. Tests T, AL, AM |
| 16 | IDOR protection verified | PASS | 404 for missing *and* not-yours; no route model binding; constrained params. Tests U, U2, U3, V, W, W2, Z |
| 17 | Rate limiting verified | PASS | Configured limit enforced, per-user scope, `Retry-After`. Tests AL, AL2 |
| 18 | No financial logic duplicated | PASS | 25/25 audit checks at 0; 11 static tests |
| 19 | No security bypass possible | PASS | 0 bypass/force/override reads; forbidden fields rejected. Tests D, AO, AP, AQ + audit |
| 20 | No Phase 1 → 4.3 regression | PASS | Pre-existing suite: 56 tests, 231 assertions, unchanged. §8.4 |

---

## 13. API reference

### 13.1 `POST /api/v1/bets/purchase`

```json
{
  "draw_id": 12,
  "client_key": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "items": [
    { "market": "3d_direct", "number": "007", "stake": "10.00" }
  ]
}
```

`201 Created` (or `200 OK` on an idempotent replay):

```json
{
  "success": true,
  "message": "Bet purchased.",
  "data": {
    "status": "purchased",
    "replayed": false,
    "client_key": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
    "draw_id": 12,
    "bet": { "id": 41, "uuid": "…", "bet_number": "…", "status": "active", "type": "3d" },
    "ticket": { "id": 41, "uuid": "…", "ticket_number": "…", "status": "issued" },
    "currency": "THB",
    "total_stake": "10.00",
    "potential_payout": "9000.00",
    "placed_at": "2026-08-27T15:20:00+00:00",
    "items": [
      {
        "market": "3d_direct",
        "number": "007",
        "position": "top",
        "stake": "10.00",
        "payout_multiplier": 900,
        "potential_payout": "9000.00"
      }
    ]
  }
}
```

Not returned, deliberately: `wallet_id`, `ledger_account_id`, ledger entry ids, the financial
transaction id, the risk reservation, the derived idempotency key, and the owner's `user_id`.

### 13.2 Error shape

```json
{
  "success": false,
  "error": {
    "code": "insufficient_balance",
    "message": "There is not enough balance to place this bet.",
    "details": {}
  }
}
```

`details` is always present and always a whitelist — never an exception message, never a
query, never a path.

### 13.3 Read endpoints

- `GET /api/v1/bets/{bet}` — id or uuid; the authenticated user's own bet, with its items.
- `GET /api/v1/bets/{bet}/status` — a minimal status projection.
- `GET /api/v1/tickets/{ticket}` — id or uuid; the ticket with only the owner's bets nested.

All three return `404 resource_not_found` for anything not owned by the caller.

---

## 14. Statement of honesty

- Every number in this report came from a command that was executed in this environment. No
  test result is estimated and none is fabricated.
- Nothing is reported as BLOCKED: PHP, Composer and MariaDB were all available and the full
  suite ran to completion.
- The `DEPR` markers are a pre-existing PHP 8.5 deprecation in stock `config/database.php`,
  not failures, and are explained in §8.6 rather than hidden.
- The installed PHP and MariaDB versions differ from those named in the prompt and are
  reported as measured (§9).
- The one requirement the existing engine could not satisfy — multi-item atomic purchase — is
  reported as a CONFLICT in §4 with the reason, the risk of each alternative, the safe
  behaviour implemented, and the proper fix. Phase 4.3 financial code was **not** altered.
- Two real defects were found by these tests during this phase and are recorded in §8.7 rather
  than quietly fixed.

**PHASE 4.4 COMPLETE. Awaiting the next instruction. Phase 5 has not been started.**
