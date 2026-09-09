<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BetStatus;
use App\Filament\Resources\BetResource\Pages;
use App\Models\Bet;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Admin Resource for Managing Player Bets and Wagers.
 */
class BetResource extends Resource
{
    protected static ?string $model = Bet::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Wagering Ledger';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_BETS);
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_BETS);
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
                Tables\Columns\TextColumn::make('bet_number')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('Player')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('draw.draw_number')
                    ->label('Draw')
                    ->searchable(),
                Tables\Columns\TextColumn::make('stake_amount')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('actual_payout')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => BetStatus::Won->value,
                        'secondary' => BetStatus::Lost->value,
                        'warning' => BetStatus::Pending->value,
                        'primary' => BetStatus::Active->value,
                        'danger' => BetStatus::Cancelled->value,
                    ]),
                Tables\Columns\TextColumn::make('placed_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(BetStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBets::route('/'),
            'view' => Pages\ViewBet::route('/{record}'),
        ];
    }
}
