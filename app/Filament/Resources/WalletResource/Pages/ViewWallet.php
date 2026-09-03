<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletResource\Pages;

use App\Filament\Resources\WalletResource;
use App\Filament\Resources\WalletResource\WalletControlActions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One wallet, with lock, unlock and adjustment available as header actions.
 *
 * This is the page an operator lands on from a support ticket, which is why the balance
 * section leads with the available figure: "why can I not withdraw?" is almost always
 * answered by reserved funds or a lock, both visible here without further navigation.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No EditAction — a wallet has no editable field. The adjustment action is the only way to
 * change a balance from this panel, and it posts a ledger entry.
 */
class ViewWallet extends ViewRecord
{
    protected static string $resource = WalletResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return WalletControlActions::forPage();
    }
}
