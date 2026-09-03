<?php

declare(strict_types=1);

namespace App\Filament\Resources\DrawResource\Pages;

use App\Enums\DrawStatus;
use App\Filament\Resources\DrawResource;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ListDraws extends ListRecords
{
    protected static string $resource = DrawResource::class;

    /**
     * @return array<int, Actions\Action|Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New draw')
                ->visible(fn (): bool => AdminAccess::current(AdminAccess::MANAGE_DRAWS)),

            Actions\Action::make('provision')
                ->label('Provision from calendar')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Provision draws from the official calendar')
                ->modalDescription('Creates any missing draw the configured calendar declares, out to the horizon. Existing draws are never touched — the draw number is derived from the draw moment, so running this twice creates nothing the second time.')
                ->visible(fn (): bool => AdminAccess::current(AdminAccess::MANAGE_DRAWS))
                ->action(function (): void {
                    try {
                        $summary = app(\App\Services\Draw\DrawScheduleService::class)->provision();

                        Notification::make()
                            ->success()
                            ->title('Calendar provisioned')
                            ->body(sprintf(
                                '%s new draw(s) created, %s already existed.',
                                AdminFormat::count($summary['created'] ?? 0),
                                AdminFormat::count($summary['existing'] ?? 0),
                            ))
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Provisioning failed')
                            ->body($exception->getMessage())
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * Tabs, because "which draws need me?" is the question this list is opened with.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'needs_attention' => Tab::make('Awaiting numbers')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DrawStatus::Drawing))
                ->badge(fn (): int => \App\Models\Draw::query()->where('status', DrawStatus::Drawing)->count())
                ->badgeColor('danger'),

            'live' => Tab::make('Open')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DrawStatus::Open))
                ->badge(fn (): int => \App\Models\Draw::query()->where('status', DrawStatus::Open)->count()),

            'upcoming' => Tab::make('Upcoming')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', DrawStatus::Scheduled)
                    ->where('scheduled_at', '>=', now())),

            'settled' => Tab::make('Settled')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DrawStatus::Completed)),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return \App\Models\Draw::query()->where('status', DrawStatus::Drawing)->exists()
            ? 'needs_attention'
            : 'live';
    }
}
