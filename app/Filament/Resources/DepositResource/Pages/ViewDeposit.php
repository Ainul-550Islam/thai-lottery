<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepositResource\Pages;

use App\Filament\Resources\DepositResource;
use App\Filament\Resources\DepositResource\DepositDecisionActions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One deposit, with the same decisions the list screen offers.
 *
 * They are the identical declarations rendered as header actions, so an operator who
 * drilled in to read the provider reference before deciding does not have to navigate back,
 * and the two screens can never disagree about when a button is legal.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No EditAction: a deposit's amounts and provider data are not the panel's to change.
 */
class ViewDeposit extends ViewRecord
{
    protected static string $resource = DepositResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return DepositDecisionActions::forPage();
    }
}
