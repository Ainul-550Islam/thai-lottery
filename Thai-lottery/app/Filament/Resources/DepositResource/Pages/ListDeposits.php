<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepositResource\Pages;

use App\Enums\DepositStatus;
use App\Filament\Resources\DepositResource;
use App\Models\Deposit;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The deposit work queue.
 *
 * The tabs answer the question this screen is opened with — "what is waiting on me?" —
 * rather than offering a neutral list the operator has to filter every time. The default
 * tab is the pending queue when there is one, because an unreviewed deposit is a player
 * waiting; when the queue is empty the screen falls back to everything, so it never looks
 * broken by showing an empty table.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No header actions at all: this resource has no create path (deposits come from the
 * deposit pipeline) and bulk approval is not offered on purpose — a decision that credits
 * a wallet should be taken one record at a time, with that record's reference in front of
 * the operator.
 */
class ListDeposits extends ListRecords
{
    protected static string $resource = DepositResource::class;

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
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DepositStatus::Pending))
                ->badge(fn (): int => Deposit::query()->where('status', DepositStatus::Pending)->count())
                ->badgeColor('warning'),

            'approved' => Tab::make('Approved, not credited')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', [DepositStatus::Approved, DepositStatus::Processing])
                    ->whereNull('financial_transaction_id'))
                ->badge(fn (): int => Deposit::query()
                    ->whereIn('status', [DepositStatus::Approved, DepositStatus::Processing])
                    ->whereNull('financial_transaction_id')
                    ->count()),

            'credited' => Tab::make('Credited')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DepositStatus::Confirmed)),

            'refused' => Tab::make('Refused')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    DepositStatus::Rejected,
                    DepositStatus::Failed,
                    DepositStatus::Cancelled,
                ])),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Deposit::query()->where('status', DepositStatus::Pending)->exists() ? 'pending' : 'all';
    }
}
