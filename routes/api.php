<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BetController;
use App\Http\Controllers\Api\V1\BetPurchaseController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\DrawController;
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
| API Routes — Thai Lottery Platform
|--------------------------------------------------------------------------
*/

// Public Authentication
Route::prefix('v1/auth')
    ->name('api.v1.auth.')
    ->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login');

        Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->middleware('active')->name('me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

// Webhook Endpoints
Route::prefix('v1/payments/webhook')
    ->middleware(['throttle:webhook'])
    ->group(function (): void {
        Route::post('/{gateway}', [PaymentWebhookController::class, 'handle'])
            ->name('api.v1.payments.webhook');
    });

Route::prefix('v1/webhooks')
    ->middleware(['throttle:webhook', 'webhook.signature'])
    ->group(function (): void {
        Route::post('/{gateway}', [PaymentWebhookController::class, 'handle'])
            ->name('api.v1.webhooks.handle');
    });

// Authenticated Player API
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {

        // Wagering & Bets
        Route::get('/bets', [BetController::class, 'index'])->name('bets.index');
        Route::post('/bets/purchase', [BetPurchaseController::class, 'store'])
            ->middleware('throttle:bet')
            ->name('bets.purchase');
        Route::get('/bets/{bet}', [BetController::class, 'show'])
            ->where('bet', '[A-Za-z0-9-]{1,64}')
            ->name('bets.show');
        Route::get('/bets/{bet}/status', [BetController::class, 'status'])
            ->where('bet', '[A-Za-z0-9-]{1,64}')
            ->name('bets.status');

        // Tickets
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
            ->where('ticket', '[A-Za-z0-9-]{1,64}')
            ->name('tickets.show');

        // Draws
        Route::get('/draws', [DrawController::class, 'index'])->name('draws.index');
        Route::get('/draws/open', [DrawController::class, 'open'])->name('draws.open');
        Route::get('/draws/current', [DrawController::class, 'open'])->name('draws.current');
        Route::get('/draws/{draw}', [DrawController::class, 'show'])->name('draws.show');
        Route::get('/draws/{draw}/results', [DrawController::class, 'results'])->name('draws.results');

        // Wallet & Transactions
        Route::get('/wallet', [WalletController::class, 'show'])->name('wallet.show');
        Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');

        // Deposits
        Route::get('/deposits/methods', [DepositController::class, 'methods'])->name('deposits.methods');
        Route::post('/deposits', [DepositController::class, 'store'])
            ->middleware('throttle:deposit')
            ->name('deposits.store');
        Route::get('/deposits', [DepositController::class, 'index'])->name('deposits.index');
        Route::get('/deposits/{deposit}', [DepositController::class, 'show'])->name('deposits.show');

        // Withdrawals
        Route::post('/withdrawals', [WithdrawalController::class, 'store'])
            ->middleware('throttle:withdrawal')
            ->name('withdrawals.store');
        Route::get('/withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('/withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->name('withdrawals.show');

        // Player Profile
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::match(['put', 'post'], '/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::match(['put', 'post'], '/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        // Responsible Gaming
        Route::get('/responsible-gaming', [ResponsibleGamingController::class, 'index'])->name('responsible-gaming.index');
        Route::get('/responsible-gaming/limits', [ResponsibleGamingController::class, 'index'])->name('limits.index');
        Route::match(['put', 'post'], '/responsible-gaming/limits', [ResponsibleGamingController::class, 'store'])->name('limits.store');
        Route::post('/responsible-gaming/self-exclude', [ResponsibleGamingController::class, 'selfExclude'])->name('self-exclude');
        Route::post('/responsible-gaming/self-exclusion', [ResponsibleGamingController::class, 'selfExclude'])->name('self-exclusion');

        // KYC Verification
        Route::get('/kyc', [KycController::class, 'status'])->name('kyc.status');
        Route::get('/kyc/status', [KycController::class, 'status'])->name('kyc.status.alias');
        Route::post('/kyc/upload', [KycController::class, 'upload'])->name('kyc.upload.alias');
        Route::post('/kyc/documents', [KycController::class, 'upload'])->name('kyc.upload');
        Route::get('/kyc/documents', [KycController::class, 'documents'])->name('kyc.documents');
    });
