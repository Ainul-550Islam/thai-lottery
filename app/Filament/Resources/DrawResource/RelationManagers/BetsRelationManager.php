<?php

declare(strict_types=1);

namespace App\Filament\Resources\DrawResource\RelationManagers;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The bets placed against one draw — read only.
 *
 * A bet is the record of a financial commitment: it was validated, risk-assessed, priced
 * and paid for through BetPurchaseService, and its stake is already posted to the ledger.
 * Editing one from a table would silently break that chain, so this manager offers no
 * create, edit or delete. Cancellation, when it is built, belongs to a service.
 */
class BetsRelationManager extends RelationManager
{
    protected static string $relationship = 'bets';

    protected static ?string $title = 'Bets';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DRAWS);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('bet_number')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('bet_number')
                    ->label('Bet')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('Player')
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (BetType $state): string => strtoupper($state->value)),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (BetStatus $state): string => match ($state) {
                        BetStatus::Won => 'success',
                        BetStatus::Lost => 'gray',
                        BetStatus::Cancelled, BetStatus::Refunded => 'danger',
                        default => 'info',
                    }),

                Tables\Columns\TextColumn::make('stake_amount')
                    ->label('Stake')
                    ->formatStateUsing(fn ($state, Bet $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total staked')),

                Tables\Columns\TextColumn::make('potential_payout')
                    ->label('Potential')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('actual_payout')
                    ->label('Simulated payout')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('placed_at')
                    ->label('Placed')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(BetStatus::cases())
                        ->mapWithKeys(fn (BetStatus $status): array => [$status->value => ucfirst($status->value)])
                        ->all())
                    ->multiple(),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('No bets on this draw');
    }
}
