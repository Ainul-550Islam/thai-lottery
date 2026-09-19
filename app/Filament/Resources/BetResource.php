<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Filament\Resources\BetResource\Pages;
use App\Models\Bet;
use App\Models\BetItem;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Placed bets — a read-only ledger of commitments.
 *
 * WHY THIS EXISTS
 * By the time a row lands in `bets` the money has already moved: BetPurchaseService
 * validated the selection, NumberLimitEngine reserved the exposure inside a lock, the
 * wallet was debited and the double-entry ledger was posted. The row is therefore not a
 * draft that an operator may correct — it is the *evidence* of what the platform did.
 * Operators still need to see it: to answer "why was this player charged 500", to trace a
 * disputed selection back to its draw, and to compare a simulated prize against the
 * stake. That reading need is what this resource serves, and only that.
 *
 * WHY EVERY MUTATION IS ABSENT RATHER THAN MERELY HIDDEN
 * canCreate/canEdit/canDelete return a hard false, the resource registers no create or
 * edit page, and neither page class declares a header action. This is deliberate
 * belt-and-braces: hiding a button still leaves the Livewire action callable by anyone
 * who knows its name, whereas a resource with no such action and an authorization gate
 * that never opens cannot be coaxed into writing. Cancelling or refunding a bet is a
 * domain operation with ledger consequences; when a service for it exists it will be
 * offered here as a delegating action, not as an Eloquent update.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No "recalculate payout" button. The simulated prize shown per selection is read from
 *   the multiplier recorded at placement time (BetItem::calculatePayout(), pure bcmath),
 *   never re-derived from today's configuration — a multiplier that changed after the
 *   bet was sold must not silently rewrite history on screen.
 * - No bulk actions, not even export: an export of financial rows is a compliance
 *   artefact and belongs with the audit surface, not bolted onto a browse screen.
 * - No dedicated permission is invented. The seeded catalogue has no "view bets" phrase,
 *   so the resource reads to anyone who may already see draws or transaction history;
 *   that gap is recorded in FILAMENT-PROGRESS-lottery.md rather than papered over with a
 *   made-up permission string.
 */
class BetResource extends Resource
{
    protected static ?string $model = Bet::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'bet_number';

    /**
     * There is no 'view bets' permission in the seeded catalogue. Rather than invent
     * one, the resource accepts either of the two phrases whose holders legitimately
     * need this screen: draw operators, and the finance/audit readers who follow money.
     *
     * @var list<string>
     */
    public const READ_PERMISSIONS = [
        AdminAccess::VIEW_DRAWS,
        AdminAccess::VIEW_TRANSACTION_HISTORY,
    ];

    public static function canViewAny(): bool
    {
        return AdminAccess::currentAny(self::READ_PERMISSIONS);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // A bet is created by BetPurchaseService against a player's wallet. There is no
        // honest way for an operator to "add" one from a form.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    /**
     * No form: the resource has no create or edit page, and Filament only reaches this
     * method through one of them. It is declared empty so that a future contributor who
     * adds a page gets a blank screen rather than an accidentally editable bet.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager loads chosen from the columns actually rendered below: without them the
        // player and draw columns issue two queries per row.
        return parent::getEloquentQuery()->with(['user', 'draw']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('placed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('bet_number')
                    ->label('Bet')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('Player')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('draw.draw_number')
                    ->label('Draw')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (BetType $state): string => strtoupper($state->value))
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BetStatus $state): string => $state->label())
                    ->color(fn (BetStatus $state): string => match ($state) {
                        BetStatus::Won => 'success',
                        BetStatus::Lost => 'gray',
                        BetStatus::Cancelled, BetStatus::Refunded => 'danger',
                        BetStatus::Active => 'info',
                        default => 'warning',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_numbers')
                    ->label('Selections')
                    ->formatStateUsing(fn ($state): string => AdminFormat::count($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stake_amount')
                    ->label('Stake')
                    ->formatStateUsing(fn ($state, Bet $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('potential_payout')
                    ->label('Potential')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('actual_payout')
                    ->label('Simulated payout')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('placed_at')
                    ->label('Placed ('.AdminFormat::timezoneLabel().')')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Recorded')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(BetStatus::cases())
                        ->mapWithKeys(fn (BetStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('type')
                    ->options(fn (): array => collect(BetType::cases())
                        ->mapWithKeys(fn (BetType $type): array => [$type->value => strtoupper($type->value)])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('draw_id')
                    ->label('Draw')
                    ->relationship('draw', 'draw_number')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Player')
                    ->relationship('user', 'username')
                    ->searchable(),

                Tables\Filters\Filter::make('placed_between')
                    ->label('Placed between')
                    ->form([
                        DatePicker::make('placed_from')->label('From'),
                        DatePicker::make('placed_until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['placed_from'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('placed_at', '>=', $date),
                            )
                            ->when(
                                $data['placed_until'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('placed_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (($data['placed_from'] ?? null) !== null) {
                            $indicators[] = 'Placed from '.$data['placed_from'];
                        }

                        if (($data['placed_until'] ?? null) !== null) {
                            $indicators[] = 'Placed until '.$data['placed_until'];
                        }

                        return $indicators;
                    }),

                Tables\Filters\Filter::make('stake_between')
                    ->label('Stake between')
                    ->form([
                        // Deliberately TextInput, not ->numeric(): a stake bound is
                        // compared against a DECIMAL(20,2) column and must never be cast
                        // to float on its way into the query.
                        TextInput::make('stake_min')
                            ->label('Minimum stake')
                            ->rule('regex:/^\d{1,18}(\.\d{1,2})?$/'),
                        TextInput::make('stake_max')
                            ->label('Maximum stake')
                            ->rule('regex:/^\d{1,18}(\.\d{1,2})?$/'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                self::decimalOrNull($data['stake_min'] ?? null),
                                fn (Builder $q, string $min): Builder => $q->where('stake_amount', '>=', $min),
                            )
                            ->when(
                                self::decimalOrNull($data['stake_max'] ?? null),
                                fn (Builder $q, string $max): Builder => $q->where('stake_amount', '<=', $max),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (self::decimalOrNull($data['stake_min'] ?? null) !== null) {
                            $indicators[] = 'Stake ≥ '.AdminFormat::money($data['stake_min']);
                        }

                        if (self::decimalOrNull($data['stake_max'] ?? null) !== null) {
                            $indicators[] = 'Stake ≤ '.AdminFormat::money($data['stake_max']);
                        }

                        return $indicators;
                    }),

                Tables\Filters\TernaryFilter::make('winners')
                    ->label('Winning bets')
                    ->placeholder('All bets')
                    ->trueLabel('Won only')
                    ->falseLabel('Not won')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('status', BetStatus::Won),
                        false: fn (Builder $query): Builder => $query->where('status', '!=', BetStatus::Won->value),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->headerActions([])
            ->bulkActions([])
            ->emptyStateHeading('No bets recorded')
            ->emptyStateDescription('Bets appear here once BetPurchaseService accepts one; nothing on this screen can create one.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Bet')
                ->schema([
                    Infolists\Components\TextEntry::make('bet_number')->label('Bet number')->copyable(),
                    Infolists\Components\TextEntry::make('user.username')->label('Player')->placeholder('—'),
                    Infolists\Components\TextEntry::make('draw.draw_number')->label('Draw')->placeholder('—'),
                    Infolists\Components\TextEntry::make('ticket.ticket_number')->label('Ticket')->placeholder('not ticketed'),
                    Infolists\Components\TextEntry::make('type')
                        ->badge()
                        ->formatStateUsing(fn (BetType $state): string => strtoupper($state->value)),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (BetStatus $state): string => $state->label()),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Money')
                ->description('Exact decimal strings as stored. Nothing on this page recomputes them.')
                ->schema([
                    Infolists\Components\TextEntry::make('stake_amount')
                        ->label('Stake')
                        ->formatStateUsing(fn ($state, Bet $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('potential_payout')
                        ->label('Potential payout')
                        ->formatStateUsing(fn ($state, Bet $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('actual_payout')
                        ->label('Simulated payout')
                        ->formatStateUsing(fn ($state, Bet $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('total_numbers')
                        ->label('Selections')
                        ->formatStateUsing(fn ($state): string => AdminFormat::count($state)),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Timeline ('.AdminFormat::timezoneLabel().' time)')
                ->schema([
                    Infolists\Components\TextEntry::make('placed_at')->label('Placed')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('won_at')->label('Won')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('cancelled_at')->label('Cancelled')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('cancelled_reason')->label('Cancellation reason')->placeholder('—'),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Selections')
                ->description('One row per selected number, as sold. The simulated prize is the stake times the multiplier recorded at placement time — read back with bcmath, never re-priced from current configuration.')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('number')
                                ->label('Number')
                                ->formatStateUsing(fn ($state): string => AdminFormat::number($state))
                                ->weight('bold'),

                            Infolists\Components\TextEntry::make('position')
                                ->label('Position')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('amount')
                                ->label('Stake')
                                ->formatStateUsing(fn ($state): string => AdminFormat::money($state)),

                            Infolists\Components\TextEntry::make('payout_multiplier')
                                ->label('Multiplier')
                                ->formatStateUsing(fn ($state): string => AdminFormat::count($state).'×'),

                            Infolists\Components\TextEntry::make('simulated_prize')
                                ->label('Simulated prize')
                                ->state(fn (BetItem $record): string => AdminFormat::money($record->calculatePayout()))
                                ->tooltip('stake × multiplier, computed with bcmath at scale 2'),

                            Infolists\Components\TextEntry::make('potential_payout')
                                ->label('Recorded potential')
                                ->formatStateUsing(fn ($state): string => AdminFormat::money($state)),

                            Infolists\Components\IconEntry::make('is_winner')
                                ->label('Winner')
                                ->boolean(),

                            Infolists\Components\TextEntry::make('actual_payout')
                                ->label('Settled payout')
                                ->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Metadata')
                ->collapsed()
                ->schema([
                    Infolists\Components\KeyValueEntry::make('metadata')->label(''),
                ]),
        ]);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        // No 'create' and no 'edit' route exists at all. That is the strongest possible
        // statement of read-only: there is no URL to guess.
        return [
            'index' => Pages\ListBets::route('/'),
            'view' => Pages\ViewBet::route('/{record}'),
        ];
    }

    /**
     * A filter bound that is an exact decimal string, or null.
     *
     * Returned as a string so it reaches the query builder untouched: casting a money
     * bound to float here would be the one place the panel introduced a rounding error.
     */
    private static function decimalOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $raw = trim((string) $value);

        return preg_match('/^\d{1,18}(\.\d{1,2})?$/', $raw) === 1 ? $raw : null;
    }
}
