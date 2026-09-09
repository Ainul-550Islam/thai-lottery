<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\DrawStatus;
use App\Filament\Resources\DrawResource\DrawLifecycleActions;
use App\Filament\Resources\DrawResource\Pages;
use App\Filament\Resources\DrawResource\RelationManagers;
use App\Models\Draw;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Admin Resource for Lottery Draws Lifecycle Management.
 */
class DrawResource extends Resource
{
    protected static ?string $model = Draw::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Lottery Engine';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DRAWS);
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DRAWS);
    }

    public static function canCreate(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_DRAWS);
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Draw Schedule & Details')
                    ->schema([
                        Forms\Components\TextInput::make('draw_number')
                            ->required()
                            ->maxLength(64),
                        Forms\Components\DateTimePicker::make('scheduled_at')
                            ->required(),
                        Forms\Components\DateTimePicker::make('betting_open_at')
                            ->required(),
                        Forms\Components\DateTimePicker::make('betting_close_at')
                            ->required(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('draw_number')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('betting_close_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => DrawStatus::Open->value,
                        'primary' => DrawStatus::ResultPublished->value,
                        'warning' => DrawStatus::Closed->value,
                        'secondary' => DrawStatus::Scheduled->value,
                        'info' => DrawStatus::Completed->value,
                        'danger' => DrawStatus::Cancelled->value,
                    ]),
                Tables\Columns\TextColumn::make('total_bets')
                    ->label('Bets')
                    ->counts('bets')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_stake')
                    ->money('THB')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(DrawStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions(array_merge(
                [
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make()->visible(fn () => AdminAccess::current(AdminAccess::MANAGE_DRAWS)),
                ],
                DrawLifecycleActions::forTable(),
            ))
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BetsRelationManager::class,
            RelationManagers\NumberLimitsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDraws::route('/'),
            'create' => Pages\CreateDraw::route('/create'),
            'view' => Pages\ViewDraw::route('/{record}'),
            'edit' => Pages\EditDraw::route('/{record}/edit'),
        ];
    }
}
