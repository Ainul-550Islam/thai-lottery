<?php

declare(strict_types=1);

namespace App\Filament\Resources\FinancialTransactionResource\Pages;

use App\Filament\Resources\FinancialTransactionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Ledger history, filtered.
 *
 * WHY THERE ARE NO TABS HERE
 * Deposits and withdrawals get tabs because their lists are work queues with a natural
 * "waiting on me" subset. This list is not a queue: nothing on it is actionable, and every
 * reason to open it ("what happened to this player's money on the 3rd?") is a filter, not a
 * status bucket. Tabs would just add a dimension the operator has to reset before the filter
 * they actually want takes effect.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No header actions of any kind — no create, no export, no bulk anything. This page has no
 * write path, which is what makes it usable as evidence.
 */
class ListFinancialTransactions extends ListRecords
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
