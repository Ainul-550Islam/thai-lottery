<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AgentStatus;
use App\Filament\Resources\AgentResource\Pages;
use App\Models\Agent;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Admin Resource for Viewing Agent Network and Referral Hierarchies.
 */
class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Agent Operations';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_USERS);
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_USERS);
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
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('agent_code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Agent Name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('parent.agent_code')
                    ->label('Parent Agent')
                    ->placeholder('— (Top-Level)'),
                Tables\Columns\TextColumn::make('commission_rate')
                    ->formatStateUsing(fn ($state) => bcmul((string) $state, '100', 2).'%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_referrals')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_commission_earned')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => AgentStatus::Active->value,
                        'warning' => AgentStatus::Suspended->value,
                        'danger' => AgentStatus::Terminated->value,
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(AgentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgents::route('/'),
            'view' => Pages\ViewAgent::route('/{record}'),
        ];
    }
}
