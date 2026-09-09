<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BetType;
use App\Enums\LimitStatus;
use App\Filament\Resources\NumberLimitResource\Pages;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Admin Resource for Configuring Risk and Exposure Ceilings per Number.
 */
class NumberLimitResource extends Resource
{
    protected static ?string $model = NumberLimit::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Risk Management';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_RISK_LIMITS);
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_RISK_LIMITS);
    }

    public static function canCreate(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_RISK_LIMITS);
    }

    public static function canDelete(mixed $record): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_RISK_LIMITS)
            && $record instanceof NumberLimit
            && bccomp((string) $record->current_amount, '0.00', 2) === 0;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Limit Configuration')
                    ->schema([
                        Forms\Components\Select::make('draw_id')
                            ->relationship('draw', 'draw_number')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('bet_type')
                            ->options(collect(BetType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                            ->required(),
                        Forms\Components\TextInput::make('number')
                            ->required()
                            ->maxLength(6)
                            ->numeric(),
                        Forms\Components\TextInput::make('max_amount')
                            ->label('Max Stake Capacity (THB)')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        Forms\Components\TextInput::make('maximum_payout_exposure')
                            ->label('Max Payout Exposure (THB, optional)')
                            ->numeric()
                            ->nullable(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('draw.draw_number')
                    ->label('Draw')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('bet_type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('number')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('max_amount')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_amount')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('remaining_amount')
                    ->money('THB'),
                Tables\Columns\TextColumn::make('utilisation_percent')
                    ->label('Utilisation')
                    ->formatStateUsing(fn ($state) => $state.'%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => LimitStatus::Active->value,
                        'danger' => LimitStatus::Exceeded->value,
                        'warning' => LimitStatus::Suspended->value,
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('draw_id')
                    ->relationship('draw', 'draw_number'),
                Tables\Filters\SelectFilter::make('bet_type')
                    ->options(collect(BetType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(LimitStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn () => AdminAccess::current(AdminAccess::MANAGE_RISK_LIMITS)),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNumberLimits::route('/'),
            'create' => Pages\CreateNumberLimit::route('/create'),
            'edit' => Pages\EditNumberLimit::route('/{record}/edit'),
        ];
    }
}
