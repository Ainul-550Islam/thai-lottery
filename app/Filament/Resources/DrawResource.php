<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Filament\Resources\DrawResource\DrawLifecycleActions;
use App\Filament\Resources\DrawResource\Pages;
use App\Models\Draw;
use App\Services\Draw\DrawLifecycleService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Draws, and the four operator decisions a draw can need.
 *
 * WHY THE ACTIONS LOOK LIKE THIS
 * Every button here delegates to the Phase 5.1 service that owns the rule:
 *
 *   open / close / cancel      -> DrawLifecycleService
 *   publish official numbers   -> DrawResultPublicationService
 *   settle                     -> DrawSettlementSimulationService
 *
 * The panel never writes draws.status, never writes a timestamp and never computes a
 * payout. It decides only whether to *offer* a button, and reports what the service said.
 * That is why a broken transition surfaces as a red notification rather than a corrupted
 * draw: the service refuses and nothing is written.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No editing of a draw after publication. The lifecycle service locks it and this
 *   resource does not try to work around that.
 * - No "unpublish" and no "unsettle". Neither exists in the domain, on purpose.
 * - Settlement remains the Phase 5.1 SIMULATION: no wallet is credited and no payout row
 *   is written. The action says so in its confirmation text, so an operator is never
 *   misled into thinking players were paid.
 */
class DrawResource extends Resource
{
    protected static ?string $model = Draw::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'draw_number';

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DRAWS);
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_DRAWS);
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_DRAWS)
            && app(DrawLifecycleService::class)->isMutable($record);
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        // Draws are never deleted from the panel: a draw with bets against it is
        // financial history. Cancellation is the supported path.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = Draw::query()->where('status', DrawStatus::Drawing)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Draws awaiting official numbers';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Draw')
                ->description('Only these four fields are modifiable, and only before the result is published — DrawLifecycleService::MODIFIABLE_FIELDS decides that, not this form.')
                ->schema([
                    Forms\Components\TextInput::make('draw_number')
                        ->label('Draw number')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->helperText('The scheduler derives this as DR-YYYYMMDD-HHMM. Keep the format so an automated and a hand-made draw read alike.'),

                    Forms\Components\Select::make('type')
                        ->label('Type')
                        ->options(fn (): array => collect(DrawType::cases())
                            ->mapWithKeys(fn (DrawType $type): array => [$type->value => strtoupper($type->value)])
                            ->all())
                        ->required()
                        ->native(false),

                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label('Scheduled at ('.AdminFormat::timezoneLabel().' time)')
                        ->seconds(false)
                        ->required()
                        ->helperText('Stored in the application timezone; shown here in the market timezone.'),

                    Forms\Components\KeyValue::make('metadata')
                        ->label('Metadata')
                        ->keyLabel('key')
                        ->valueLabel('value')
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('draw_number')
                    ->label('Draw')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (DrawType $state): string => strtoupper($state->value)),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DrawStatus $state): string => $state->label())
                    ->color(fn (DrawStatus $state): string => match ($state) {
                        DrawStatus::Open => 'success',
                        DrawStatus::Drawing => 'warning',
                        DrawStatus::Closed => 'info',
                        DrawStatus::Cancelled => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('Scheduled ('.AdminFormat::timezoneLabel().')')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('result.first_prize')
                    ->label('First prize')
                    ->formatStateUsing(fn ($state): string => AdminFormat::number($state))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('result.bottom_two')
                    ->label('Bottom 2')
                    ->formatStateUsing(fn ($state): string => AdminFormat::number($state))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('total_bets')
                    ->label('Bets')
                    ->formatStateUsing(fn ($state): string => AdminFormat::count($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_amount_wagered')
                    ->label('Stake')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_payout')
                    ->label('Simulated payout')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(DrawStatus::cases())
                        ->mapWithKeys(fn (DrawStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('type')
                    ->options(fn (): array => collect(DrawType::cases())
                        ->mapWithKeys(fn (DrawType $type): array => [$type->value => strtoupper($type->value)])
                        ->all()),

                Tables\Filters\Filter::make('awaiting_numbers')
                    ->label('Awaiting official numbers')
                    ->query(fn ($query) => $query->where('status', DrawStatus::Drawing))
                    ->toggle(),

                Tables\Filters\Filter::make('upcoming')
                    ->label('Upcoming only')
                    ->query(fn ($query) => $query->where('scheduled_at', '>=', now()))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make(DrawLifecycleActions::forTable())
                    ->label('Lifecycle')
                    ->icon('heroicon-m-play-circle')
                    ->button()
                    ->outlined(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No draws yet')
            ->emptyStateDescription('The scheduler creates them from config(lottery.draw.official_schedule); lottery:schedule-draws provisions them now.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Lifecycle')
                ->schema([
                    Infolists\Components\TextEntry::make('draw_number')->label('Draw number')->copyable(),
                    Infolists\Components\TextEntry::make('type')->badge()->formatStateUsing(fn (DrawType $state): string => strtoupper($state->value)),
                    Infolists\Components\TextEntry::make('status')->badge()->formatStateUsing(fn (DrawStatus $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('lifecycle_state')
                        ->label('Lifecycle state')
                        ->state(fn (Draw $record): string => app(DrawLifecycleService::class)->currentState($record)->value),
                    Infolists\Components\TextEntry::make('allowed_transitions')
                        ->label('Allowed next states')
                        ->state(fn (Draw $record): string => collect(app(DrawLifecycleService::class)->allowedTransitions($record))
                            ->map(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                            ->implode(', ') ?: 'none (terminal)'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Timeline ('.AdminFormat::timezoneLabel().' time)')
                ->schema([
                    Infolists\Components\TextEntry::make('betting_open_at')->label('Betting opens')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('betting_close_at')->label('Betting closes')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('scheduled_at')->label('Scheduled')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('opened_at')->label('Opened')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('closed_at')->label('Closed')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('drawn_at')->label('Drawn')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('result_published_at')->label('Result published')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('completed_at')->label('Settled')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Official result')
                ->schema([
                    Infolists\Components\TextEntry::make('result.first_prize')->label('First prize (6 digits)')->placeholder('not published'),
                    Infolists\Components\TextEntry::make('result.bottom_two')->label('Bottom two')->placeholder('not published'),
                    Infolists\Components\TextEntry::make('result.published_at')->label('Published at')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))->placeholder('—'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Totals')
                ->description('Written by the domain services only — the panel never edits these.')
                ->schema([
                    Infolists\Components\TextEntry::make('total_bets')->label('Bets')->formatStateUsing(fn ($state): string => AdminFormat::count($state)),
                    Infolists\Components\TextEntry::make('total_amount_wagered')->label('Total staked')->formatStateUsing(fn ($state): string => AdminFormat::money($state, AdminFormat::currency())),
                    Infolists\Components\TextEntry::make('total_payout')->label('Simulated payout')->formatStateUsing(fn ($state): string => AdminFormat::money($state, AdminFormat::currency())),
                    Infolists\Components\TextEntry::make('house_profit')->label('House profit')->formatStateUsing(fn ($state): string => AdminFormat::money($state, AdminFormat::currency())),
                ])
                ->columns(4),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getRelations(): array
    {
        return [
            DrawResource\RelationManagers\BetsRelationManager::class,
            // Number limits are genuinely per-draw: number_limits.draw_id is NOT NULL with
            // a cascade delete and a unique index on (draw_id, bet_type, number). So a
            // limit has no meaning away from its draw, and the risk officer setting one is
            // always looking at a specific draw when they do it.
            DrawResource\RelationManagers\NumberLimitsRelationManager::class,
        ];
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
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
