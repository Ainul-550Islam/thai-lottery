<?php

declare(strict_types=1);

namespace App\Filament\Resources\DrawResource\Pages;

use App\Filament\Resources\DrawResource;
use App\Services\Draw\DrawLifecycleService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDraw extends EditRecord
{
    protected static string $resource = DrawResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }

    /**
     * The lifecycle service decides whether this draw may change at all, and which fields
     * may change. Saving goes through applyModification() rather than Eloquent so that a
     * protected field cannot be smuggled in through a crafted form request.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate($record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $allowed = array_intersect_key(
            $data,
            array_flip(DrawLifecycleService::MODIFIABLE_FIELDS),
        );

        return app(DrawLifecycleService::class)->applyModification(
            $record,
            $allowed,
            'admin panel edit',
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
