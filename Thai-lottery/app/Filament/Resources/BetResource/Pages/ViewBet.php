<?php

declare(strict_types=1);

namespace App\Filament\Resources\BetResource\Pages;

use App\Filament\Resources\BetResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One bet, in full, with its selections.
 *
 * WHY THIS EXISTS
 * The list answers "which bets"; this page answers "what exactly did this player buy, and
 * what would it pay". It is the screen a dispute is settled from, so it shows the stored
 * multiplier and the stake alongside the simulated prize rather than only the prize:
 * an operator can then see *why* the number is what it is.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No Edit header action, and no lifecycle actions. Unlike ViewDraw, there is no bet
 *   service to delegate to: cancellation and refund are not implemented in the domain
 *   yet, and inventing them in the panel would write ledger-visible state from a screen.
 *   When BetCancellationService exists, its button belongs here — delegating, wrapped in
 *   the DrawLifecycleActions::run() try/catch pattern, and never touching the model.
 * - No delete action, hard or soft. The model uses SoftDeletes for the domain's own
 *   convenience; that is not an invitation for an operator to hide financial history.
 */
class ViewBet extends ViewRecord
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
