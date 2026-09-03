<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Withdrawals waiting on a human, oldest first.
 *
 * Withdrawals rather than deposits, because a withdrawal is the only request in this
 * system where a player is waiting on money leaving the platform: the delay is visible to
 * them and the decision is irreversible once completed. Deposits get their own resource
 * but not the dashboard's scarce space.
 *
 * This widget only lists and links. Approving happens on the resource, where the
 * confirmation, the reason field and the audit trail live.
 */
class PendingApprovalsWidget extends TableWidget
{
    protected static ?string $heading = 'Withdrawals waiting for a decision';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::MANAGE_PAYOUTS,
            AdminAccess::VIEW_FINANCIAL_REPORTS,
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Withdrawal::query()
                    ->with('user')
                    ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview])
                    ->orderBy('created_at')
            )
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Every withdrawal request has been decided.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('reference_number')
                    ->label('Reference')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('Player')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Withdrawal $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('status')
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Requested')
                    ->since()
                    ->tooltip(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),
            ])
            ->recordUrl(fn (Withdrawal $record): string => \App\Filament\Resources\WithdrawalResource::getUrl('view', ['record' => $record]))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->limit(50));
    }
}
