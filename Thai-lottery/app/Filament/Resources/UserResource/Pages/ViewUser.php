<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\UserAccountActions;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One account, and every decision available on it.
 *
 * The header carries the identical action objects the list screen renders as row actions:
 * one declaration in UserAccountActions, two screens, no chance of the two disagreeing
 * about whether a suspended account may be suspended again.
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return array_merge(
            [Actions\EditAction::make()->label('Edit profile')],
            UserAccountActions::forPage(),
        );
    }
}
