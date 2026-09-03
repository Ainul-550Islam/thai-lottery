<?php

declare(strict_types=1);

namespace App\Filament\Resources\DrawResource\Pages;

use App\Filament\Resources\DrawResource;
use App\Filament\Resources\DrawResource\DrawLifecycleActions;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDraw extends ViewRecord
{
    protected static string $resource = DrawResource::class;

    /**
     * The same lifecycle actions as the list screen, so an operator who drilled into a
     * draw does not have to go back to act on it. They are the identical action objects:
     * one definition, two places, no chance of the two disagreeing about when a button is
     * allowed.
     *
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return array_merge(
            [Actions\EditAction::make()],
            DrawLifecycleActions::forPage(),
        );
    }
}
