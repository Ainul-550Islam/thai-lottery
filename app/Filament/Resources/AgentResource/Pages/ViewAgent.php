<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One agent's record.
 *
 * There is no Edit action in the header, and that is not an oversight: AgentResource
 * declares canEdit() false because every writable column on the row is either money or
 * the rate that prices it, and no service owns either. Rendering an edit button that the
 * resource would then refuse is worse than rendering none.
 */
class ViewAgent extends ViewRecord
{
    protected static string $resource = AgentResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
