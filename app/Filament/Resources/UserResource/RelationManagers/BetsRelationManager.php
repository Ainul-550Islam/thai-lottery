<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * What this account has staked — read only.
 *
 * The same argument as DrawResource's bets manager, from the other direction: a bet is a
 * priced, risk-assessed, ledger-posted commitment, and editing one from a table would
 * break the chain that produced it. Here it exists because the question an operator has
 * while looking at a suspended account is almost always "what were they doing?", and
 * making them go draw-by-draw to answer it is how a moderation decision gets made on
 * incomplete information.
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
        return AdminAccess::currentAny([
            AdminAccess::MANAGE_USERS,
            AdminAccess::VIEW_DRAWS,
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('bet_number')
            ->defaultSort('placed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('bet_number')
                    ->label('Bet')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('draw.draw_number')
                    ->label('Draw')
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
                    ->formatStateUsing(fn ($state, Bet $record): string => AdminFormat::money($state, is_object($record->currency) ? $record->currency->value : (string) $record->currency))
                    ->alignEnd(),

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
            ->emptyStateHeading('This account has never placed a bet');
    }
}
