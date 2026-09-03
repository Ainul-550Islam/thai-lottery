<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The next draws and where each one is in its lifecycle.
 *
 * The "Waiting on" column is the point of this widget: it names who owes the next move -
 * the scheduler, or a human with the official numbers - because the single most confusing
 * state in this system is a draw sitting in result-pending that nobody realises is waiting
 * for an operator.
 */
class DrawPipelineWidget extends TableWidget
{
    protected static ?string $heading = 'Draw pipeline';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DRAWS);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Draw::query()
                    ->whereNotIn('status', [DrawStatus::Completed, DrawStatus::Cancelled])
                    ->orderBy('scheduled_at')
            )
            ->emptyStateHeading('No draw in the pipeline')
            ->emptyStateDescription('Run lottery:schedule-draws, or wait for the next tick.')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('draw_number')
                    ->label('Draw')
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (DrawStatus $state): string => match ($state) {
                        DrawStatus::Open => 'success',
                        DrawStatus::Drawing => 'warning',
                        DrawStatus::Closed => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('Scheduled')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_bets')
                    ->label('Bets')
                    ->formatStateUsing(fn ($state): string => AdminFormat::count($state))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_amount_wagered')
                    ->label('Stake')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('id')
                    ->label('Waiting on')
                    ->badge()
                    ->color(fn (Draw $record): string => $record->status === DrawStatus::Drawing ? 'danger' : 'gray')
                    ->formatStateUsing(fn (Draw $record): string => match ($record->status) {
                        DrawStatus::Scheduled => 'scheduler: open',
                        DrawStatus::Open => 'scheduler: close',
                        DrawStatus::Closed => 'scheduler: mark pending',
                        DrawStatus::Drawing => 'operator: official numbers',
                        DrawStatus::ResultPublished => 'scheduler: settle',
                        default => '—',
                    }),
            ])
            ->recordUrl(fn (Draw $record): string => \App\Filament\Resources\DrawResource::getUrl('view', ['record' => $record]));
    }
}
