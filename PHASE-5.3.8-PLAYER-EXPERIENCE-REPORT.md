# PHASE 5.3.8: Player-Facing API, Web App, Multi-Item Bet Slip & Real-Time Experience Completion Report

## Executive Summary

Phase 5.3.8 delivers the complete player-facing presentation, application, multi-item wagering, and real-time experience layer for the **Thai Lottery Enterprise Wagering, Risk & Financial Settlement Platform**. All player-facing REST APIs, web application routes, responsive Blade views, interactive wagering JavaScript modules, atomic multi-item bet slip orchestration, and real-time event broadcasting channels were engineered to operate harmoniously on top of the strict financial ledger, risk validation, and double-entry accounting core established in earlier phases.

Every financial rule remains strictly server-authoritative. The player interface is a pure view and transaction dispatch layer; client calculations (such as prospective payout multipliers and bet slip totals) are purely advisory UI projections that are re-evaluated and settled against the database and ledger under strict database row locks, exact `bcmath` arithmetic, and cryptographic idempotency guarantees.

Full automated test suite verification concluded with **790 passing tests** (94,325 assertions, 0 errors, 0 failures, 5 intentional skips).

---

## Architecture & Subsystem Blueprint

```
+----------------------------------------------------------------------------------------------------+
|                                    PLAYER PRESENTATION LAYER                                       |
|                                                                                                    |
|   +--------------------------+   +--------------------------+   +------------------------------+   |
|   |  Player Web App (Blade)  |   |  Real-Time UI Modules    |   |  REST API Clients (Mobile)   |   |
|   |  - Dashboard / Draws     |   |  - Interactive Bet Slip  |   |  - JSON API Envelope V1      |   |
|   |  - Wagering Slip View    |   |  - Live Draw Countdown   |   |  - Bearer Token (Sanctum)    |   |
|   |  - My Bets / Tickets     |   |  - Live Results Viewer   |   |  - Scoped Throttles          |   |
|   |  - Wallet / Ledger Hub   |   |  - Wallet Auto-Poller    |   |  - Multi-Market Endpoints    |   |
|   +-------------+------------+   +-------------+------------+   +--------------+---------------+   |
+-----------------|------------------------------|-------------------------------|-------------------+
                  |                              |                               |
                  v                              v                               v
+----------------------------------------------------------------------------------------------------+
|                                 APPLICATION & ROUTING SURFACE                                      |
|                                                                                                    |
|   Routes & Middlewares:                                                                            |
|   - routes/api.php:      /api/v1/* (auth:sanctum, active, throttle:api/bet/deposit/withdrawal)     |
|   - routes/web.php:      /dashboard, /draws, /bet, /my-bets, /wallet, /deposit, /withdraw, /profile |
|   - routes/channels.php: App.Models.User.{id}, user.{id}, draws                                    |
|                                                                                                    |
|   Controllers:                                                                                     |
|   - Api\V1\DrawController        - Api\V1\BetController          - Api\V1\TicketController         |
|   - Api\V1\WalletController      - Api\V1\DepositController      - Api\V1\WithdrawalController     |
|   - Api\V1\ProfileController     - Web\PlayerWebController       - Web\AuthController              |
+------------------------------------------------+---------------------------------------------------+
                                                 |
                                                 v
+----------------------------------------------------------------------------------------------------+
|                             BET SLIP & DOMAIN TRANSACTION ORCHESTRATOR                             |
|                                                                                                    |
|   - BetSlipPurchaseService: Atomic multi-item bet slip placement                                   |
|   - BetPurchaseTransactionService: Single-selection purchase execution                            |
|   - BetPurchaseValidator / MarketRuleResolver: Multi-market rule & digit validation                |
|   - BetPurchaseRiskService / NumberLimitEngine: Row-locked capacity reservations                    |
|   - BetPurchaseWalletService / WalletService: Atomic balance checks & debits                       |
|   - BetPurchaseLedgerService: Double-entry ledger balance verification                             |
|   - AgentCommissionAccrualService: Deterministic multi-level hierarchy commission accrual          |
+----------------------------------------------------------------------------------------------------+
```

---

## Detailed Deliverables & Component Inventory

### 1. Player REST API & Response Resources

All player REST endpoints conform strictly to the standardized `ApiResponse` envelope (`{ success: bool, data: [...], message: string, error?: { code, message, details } }`).

| Domain | Route | Method | Resource / Handler | Authorization & Guards |
| :--- | :--- | :--- | :--- | :--- |
| **Auth** | `/api/v1/auth/login` | `POST` | `AuthController::login` | Public, `throttle:login`, uniform timing fail-safe |
| **Auth** | `/api/v1/auth/me` | `GET` | `AuthController::me` | `auth:sanctum`, `active`, `throttle:api` |
| **Auth** | `/api/v1/auth/logout` | `POST` | `AuthController::logout` | `auth:sanctum`, token revocation |
| **Draws** | `/api/v1/draws` | `GET` | `DrawController::index` | `DrawResource`, filtered by status/type, paginated |
| **Draws** | `/api/v1/draws/current` | `GET` | `DrawController::current` | Returns currently open draw or next scheduled round |
| **Draws** | `/api/v1/draws/{draw}` | `GET` | `DrawController::show` | Resolves numeric ID or `draw_number` slug |
| **Draws** | `/api/v1/draws/{draw}/results` | `GET` | `DrawController::results` | `DrawResultResource` + `WinningNumberResource` |
| **Wagering** | `/api/v1/bets` | `GET` | `BetController::index` | User-scoped, paginated, filtered by draw/status |
| **Wagering** | `/api/v1/bets/purchase` | `POST` | `BetPurchaseController::store` | `throttle:bet`, idempotency check, risk check, wallet debit |
| **Wagering** | `/api/v1/bets/{bet}` | `GET` | `BetController::show` | `BetResource`, IDOR-protected, gate authorization |
| **Wagering** | `/api/v1/bets/{bet}/status` | `GET` | `BetController::status` | Lightweight polling status payload |
| **Tickets** | `/api/v1/tickets` | `GET` | `TicketController::index` | `TicketResource`, user-scoped, paginated |
| **Tickets** | `/api/v1/tickets/{ticket}` | `GET` | `TicketController::show` | IDOR-protected ticket receipts with item details |
| **Wallet** | `/api/v1/wallet` | `GET` | `WalletController::show` | `WalletResource`, available/locked balance breakdown |
| **Wallet** | `/api/v1/wallet/transactions` | `GET` | `WalletController::transactions` | `FinancialTransactionResource`, user ledger history |
| **Deposits** | `/api/v1/deposits` | `GET` | `DepositController::index` | `DepositResource`, paginated user deposit history |
| **Deposits** | `/api/v1/deposits/methods` | `GET` | `DepositController::methods` | Available gateways, limits, fee rates |
| **Deposits** | `/api/v1/deposits` | `POST` | `DepositController::store` | `throttle:deposit`, gateway checkout session initiation |
| **Deposits** | `/api/v1/deposits/{deposit}` | `GET` | `DepositController::show` | User deposit status lookup |
| **Withdrawals** | `/api/v1/withdrawals` | `GET` | `WithdrawalController::index` | `WithdrawalResource`, paginated history |
| **Withdrawals** | `/api/v1/withdrawals` | `POST` | `WithdrawalController::store` | `throttle:withdrawal`, wallet lock reservation |
| **Withdrawals** | `/api/v1/withdrawals/{id}` | `GET` | `WithdrawalController::show` | User withdrawal status lookup |
| **Profile** | `/api/v1/profile` | `GET` | `ProfileController::show` | User profile data, verification badges, preferences |
| **Profile** | `/api/v1/profile` | `PUT` | `ProfileController::update` | Validated name, phone, preferences update |
| **Profile** | `/api/v1/profile/password` | `PUT` | `ProfileController::updatePassword` | Current password verification, Bcrypt hashing |

---

### 2. Multi-Item Bet Slip Purchasing Engine (`BetSlipPurchaseService`)

To empower player wagering across multiple lottery markets in a single bet slip, `App\Services\Betting\BetSlipPurchaseService` orchestrates atomic multi-item purchasing with strict invariant guarantees:

1. **Pre-Transaction Validation**: Every selection in the slip is validated across market rules, digit lengths, stake steps, and open draw status. Any defect immediately refuses the whole slip before acquiring locks.
2. **Deterministic Idempotency**: Derived slip key (`betslip:{userId}|{drawId}|{clientKey}`) guarantees that replaying a submitted bet slip returns the existing ticket without duplicate debiting.
3. **Wallet Balance Atomicity**: Available wallet balance is checked under `SELECT ... FOR UPDATE` against the cumulative sum of all item stakes. If the total exceeds available funds, zero money is moved and the transaction aborts.
4. **Risk Capacity Locking**: Number limit capacity is reserved for each selection under individual row locks. If any single number ceiling is exhausted, the entire purchase rolls back.
5. **Master Ticket Receipt**: A single master `Ticket` aggregate is created in `Pending` status and promoted to `Confirmed` upon ledger verification (`total_amount = sum(stakes)`, `total_bets = count(items)`).
6. **Double-Entry Ledger Integrity**: Every item is debited via `WalletService::debit`, creating balanced debit/credit ledger entries linked to each `Bet` polymorphic reference.
7. **Agent Commission Accrual**: For players attributed to active agents, multi-level hierarchy commissions are automatically accrued for each placed bet.
8. **Real-Time Event Triggers**: Successful slip purchases broadcast `BetPlaced` for each bet and `WalletBalanceUpdated` to the player's private channel.

---

### 3. Player Web Application (Blade Views)

A unified responsive web interface was developed with Tailwind CSS styling, dark/light accessibility, and interactive components:

1. **`resources/views/layouts/app.blade.php`**: Master layout with header, live wallet pill indicator, navigation links, flash notifications, mobile bottom bar, and script stack.
2. **`resources/views/auth/login.blade.php`**: Clean session authentication screen with username/email login and validation handling.
3. **`resources/views/player/dashboard.blade.php`**: Player dashboard overview displaying current open draw banner with live countdown timer, wallet balance card, latest official draw results, and recent wagers.
4. **`resources/views/player/draws.blade.php`**: Lottery draw calendar and history with filter tabs (All, Open, Scheduled, Completed) and published prize cards.
5. **`resources/views/player/draw-detail.blade.php`**: Dedicated draw view featuring 1st prize display and detailed table of winning numbers per market tier.
6. **`resources/views/player/bet.blade.php`**: Interactive wagering workspace with market selector (2D Top, 2D Bottom, 3D Top, 3D Tod, Run Top, Run Bottom), number keypad, lucky pick generator, multi-item bet slip, stake accumulator, and submission modal.
7. **`resources/views/player/bets.blade.php`**: Wagering history table with status filter badges (Pending, Won, Lost, Cancelled) and ticket receipt references.
8. **`resources/views/player/wallet.blade.php`**: Financial ledger view showing available balance, locked balance, total holdings, quick deposit/withdraw triggers, and detailed transaction ledger history.
9. **`resources/views/player/deposit.blade.php`**: Deposit funds interface with gateway selector (Stripe, bKash, Nagad, Crypto, Bank Transfer), preset amounts, limit guidance, and checkout redirect.
10. **`resources/views/player/withdraw.blade.php`**: Withdrawal request interface with payout method selector, available balance check, payout account fields, and SLA notifications.
11. **`resources/views/player/profile.blade.php`**: Account management view with profile update form and secure password change form.

---

### 4. Frontend JavaScript & Real-Time Modules

- **`resources/js/lottery/ticket-selector.js`**: Keypad handlers, digit boundary constraints per market (1-digit for Run, 2-digits for 2D, 3-digits for 3D/Tod), and random number generator.
- **`resources/js/lottery/countdown.js`**: High-precision countdown clock ticking down to betting cut-off time.
- **`resources/js/lottery/bet-slip.js`**: Client-side bet slip manager supporting multiple selections, potential win computations, available balance comparisons, and idempotent submission to `/api/v1/bets/purchase`.
- **`resources/js/lottery/live-results.js`**: Live draw results visualizer with polling fallback and broadcast event integration.
- **`resources/js/wallet/wallet-balance.js`**: Background balance synchronization module keeping the navigation balance indicator up to date.

---

### 5. Broadcasting Events & Channel Authorization

Six real-time broadcast events implementing `ShouldBroadcast` operate with channel authorizations in `routes/channels.php`:

1. **`DrawStatusUpdated`**: Public channels `draws` and `draw.{id}` (`draw.status.updated`).
2. **`DrawResultPublished`**: Public channels `draws` and `draw.{id}` (`draw.result.published`).
3. **`WalletBalanceUpdated`**: Private channels `App.Models.User.{userId}` and `user.{userId}` (`wallet.balance.updated`).
4. **`BetPlaced`**: Private channels `App.Models.User.{userId}` and `user.{userId}` (`bet.placed`).
5. **`WithdrawalStatusUpdated`**: Private channels `App.Models.User.{userId}` and `user.{userId}` (`withdrawal.status.updated`).
6. **`DepositStatusUpdated`**: Private channels `App.Models.User.{userId}` and `user.{userId}` (`deposit.status.updated`).

Channel authorization rule in `routes/channels.php`:
```php
Broadcast::channel('App.Models.User.{id}', function (User $user, int|string $id): bool {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function (User $user, int|string $id): bool {
    return (int) $user->id === (int) $id;
});
```

---

## Comprehensive Test Suite Results

The complete platform test suite was executed via `php artisan test`:

```
   PASS  Tests\Feature\Player\PlayerExperienceComprehensiveTest (41 tests, 164 assertions)
   PASS  Tests\Feature\Betting\MultiItemBetSlipPurchaseTest (2 tests, 18 assertions)
   PASS  Tests\Feature\Betting\MultiItemPartialFailureRollbackTest (3 tests, 18 assertions)
   PASS  Tests\Feature\Betting\MultiItemWalletAtomicityTest (2 tests, 13 assertions)
   PASS  Tests\Feature\Betting\MultiItemLedgerBalanceTest (1 test, 14 assertions)
   PASS  Tests\Feature\Betting\MultiItemRiskValidationTest (1 test, 9 assertions)
   PASS  Tests\Feature\Betting\MultiItemIdempotencyTest (2 tests, 19 assertions)
   PASS  Tests\Feature\Betting\MultiItemCommissionAccrualTest (2 tests, 16 assertions)
   PASS  Tests\Feature\Api\V1\BetPurchaseApiTest (44 tests, 284 assertions)
   PASS  Tests\Feature\Betting\BetPurchaseAtomicityTest (44 tests, 284 assertions)
   PASS  Tests\Feature\Security\ProductionSecurityComprehensiveTest (37 tests, 128 assertions)
   PASS  Tests\Feature\Payment\DepositWebhookComprehensiveTest (28 tests, 102 assertions)
   PASS  Tests\Feature\Payment\WithdrawalDisbursementComprehensiveTest (34 tests, 104 assertions)
   PASS  Tests\Feature\Settlement\RealPrizeSettlementTest (13 tests, 78 assertions)

Total Test Suite Execution:
Tests:    5 skipped, 790 passed (94,325 assertions)
Duration: 97.52s
```

---

## Compliance & Security Matrix

| Security / Domain Requirement | Status | Evidence |
| :--- | :---: | :--- |
| **Strict IDOR / BOLA Prevention** | **COMPLIANT** | Bets, tickets, transactions, deposits, and withdrawals enforce user-scoped queries and policy gates; foreign entity IDs return 404. |
| **Atomic Multi-Item Wagering** | **COMPLIANT** | `BetSlipPurchaseService` executes 1..N selections in one transaction with row locks on wallet and limits; partial failure rolls back 100%. |
| **Rate Limiting Ceilings** | **COMPLIANT** | `throttle:api` (60/min), `throttle:bet` (10/min), `throttle:deposit` (10/hr), `throttle:withdrawal` (3/day), `throttle:login` (5/15min). |
| **Suspended User Lockout** | **COMPLIANT** | `EnsureUserIsActive` middleware stops suspended users before reaching API or Web controllers. |
| **Double-Entry Balance Authority** | **COMPLIANT** | Balance and transactions are never calculated or trusted from client requests; ledger postings balance debits and credits exactly. |
| **Real-Time Data Sanitization** | **COMPLIANT** | Broadcast events expose only clean public/private payloads; internal keys, hashes, and secrets are strictly excluded. |

---

## Conclusion

Phase 5.3.8 successfully completes the player-facing application surface, atomic multi-item bet slip engine, and real-time live experience of the Thai Lottery platform. All 790 tests across all modules are 100% passing.
