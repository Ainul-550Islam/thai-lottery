<?php

declare(strict_types=1);

namespace App\Filament\Resources\DrawResource\RelationManagers;

use App\Enums\BetType;
use App\Enums\LimitStatus;
use App\Filament\Resources\NumberLimitResource;
use App\Models\NumberLimit;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The exposure ceilings that apply to one draw.
 *
 * WHY THIS MANAGER IS JUSTIFIED (the schema was checked before it was written)
 * 2024_01_03_000400_create_number_limits_table.php declares
 * `$table->foreignId('draw_id')->constrained()->cascadeOnDelete()` — NOT NULL — and a
 * unique key on (draw_id, bet_type, number). App\Models\Draw exposes numberLimits() as a
 * HasMany. Limits are therefore genuinely scoped per draw, not global, and
 * NumberLimitEngine::assess() says so in its own refusal text: "number_limits.draw_id is
 * NOT NULL, so the schema cannot express a global or default limit row." A relation
 * manager is the right shape for this data; a global risk-settings page would not be.
 *
 * WHY IT IS READ-ONLY EVEN THOUGH THE RESOURCE IS NOT
 * Creating a limit means choosing a bet type and then a number whose digit count depends
 * on that type, with a live helper explaining the shape. That form belongs on a full
 * page, and it already exists at NumberLimitResource. Duplicating it into a modal here
 * would mean two validation surfaces that must agree forever. Instead this table shows
 * the draw's risk picture — reserved against remaining, worst-utilised first — and links
 * each row to the real editor.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No attach/detach. The relationship is a foreign key on the limit, not a pivot; there
 *   is nothing to attach.
 * - No delete, not even for untouched rows. The deletion guard lives on the resource
 *   where its confirmation text can explain itself; a delete button on a sub-table of a
 *   draw page is too easy to press by accident.
 * - No aggregate "total exposure on this draw" summariser. Summing ceilings would suggest
 *   the house is liable for all of them at once, which is false: at most one number wins.
 */
class NumberLimitsRelationManager extends RelationManager
{
    protected static string $relationship = 'numberLimits';

    protected static ?string $title = 'Number limits';

    protected static ?string $icon = 'heroicon-o-shield-exclamation';

    protected static ?string $modelLabel = 'number limit';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return AdminAccess::currentAny(NumberLimitResource::READ_PERMISSIONS);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('current_amount', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('bet_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (BetType $state): string => strtoupper($state->value))
                    ->sortable(),

                Tables\Columns\TextColumn::make('number')
                    ->label('Number')
                    ->formatStateUsing(fn ($state): string => AdminFormat::number($state))
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('max_amount')
                    ->label('Stake ceiling')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('current_amount')
                    ->label('Reserved')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('remaining_amount')
                    ->label('Remaining')
                    ->state(fn (NumberLimit $record): string => AdminFormat::money($record->remaining_amount))
                    ->alignEnd()
                    ->color(fn (NumberLimit $record): string => bccomp((string) $record->remaining_amount, '0', 2) === 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('utilisation')
                    ->label('Utilisation')
                    ->state(fn (NumberLimit $record): string => $record->utilisation_percent.'%')
                    ->alignEnd()
                    ->color(fn (NumberLimit $record): string => match (true) {
                        bccomp((string) $record->utilisation_percent, '100', 2) >= 0 => 'danger',
                        bccomp((string) $record->utilisation_percent, '80', 2) >= 0 => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('current_payout_exposure')
                    ->label('Payout exposure')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (LimitStatus $state): string => $state->label())
                    ->color(fn (LimitStatus $state): string => $state->color()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bet_type')
                    ->label('Bet type')
                    ->options(fn (): array => collect(BetType::cases())
                        ->mapWithKeys(fn (BetType $type): array => [$type->value => strtoupper($type->value)])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(LimitStatus::cases())
                        ->mapWithKeys(fn (LimitStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('openLimit')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (NumberLimit $record): string => NumberLimitResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (NumberLimit $record): bool => NumberLimitResource::canEdit($record)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No number limits on this draw')
            ->emptyStateDescription('Without a limit row, NumberLimitEngine cannot reserve capacity for a number on this draw.');
    }
}
