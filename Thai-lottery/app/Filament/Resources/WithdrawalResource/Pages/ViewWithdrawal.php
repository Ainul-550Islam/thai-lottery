<?php

declare(strict_types=1);

namespace App\Filament\Resources\WithdrawalResource\Pages;

use App\Filament\Resources\WithdrawalResource;
use App\Filament\Resources\WithdrawalResource\WithdrawalDecisionActions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One withdrawal, with the same five decisions the queue offers.
 *
 * This is the page PendingApprovalsWidget links to, so it is where an operator actually
 * decides most of the time. The actions are the identical declarations used on the list
 * screen, so the two screens can never disagree about which transition is legal.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No EditAction, and payout_details is never rendered: see the resource's class comment.
 */
class ViewWithdrawal extends ViewRecord
{
    protected static string $resource = WithdrawalResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return WithdrawalDecisionActions::forPage();
    }
}
