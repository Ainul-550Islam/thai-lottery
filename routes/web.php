<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\PlayerWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System Probes & Telemetry
|--------------------------------------------------------------------------
*/
Route::get('/up', [HealthController::class, 'live'])->name('health.live');
Route::get('/up/live', [HealthController::class, 'live'])->name('health.live.alias');
Route::get('/up/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/up/health', [HealthController::class, 'health'])->name('health.detailed');
Route::get('/metrics', [MetricsController::class, 'metrics'])->name('metrics');

/*
|--------------------------------------------------------------------------
| Web Routes — Thai Lottery Platform
|--------------------------------------------------------------------------
*/

// Public routes & redirects
Route::get('/', function () {
    return redirect()->route('player.dashboard');
});

// Authentication routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.attempt');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Player Authenticated Routes
Route::middleware(['auth'])->prefix('player')->name('player.')->group(function () {
    Route::get('/dashboard', [PlayerWebController::class, 'dashboard'])->name('dashboard');
    Route::get('/draws', [PlayerWebController::class, 'draws'])->name('draws');
    Route::get('/draws/{id}', [PlayerWebController::class, 'drawDetail'])->name('draws.detail');
    Route::get('/draw-detail/{id}', [PlayerWebController::class, 'drawDetail'])->name('draw-detail');
    Route::get('/bet', [PlayerWebController::class, 'betSlip'])->name('bet');
    Route::get('/bets', [PlayerWebController::class, 'bets'])->name('bets');
    Route::get('/wallet', [PlayerWebController::class, 'wallet'])->name('wallet');
    Route::get('/deposit', [PlayerWebController::class, 'deposit'])->name('deposit');
    Route::post('/deposit', [PlayerWebController::class, 'storeDeposit'])->name('deposit.store');
    Route::get('/withdraw', [PlayerWebController::class, 'withdraw'])->name('withdraw');
    Route::post('/withdraw', [PlayerWebController::class, 'storeWithdraw'])->name('withdraw.store');
    Route::get('/profile', [PlayerWebController::class, 'profile'])->name('profile');
    Route::put('/profile', [PlayerWebController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/password', [PlayerWebController::class, 'updatePassword'])->name('profile.password');
    Route::post('/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('limits.store');
    Route::post('/profile/kyc', [PlayerWebController::class, 'updateProfile'])->name('kyc.upload');
    Route::post('/profile/self-exclusion', [PlayerWebController::class, 'updateProfile'])->name('self-exclusion.store');
});

// Root-level aliases for direct player routes
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [PlayerWebController::class, 'dashboard']);
    Route::get('/draws', [PlayerWebController::class, 'draws']);
    Route::get('/draws/{id}', [PlayerWebController::class, 'drawDetail']);
    Route::get('/bet', [PlayerWebController::class, 'betSlip']);
    Route::get('/bets', [PlayerWebController::class, 'bets']);
    Route::get('/wallet', [PlayerWebController::class, 'wallet']);
    Route::get('/deposit', [PlayerWebController::class, 'deposit']);
    Route::get('/withdraw', [PlayerWebController::class, 'withdraw']);
    Route::get('/profile', [PlayerWebController::class, 'profile']);
});
