<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The trail, newest first.
 *
 * There are no header actions and there never will be: create, export and purge are the
 * three things a header on this page could offer and all three are refused by
 * AuditLogResource for reasons written out there. The screen is a window, and a window
 * with no handle is the point.
 */
class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
