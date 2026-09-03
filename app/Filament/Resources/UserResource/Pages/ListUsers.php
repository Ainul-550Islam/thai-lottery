<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The account list.
 *
 * WHY THERE ARE NO HEADER ACTIONS AND NO TABS
 * There is no "New user" button because registration owns account creation (it also
 * provisions the wallet and starts email verification, neither of which a form here could
 * do honestly). There are no status tabs either: unlike draws, where "which draw needs me
 * tonight?" is the question the screen is opened with, an operator arrives here already
 * knowing whose account they want, so search and the status filter serve better than a
 * tab bar that would have to be paged through anyway.
 */
class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
