<?php

declare(strict_types=1);

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One ticket and the bets it groups.
 *
 * WHY THIS EXISTS
 * A support question is almost never "show me bet 4471"; it is "show me what this player
 * bought on this draw". The ticket is that unit, so this page lists the bets inline
 * rather than making an operator pivot through the bet list with a filter.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No header actions at all. In particular no cancel and no expire: TicketStatus knows
 * those states are reachable, but no domain service performs the transition, and the
 * panel writing tickets.status directly is exactly the failure mode the project
 * forbids. The button arrives with the service, not before it.
 */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
