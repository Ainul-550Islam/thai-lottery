<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletResource\Pages;

use App\Enums\WalletStatus;
use App\Filament\Resources\WalletResource;
use App\Models\Wallet;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every wallet, with the two subsets an operator ever hunts for.
 *
 * "Locked" is the queue of players who currently cannot transact, which is the state most
 * likely to generate a support ticket, and "reserved funds" surfaces wallets whose available
 * balance is lower than their total — the usual explanation for "my balance is wrong".
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No header actions: wallets are not created from the panel, and there is no bulk lock. A
 * lock stops a player transacting and requires a reason each time, so it is a per-record
 * decision by design.
 */
class ListWallets extends ListRecords
{
    protected static string $resource = WalletResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'locked' => Tab::make('Locked')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', WalletStatus::Locked))
                ->badge(fn (): int => Wallet::query()->where('status', WalletStatus::Locked)->count())
                ->badgeColor('danger'),

            'reserved' => Tab::make('Holding reserved funds')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('locked_balance', '>', 0))
                ->badge(fn (): int => Wallet::query()->where('locked_balance', '>', 0)->count())
                ->badgeColor('warning'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
