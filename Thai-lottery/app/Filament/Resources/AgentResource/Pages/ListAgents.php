<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The agent register.
 *
 * No header actions: there is no agent onboarding service to call, so a "New agent"
 * button could only insert a row with an invented agent_code and a commission rate nobody
 * agreed to. An empty header is the honest rendering of that gap.
 */
class ListAgents extends ListRecords
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
