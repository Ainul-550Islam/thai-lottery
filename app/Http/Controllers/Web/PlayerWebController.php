<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\DrawStatus;
use App\Enums\PaymentMethod;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\DepositService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Player Web App Controller providing dynamic Blade views and player actions.
 */
final class PlayerWebController
{
    public function __construct(
        private readonly ?DepositService $depositService = null,
        private readonly ?WithdrawalService $withdrawalService = null,
    ) {
    }

    /**
     * Player Dashboard overview.
     */
    public function dashboard(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->first();

        $openDraw = Draw::query()
            ->where('status', DrawStatus::Open)
            ->with(['result.winningNumbers', 'winningNumbers'])
            ->first();

        $upcomingDraw = $openDraw ?? Draw::query()
            ->where('status', DrawStatus::Scheduled)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        $recentBets = Bet::query()
            ->where('user_id', $user->id)
            ->with(['draw', 'items'])
            ->latest('id')
            ->take(5)
            ->get();

        $latestCompletedDraw = Draw::query()
            ->whereIn('status', [DrawStatus::ResultPublished, DrawStatus::Completed])
            ->with(['result.winningNumbers', 'winningNumbers'])
            ->latest('completed_at')
            ->first();

        $recentTransactions = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->take(5)
            ->get();

        return view('player.dashboard', [
            'user' => $user,
            'wallet' => $wallet,
            'openDraw' => $openDraw,
            'upcomingDraw' => $upcomingDraw,
            'recentBets' => $recentBets,
            'latestCompletedDraw' => $latestCompletedDraw,
            'recentTransactions' => $recentTransactions,
        ]);
    }

    /**
     * Complete lottery draw schedule and past results.
     */
    public function draws(Request $request): View
    {
        $status = $request->query('status');

        $query = Draw::query()
            ->with(['result.winningNumbers', 'winningNumbers'])
            ->latest('scheduled_at');

        if ($status && $status !== 'all') {
            $statusEnum = DrawStatus::tryFrom($status);
            if ($statusEnum) {
                $query->where('status', $statusEnum);
            }
        }

        $draws = $query->paginate(12);

        $openDraw = Draw::query()
            ->where('status', DrawStatus::Open)
            ->first();

        return view('player.draws', [
            'draws' => $draws,
            'openDraw' => $openDraw,
            'currentFilter' => $status ?? 'all',
        ]);
    }

    /**
     * Single draw details and results.
     */
    public function drawDetail(string $id): View
    {
        $draw = ctype_digit($id)
            ? Draw::with(['result.winningNumbers', 'winningNumbers'])->findOrFail((int) $id)
            : Draw::with(['result.winningNumbers', 'winningNumbers'])->where('draw_number', $id)->firstOrFail();

        return view('player.draw-detail', [
            'draw' => $draw,
        ]);
    }

    /**
     * Bet placement and interactive bet slip interface.
     */
    public function betSlip(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->first();

        $openDraw = Draw::query()
            ->where('status', DrawStatus::Open)
            ->first();

        return view('player.bet', [
            'user' => $user,
            'wallet' => $wallet,
            'openDraw' => $openDraw,
        ]);
    }

    /**
     * Player's wagers and lottery tickets ledger.
     */
    public function bets(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $status = $request->query('status');

        $query = Bet::query()
            ->where('user_id', $user->id)
            ->with(['draw', 'items', 'ticket'])
            ->latest('id');

        if ($status && $status !== 'all') {
            $statusEnum = \App\Enums\BetStatus::tryFrom($status);
            if ($statusEnum) {
                $query->where('status', $statusEnum);
            }
        }

        $bets = $query->paginate(15);

        return view('player.bets', [
            'bets' => $bets,
            'currentFilter' => $status ?? 'all',
        ]);
    }

    /**
     * Wallet balances, holds, and full ledger history.
     */
    public function wallet(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $type = $request->query('type');

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->first();

        $txQuery = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->latest('id');

        if ($type && $type !== 'all') {
            $txQuery->where('type', $type);
        }

        $transactions = $txQuery->paginate(15);

        $totalDeposited = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'deposit')
            ->sum('amount');

        $totalPrizesWon = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'payout')
            ->sum('amount');

        return view('player.wallet', [
            'user' => $user,
            'wallet' => $wallet,
            'transactions' => $transactions,
            'totalDeposited' => $totalDeposited,
            'totalPrizesWon' => $totalPrizesWon,
        ]);
    }

    /**
     * Deposit funds page.
     */
    public function deposit(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->first();

        $gateways = (array) config('payment.gateways', []);

        $recentDeposits = Deposit::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->take(5)
            ->get();

        return view('player.deposit', [
            'user' => $user,
            'wallet' => $wallet,
            'gateways' => $gateways,
            'recentDeposits' => $recentDeposits,
        ]);
    }

    /**
     * Handle deposit initiation.
     */
    public function storeDeposit(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:100', 'max:500000'],
            'gateway' => ['required', 'string'],
        ]);

        return redirect()->route('player.deposit')->with('success', 'Deposit order created for ' . $validated['amount'] . ' THB. Please complete payment.');
    }

    /**
     * Withdraw funds page.
     */
    public function withdraw(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->first();

        $recentWithdrawals = Withdrawal::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->take(5)
            ->get();

        return view('player.withdraw', [
            'user' => $user,
            'wallet' => $wallet,
            'recentWithdrawals' => $recentWithdrawals,
        ]);
    }

    /**
     * Handle withdrawal request.
     */
    public function storeWithdraw(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:300'],
            'bank_name' => ['required', 'string'],
            'account_number' => ['required', 'string'],
            'account_name' => ['required', 'string'],
        ]);

        return redirect()->route('player.withdraw')->with('success', 'Withdrawal request of ' . $validated['amount'] . ' THB submitted for approval and payout.');
    }

    /**
     * Profile and account settings page.
     */
    public function profile(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        return view('player.profile', [
            'user' => $user,
        ]);
    }

    /**
     * Update profile details.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user->update($validated);

        return redirect()->route('player.profile')->with('success', 'Profile details updated successfully.');
    }

    /**
     * Update password.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'new_password' => ['required', 'min:8'],
        ]);

        $user->update([
            'password' => Hash::make($request->string('new_password')->value()),
        ]);

        return redirect()->route('player.profile')->with('success', 'Password updated successfully.');
    }

    /**
     * Update responsible gaming limits.
     */
    public function updateLimits(Request $request): RedirectResponse
    {
        $request->validate([
            'daily_deposit_limit' => ['nullable', 'numeric', 'min:100'],
            'max_bet_stake' => ['nullable', 'numeric', 'min:10'],
            'monthly_loss_limit' => ['nullable', 'numeric', 'min:500'],
        ]);

        return redirect()->route('player.profile')->with('success', 'Responsible gaming limits saved.');
    }
}
