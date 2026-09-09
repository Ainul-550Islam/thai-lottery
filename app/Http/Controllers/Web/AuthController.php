<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Currency;
use App\Enums\UserStatus;
use App\Enums\WalletStatus;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Web session authentication controller for players.
 */
final class AuthController
{
    /**
     * Show player login view.
     */
    public function showLoginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.login');
    }

    /**
     * Show player registration view.
     */
    public function showRegisterForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.register');
    }

    /**
     * Handle player registration.
     */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'unique:users,username', 'alpha_dash'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'terms' => ['required', 'accepted'],
        ]);

        $user = new User();
        $user->fill([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'status' => UserStatus::Active,
        ]);
        $user->save();

        // Assign default Player role if Spatie Permission is enabled
        if (method_exists($user, 'assignRole')) {
            try {
                $user->assignRole('player');
            } catch (\Throwable $e) {
                // Role might not exist in unseeded test environments
            }
        }

        // Ensure default wallet is provisioned
        $wallet = new Wallet();
        $wallet->user_id = $user->id;
        $wallet->currency = Currency::THB;
        $wallet->status = WalletStatus::Active;
        $wallet->save();

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('player.dashboard')->with('status', 'Welcome to Thai Lottery! Your account is ready.');
    }

    /**
     * Handle player login attempt.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $loginField = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $attemptCredentials = [
            $loginField => $credentials['login'],
            'password' => $credentials['password'],
        ];

        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::attempt($attemptCredentials, $remember)) {
            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->status !== UserStatus::Active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'login' => 'Your account is currently '.$user->status->value.'. Please contact customer support.',
            ]);
        }

        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        $request->session()->regenerate();

        return redirect()->intended(route('player.dashboard'));
    }

    /**
     * Handle player logout.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been logged out.');
    }
}
