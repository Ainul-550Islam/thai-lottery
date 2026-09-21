<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BetController;
use App\Http\Controllers\Api\V1\BetPurchaseController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\DrawController;
use App\Http\Controllers\Api\V1\GloController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ResponsibleGamingController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Phase 4.4 establishes the project's first HTTP surface. Phase 1 left this
| file as a placeholder, so there was no existing route convention to
| preserve and this file defines it: everything lives under an explicit
| /api/v1 prefix with named routes, so a future v2 can be added beside it
| without renaming or breaking v1.
|
| WHY EVERY ROUTE IS AUTHENTICATED
| There is no public endpoint here. Not a price list, not a draw list, not a
| market list. Every route in this file sits behind auth:sanctum, so an
| unauthenticated request cannot reach a controller at all - it is stopped by
| middleware before any application code runs.
|
| THE MIDDLEWARE STACK, AND WHY EACH LAYER IS PRESENT
| - auth:sanctum: establishes WHO the caller is. Sanctum is already installed,
|   the personal_access_tokens migration already exists, and App\Models\User
|   already uses HasApiTokens - so this is the project's existing mechanism,
|   not a new one.
| - active: the existing EnsureUserIsActive middleware. Authentication proves
|   identity; it does not prove the account is permitted to trade. A suspended
|   player holding a still-valid token is stopped here.
| - throttle:api: a per-caller ceiling on the whole surface.
| - throttle:bet: an additional, much tighter ceiling on the purchase route
|   only. Both limiters are configured from config/security.php, which already
|   declared these values and noted that "Named limiters are registered from
|   these values in a later phase" - Phase 4.4 is that phase, and they are
|   registered in AppServiceProvider.
|
| WHAT IS DELIBERATELY NOT HERE
| No payment gateway route, no webhook route, no draw-result or settlement
| route, no payout route, no agent route, no admin route. Those belong to
| later phases and adding an empty stub for them now would imply a contract
| this phase cannot honour.
|
| The `wallet.active` middleware is also deliberately not applied to the
| purchase route. Wallet state is checked inside the Phase 4.3 purchase, under
| the wallet's own row lock, and that is the check that actually decides the
| outcome. A second check out here would be a weaker duplicate of a financial
| rule this phase must not duplicate.
|
*/

/*
|--------------------------------------------------------------------------
| Authentication (added after Phase 5.1)
|--------------------------------------------------------------------------
|
| Phase 4.4 stated that every route on this surface is authenticated, and it was
| right to - but it left no route that could ISSUE a credential, so no client
| could reach any endpoint at all. The token surface below is the minimum that
| makes the existing surface usable, built on the Sanctum installation the
| project already had.
|
| /auth/login is the ONE public route in this file. It carries `throttle:login`,
| a limiter registered in AppServiceProvider from the login ceilings that
| config/security.php already declared and keyed, per that config, on the
| submitted identifier AND the client IP - so neither an account nor an address
| can be attacked freely. It does NOT carry `active`: that middleware requires an
| authenticated user, and account status is checked inside the controller.
|
*/
Route::prefix('v1/auth')
    ->name('api.v1.auth.')
    ->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login');

        Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
            // `active` is applied to /me and /logout but NOT relaxed anywhere: a suspended
            // account may still revoke its own token, which is why logout is grouped here
            // with the same middleware the rest of the surface uses.
            Route::get('/me', [AuthController::class, 'me'])->middleware('active')->name('me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {

        // The purchase route. The only mutating endpoint in this phase, and the
        // only one carrying the tighter `throttle:bet` limiter.
        Route::post('/bets/purchase', [BetPurchaseController::class, 'store'])
            ->middleware('throttle:bet')
            ->name('bets.purchase');

        // Read endpoints. Both accept either the numeric id or the uuid, and both
        // resolve it inside a query already scoped to the authenticated user.
        //
        // The {bet} and {ticket} parameters are constrained to a conservative
        // character class at the route level. This is not the authorization check -
        // that is the user-scoped query plus the existing policy inside the
        // controller - it simply means a hostile identifier never reaches the query
        // builder in the first place.
        Route::get('/bets/{bet}', [BetController::class, 'show'])
            ->where('bet', '[A-Za-z0-9-]{1,64}')
            ->name('bets.show');

        Route::get('/bets/{bet}/status', [BetController::class, 'status'])
            ->where('bet', '[A-Za-z0-9-]{1,64}')
            ->name('bets.status');

        // Player reads: paginated own bets and own tickets. Both scoped to the
        // authenticated user inside the controller.
        Route::get('/bets', [BetController::class, 'index'])->name('bets.index');
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');

        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
            ->where('ticket', '[A-Za-z0-9-]{1,64}')
            ->name('tickets.show');

        // Lottery draws and published official results.
        Route::get('/draws', [DrawController::class, 'index'])->name('draws.index');
        Route::get('/draws/current', [DrawController::class, 'current'])->name('draws.current');
        Route::get('/draws/{draw}', [DrawController::class, 'show'])
            ->where('draw', '[A-Za-z0-9-]{1,64}')
            ->name('draws.show');
        Route::get('/draws/{draw}/results', [DrawController::class, 'results'])
            ->where('draw', '[A-Za-z0-9-]{1,64}')
            ->name('draws.results');

        // Wallet balance and transaction history.
        Route::get('/wallet', [WalletController::class, 'show'])->name('wallet.show');
        Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');

        // Profile and password.
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        // KYC status and document upload.
        Route::get('/kyc/status', [KycController::class, 'status'])->name('kyc.status');
        Route::post('/kyc/upload', [KycController::class, 'upload'])->name('kyc.upload');

        // Responsible gaming limits and self-exclusion.
        Route::get('/responsible-gaming', [ResponsibleGamingController::class, 'show'])->name('responsible-gaming.show');
        Route::put('/responsible-gaming/limits', [ResponsibleGamingController::class, 'updateLimits'])->name('responsible-gaming.limits');
        Route::post('/responsible-gaming/self-exclude', [ResponsibleGamingController::class, 'selfExclude'])->name('responsible-gaming.self-exclude');

        // GLO official reference surface. Read-only: the prize ladder, one draw's
        // recorded official numbers, and the 6-digit ticket checker. A winning
        // check is reported, never credited — the physical GLO ticket is paid in
        // person, not through the wallet.
        Route::get('/glo/prizes', [GloController::class, 'prizes'])->name('glo.prizes');
        Route::get('/glo/draws/{draw}', [GloController::class, 'draw'])
            ->where('draw', '[A-Za-z0-9-]{1,64}')
            ->name('glo.draw');
        Route::post('/glo/check-ticket', [GloController::class, 'checkTicket'])->name('glo.check-ticket');
    });

/*
|--------------------------------------------------------------------------
| Deposits (added for the payment-gateway surface)
|--------------------------------------------------------------------------
|
| The player-facing deposit endpoints behind the same authenticated stack as
| the rest of the surface. The contract: POST /deposits initiates a deposit
| and returns a pending record plus the gateway checkout payload; GET
| /deposits/{id} returns one of the caller's own deposits (by id, uuid or
| reference number) scoped to the authenticated user; GET /deposits lists the
| caller's deposit history and GET /deposits/methods enumerates the available
| methods.
|
*/
Route::prefix('v1/deposits')
    ->name('api.v1.deposits.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/methods', [DepositController::class, 'methods'])->name('methods');
        Route::get('/', [DepositController::class, 'index'])->name('index');
        Route::post('/', [DepositController::class, 'store'])->middleware('throttle:deposit')->name('store');
        Route::get('/{deposit}', [DepositController::class, 'show'])->name('show');
    });

/*
|--------------------------------------------------------------------------
| Payment webhooks (added for the payment-gateway surface)
|--------------------------------------------------------------------------
|
| The one public inbound surface: a gateway POSTs a signed notification to
| /api/v1/payments/webhook/{gateway}. Signature verification is the
| controller's first act, so an unsigned request is refused before any
| payload is parsed or any database state is read. The gateway name in the
| URL chooses the driver, which is what keeps every provider's verification
| scheme in one place.
|
*/
Route::prefix('v1/payments/webhook')
    ->name('api.v1.payments.webhook.')
    ->middleware(['throttle:webhook'])
    ->group(function (): void {
        Route::post('/{gateway}', [PaymentWebhookController::class, 'handle'])->name('handle');
    });

/*
|--------------------------------------------------------------------------
| Withdrawals (added for the withdrawal-disbursement surface)
|--------------------------------------------------------------------------
|
| The player-facing withdrawal endpoints behind the same authenticated stack:
| POST /withdrawals requests a withdrawal (immediately reserving the funds in
| the wallet's locked balance), GET /withdrawals lists the caller's history and
| GET /withdrawals/{id} returns one of the caller's own withdrawals by id,
| uuid or reference number, scoped to the authenticated user.
|
*/
Route::prefix('v1/withdrawals')
    ->name('api.v1.withdrawals.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [WithdrawalController::class, 'index'])->name('index');
        Route::post('/', [WithdrawalController::class, 'store'])->middleware('throttle:withdrawal')->name('store');
        Route::get('/{withdrawal}', [WithdrawalController::class, 'show'])->name('show');
    });
