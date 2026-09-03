<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One audit entry, expanded.
 *
 * No Edit action and no Delete action in the header. Filament would happily render both
 * from the resource's defaults, so their absence is stated here as well as enforced by
 * canEdit()/canDelete() on the resource: two independent places would both have to be
 * changed before an operator could alter a recorded event, which is the level of accident
 * resistance an audit trail is supposed to have.
 */
class ViewAuditLog extends ViewRecord
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
