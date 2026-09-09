<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\WithdrawalStatus;
use App\Filament\Resources\WithdrawalResource\Pages;
use App\Filament\Resources\WithdrawalResource\WithdrawalDecisionActions;
use App\Models\Withdrawal;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Admin Resource for Viewing and Managing Player Withdrawal Requests.
 */
class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Financial Operations';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_FINANCE);
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_FINANCE);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference_number')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('Player')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('fee')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('net_amount')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('bank_name')
                    ->label('Bank')
                    ->searchable(),
                Tables\Columns\TextColumn::make('account_number')
                    ->label('Account No')
                    ->searchable(),
                Tables\Columns\TextColumn::make('account_name')
                    ->label('Account Name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => WithdrawalStatus::Pending->value,
                        'info' => WithdrawalStatus::Approved->value,
                        'primary' => WithdrawalStatus::Processing->value,
                        'success' => WithdrawalStatus::Completed->value,
                        'danger' => WithdrawalStatus::Rejected->value,
                        'secondary' => WithdrawalStatus::Failed->value,
                    ]),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(WithdrawalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions(array_merge(
                [
                    Tables\Actions\ViewAction::make(),
                ],
                WithdrawalDecisionActions::forTable(),
            ))
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawals::route('/'),
            'view' => Pages\ViewWithdrawal::route('/{record}'),
        ];
    }
}
