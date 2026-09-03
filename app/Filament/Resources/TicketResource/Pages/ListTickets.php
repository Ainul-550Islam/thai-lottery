<?php

declare(strict_types=1);

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The ticket browse screen.
 *
 * WHY THIS EXISTS
 * Filament needs a concrete list page. This one is here to hold an empty
 * getHeaderActions(): the absence of a create button is a design decision about who may
 * issue a ticket, and a decision is better recorded in a class than left implicit in a
 * missing line.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No tabs and no export. Status is already a filter, and an export of ticket-level money
 * belongs to the financial reporting surface where it can be logged.
 */
class ListTickets extends ListRecords
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
