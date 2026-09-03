<?php

declare(strict_types=1);

namespace App\Filament\Resources\NumberLimitResource\Pages;

use App\Filament\Resources\NumberLimitResource;
use App\Support\Admin\AdminAccess;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * The risk desk's working list of number ceilings.
 *
 * WHY THIS EXISTS
 * It is the only screen in the panel with a create button that produces domain
 * configuration, so the button is gated explicitly rather than relying on the resource's
 * canCreate() alone. Two gates saying the same thing is intentional: one is the
 * authorization decision, the other is the visual promise, and a reader of this file can
 * see both without opening the resource.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No tabs. "Exceeded" and "untouched" are already toggle filters, and a tab implies a
 *   queue of work; a full limit is not a task, it is a state the engine reached.
 * - No bulk delete button, matching NumberLimitResource::canDeleteAny() being false: the
 *   deletion guard is per record and a bulk action cannot honestly report a partial run.
 */
class ListNumberLimits extends ListRecords
{
    protected static string $resource = NumberLimitResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New number limit')
                ->visible(fn (): bool => AdminAccess::current(AdminAccess::MANAGE_RISK_LIMITS)),
        ];
    }
}
