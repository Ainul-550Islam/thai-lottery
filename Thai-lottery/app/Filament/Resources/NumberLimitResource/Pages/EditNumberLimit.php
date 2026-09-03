<?php

declare(strict_types=1);

namespace App\Filament\Resources\NumberLimitResource\Pages;

use App\Filament\Resources\NumberLimitResource;
use App\Models\NumberLimit;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing a ceiling that may already have exposure against it.
 *
 * WHY THE SAVE IS NARROWED
 * Filament fills the model from the submitted form, and the form only contains the five
 * definition fields. That is already safe because current_amount, current_payout_exposure,
 * status and exceeded_at are absent from NumberLimit::$fillable. The explicit intersect in
 * handleRecordUpdate is a second lock on the same door: it means a crafted Livewire
 * payload naming a consumption column cannot reach the model at all, rather than relying
 * on the mass-assignment guard to notice.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - Lowering a ceiling below what is already reserved is NOT blocked here. It is a
 *   legitimate risk decision ("stop selling this number, keep what we sold"), and the
 *   engine handles it correctly: reserve() compares the projected total against the new
 *   ceiling and refuses, so the number simply stops accepting stake. Refusing the edit
 *   would take that lever away from the risk desk.
 * - No status change. Suspending a number is a state transition and belongs to a service.
 */
class EditNumberLimit extends EditRecord
{
    protected static string $resource = NumberLimitResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (NumberLimit $record): bool => NumberLimitResource::canDelete($record))
                ->modalDescription('Nothing has been reserved against this limit, so deleting it erases no liability.'),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate($record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $definitionFields = ['draw_id', 'bet_type', 'number', 'max_amount', 'maximum_payout_exposure', 'metadata'];

        $record->fill(array_intersect_key($data, array_flip($definitionFields)));
        $record->save();

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
