<?php

declare(strict_types=1);

namespace App\Filament\Resources\WithdrawalResource\Pages;

use App\Enums\WithdrawalStatus;
use App\Filament\Resources\WithdrawalResource;
use App\Models\Withdrawal;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The payout review queue.
 *
 * The tab order follows the money rather than the alphabet: requests waiting on a human
 * first, then the ones already holding reserved funds — which are the riskiest state to
 * leave sitting, because the player's available balance is already reduced while nothing has
 * been paid — then the settled and refused history.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No header actions: there is no create path for a withdrawal from the panel, and bulk
 * approval is not offered because each approval reserves a real balance.
 */
class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;

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
            'waiting' => Tab::make('Waiting for a decision')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    WithdrawalStatus::Pending,
                    WithdrawalStatus::UnderReview,
                ]))
                ->badge(fn (): int => Withdrawal::query()->whereIn('status', [
                    WithdrawalStatus::Pending,
                    WithdrawalStatus::UnderReview,
                ])->count())
                ->badgeColor('danger'),

            'reserved' => Tab::make('Funds reserved')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    WithdrawalStatus::Approved,
                    WithdrawalStatus::Processing,
                ]))
                ->badge(fn (): int => Withdrawal::query()->whereIn('status', [
                    WithdrawalStatus::Approved,
                    WithdrawalStatus::Processing,
                ])->count())
                ->badgeColor('warning'),

            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', WithdrawalStatus::Completed)),

            'refused' => Tab::make('Refused or failed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    WithdrawalStatus::Rejected,
                    WithdrawalStatus::Failed,
                    WithdrawalStatus::Cancelled,
                ])),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Withdrawal::query()
            ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview])
            ->exists() ? 'waiting' : 'all';
    }
}
