<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\BetStatus;
use App\Enums\DepositStatus;
use App\Enums\DrawStatus;
use App\Enums\UserStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * The five numbers an operator checks first.
 *
 * Every figure is a COUNT or a SUM straight from the database - nothing here recomputes a
 * balance, and no stat is derived from another stat, so a wrong number here can only mean
 * wrong data, never wrong arithmetic in this file.
 */
class PlatformStatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DASHBOARD);
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $activePlayers = User::query()->where('status', UserStatus::Active)->count();

        $openDraws = Draw::query()->where('status', DrawStatus::Open)->count();
        $nextDraw = Draw::query()
            ->whereIn('status', [DrawStatus::Scheduled, DrawStatus::Open])
            ->orderBy('scheduled_at')
            ->first();

        $betsToday = Bet::query()->whereDate('created_at', today())->count();
        $stakeToday = (string) (Bet::query()
            ->whereDate('created_at', today())
            ->whereNot('status', BetStatus::Cancelled)
            ->sum(DB::raw('stake_amount')) ?: '0');

        $pendingDeposits = Deposit::query()->where('status', DepositStatus::Pending)->count();
        $pendingWithdrawals = Withdrawal::query()->where('status', WithdrawalStatus::Pending)->count();

        return [
            Stat::make('Active players', AdminFormat::count($activePlayers))
                ->description('Accounts permitted to transact')
                ->icon('heroicon-o-users')
                ->color('primary'),

            Stat::make('Open draws', AdminFormat::count($openDraws))
                ->description($nextDraw !== null
                    ? 'Next: '.$nextDraw->draw_number.' at '.AdminFormat::marketTime($nextDraw->scheduled_at)
                    : 'No draw scheduled — run lottery:schedule-draws')
                ->icon('heroicon-o-ticket')
                ->color($openDraws > 0 ? 'success' : 'warning'),

            Stat::make('Bets today', AdminFormat::count($betsToday))
                ->description('Stake '.AdminFormat::money($stakeToday, AdminFormat::currency()))
                ->icon('heroicon-o-banknotes')
                ->color('info'),

            Stat::make('Waiting for a human', AdminFormat::count($pendingDeposits + $pendingWithdrawals))
                ->description($pendingDeposits.' deposit(s), '.$pendingWithdrawals.' withdrawal(s)')
                ->icon('heroicon-o-hand-raised')
                ->color(($pendingDeposits + $pendingWithdrawals) > 0 ? 'danger' : 'success'),
        ];
    }
}
