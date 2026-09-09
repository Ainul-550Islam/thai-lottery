<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource\Pages;
use App\Models\Ticket;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Admin Resource for Managing Issued Lottery Tickets.
 */
class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Wagering Ledger';

    protected static ?int $navigationSort = 2;

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
                Tables\Columns\TextColumn::make('ticket_number')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('Player')
                    ->searchable(),
                Tables\Columns\TextColumn::make('draw.draw_number')
                    ->label('Draw')
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => TicketStatus::Won->value,
                        'secondary' => TicketStatus::Lost->value,
                        'warning' => TicketStatus::Pending->value,
                        'danger' => TicketStatus::Cancelled->value,
                    ]),
                Tables\Columns\TextColumn::make('issued_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(TicketStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'view' => Pages\ViewTicket::route('/{record}'),
        ];
    }
}
