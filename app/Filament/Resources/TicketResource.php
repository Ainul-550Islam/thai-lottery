<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource\Pages;
use App\Models\Ticket;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Betting tickets — the receipt that groups a player's bets for one draw.
 *
 * WHAT THE SCHEMA ACTUALLY SUPPORTS (checked, not assumed)
 * `tickets` is a real, fully populated table, not a stub: ticket_number, user_id,
 * draw_id, status, currency, total_amount, total_bets, total_numbers, four lifecycle
 * timestamps and metadata. App\Models\Ticket carries a TicketStatus cast, a Currency
 * cast, bets(), betItems() (has-many-through bets) and payouts(). Every column shown
 * below exists in 2024_01_03_000500_create_tickets_table.php.
 *
 * The one thing worth flagging is that the counters and total_amount are *cached sums*
 * maintained by the ticket service when the ticket is confirmed, and they are excluded
 * from $fillable precisely so nothing but that service writes them. This screen displays
 * them next to the live count of the bets relation, so a divergence between the cached
 * counter and reality is visible rather than hidden — which is the only honest thing a
 * read-only screen can do about a denormalised column it must not repair.
 *
 * WHY IT IS READ-ONLY
 * Same reasoning as BetResource: a ticket is issued after money has moved, and its
 * status is advanced by the ticket service. No create page, no edit page, no delete, no
 * bulk action, and no header action on either page.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No "cancel ticket" or "expire ticket" action. TicketStatus::canCancel() exists on the
 *   enum, but a *service* that performs the cancellation (and unwinds the bets and the
 *   ledger behind it) does not, and the panel must not become that service.
 * - No recomputation of total_amount from the bets relation. Showing a corrected figure
 *   would mask a genuine data defect; showing both figures surfaces it.
 */
class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'ticket_number';

    /**
     * As with bets, the seeded catalogue has no 'view tickets' phrase; the two existing
     * phrases whose holders need this screen are accepted instead of inventing one.
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

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'draw'])
            ->withCount('bets');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('ticket_number')
                    ->label('Ticket')
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

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TicketStatus $state): string => $state->label())
                    ->color(fn (TicketStatus $state): string => $state->color())
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, Ticket $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_bets')
                    ->label('Bets (cached)')
                    ->formatStateUsing(fn ($state): string => AdminFormat::count($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('bets_count')
                    ->label('Bets (actual)')
                    ->formatStateUsing(fn ($state): string => AdminFormat::count($state))
                    ->alignEnd()
                    ->color(fn ($state, Ticket $record): string => (int) $state === (int) $record->total_bets ? 'gray' : 'danger')
                    ->tooltip('Live count of the bets relation. Red means it disagrees with the cached counter.')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_numbers')
                    ->label('Selections')
                    ->formatStateUsing(fn ($state): string => AdminFormat::count($state))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Issued ('.AdminFormat::timezoneLabel().')')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('Confirmed')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(TicketStatus::cases())
                        ->mapWithKeys(fn (TicketStatus $status): array => [$status->value => $status->label()])
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

                Tables\Filters\Filter::make('issued_between')
                    ->label('Issued between')
                    ->form([
                        DatePicker::make('issued_from')->label('From'),
                        DatePicker::make('issued_until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['issued_from'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['issued_until'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->headerActions([])
            ->bulkActions([])
            ->emptyStateHeading('No tickets issued')
            ->emptyStateDescription('A ticket is created by the betting service when a player confirms a basket of bets.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Ticket')
                ->schema([
                    Infolists\Components\TextEntry::make('ticket_number')->label('Ticket number')->copyable(),
                    Infolists\Components\TextEntry::make('user.username')->label('Player')->placeholder('—'),
                    Infolists\Components\TextEntry::make('draw.draw_number')->label('Draw')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (TicketStatus $state): string => $state->label())
                        ->color(fn (TicketStatus $state): string => $state->color()),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Totals')
                ->description('Cached sums written by the ticket service on confirmation. This page never recomputes them; the live bet count beside them is the cross-check.')
                ->schema([
                    Infolists\Components\TextEntry::make('total_amount')
                        ->label('Total staked')
                        ->formatStateUsing(fn ($state, Ticket $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('total_bets')
                        ->label('Bets (cached)')
                        ->formatStateUsing(fn ($state): string => AdminFormat::count($state)),
                    Infolists\Components\TextEntry::make('live_bets')
                        ->label('Bets (live count)')
                        ->state(fn (Ticket $record): string => AdminFormat::count($record->bets()->count())),
                    Infolists\Components\TextEntry::make('total_numbers')
                        ->label('Selections')
                        ->formatStateUsing(fn ($state): string => AdminFormat::count($state)),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Timeline ('.AdminFormat::timezoneLabel().' time)')
                ->schema([
                    Infolists\Components\TextEntry::make('issued_at')->label('Issued')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('confirmed_at')->label('Confirmed')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('cancelled_at')->label('Cancelled')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('expired_at')->label('Expired')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Bets on this ticket')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('bets')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('bet_number')->label('Bet')->copyable(),
                            Infolists\Components\TextEntry::make('type')
                                ->label('Type')
                                ->badge()
                                ->formatStateUsing(fn (BetType $state): string => strtoupper($state->value)),
                            Infolists\Components\TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn (BetStatus $state): string => $state->label()),
                            Infolists\Components\TextEntry::make('stake_amount')
                                ->label('Stake')
                                ->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                            Infolists\Components\TextEntry::make('actual_payout')
                                ->label('Simulated payout')
                                ->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                        ])
                        ->columns(5)
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
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'view' => Pages\ViewTicket::route('/{record}'),
        ];
    }
}
