<?php

declare(strict_types=1);

namespace App\Filament\Resources\DrawResource\Pages;

use App\Filament\Resources\DrawResource;
use App\Services\Draw\DrawScheduleService;
use Filament\Resources\Pages\CreateRecord;

class CreateDraw extends CreateRecord
{
    protected static string $resource = DrawResource::class;

    /**
     * A hand-made draw still gets a betting window.
     *
     * betting_open_at / betting_close_at are not fillable (the schema treats them as
     * planning data owned by the scheduler), so they are set here from the SAME service
     * the scheduler uses. Without this, a manually created draw would have no cut-off and
     * BetValidationService would reject every bet against it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data;
    }

    protected function afterCreate(): void
    {
        $schedule = app(DrawScheduleService::class);
        $draw = $this->record;

        $scheduledAt = \Carbon\CarbonImmutable::parse($draw->scheduled_at);

        $draw->betting_open_at = $schedule->bettingOpenAtFor($scheduledAt);
        $draw->betting_close_at = $schedule->bettingCloseAtFor($scheduledAt);
        $draw->save();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
