<?php

declare(strict_types=1);

namespace App\Filament\Resources\BetResource\Pages;

use App\Filament\Resources\BetResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The bet browse screen.
 *
 * WHY THIS EXISTS
 * Filament requires a concrete list page per resource. This one exists to declare, in
 * code rather than in a comment, that the bet list has no header actions: no create, no
 * import, no bulk anything. An empty getHeaderActions() is the point of the class.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No tabs. Tabs on this screen would encourage an operator to treat "pending" bets as a
 *   work queue, but nothing here can action one; the rich filter set is the honest way to
 *   narrow a read-only list.
 * - No default query scoping to recent bets. An investigator searching a bet number from
 *   six months ago must find it without discovering a hidden date window first.
 */
class ListBets extends ListRecords
{
    protected static string $resource = BetResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
