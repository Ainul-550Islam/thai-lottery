<?php

declare(strict_types=1);

namespace App\Filament\Resources\FinancialTransactionResource\Pages;

use App\Filament\Resources\FinancialTransactionResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One posted transaction and its double entry.
 *
 * The header is empty on purpose. A view page in Filament normally carries an EditAction,
 * and its absence here is the whole point: there is no state in which this record may be
 * changed, so there is no button whose visibility could be got wrong.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No reversal action. Reversing a posting is FinancialReversalService's decision with its
 * own preconditions, and exposing it as a header action on a history screen would make an
 * irreversible finance operation a single click away from a read-only workflow.
 */
class ViewFinancialTransaction extends ViewRecord
{
    protected static string $resource = FinancialTransactionResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
