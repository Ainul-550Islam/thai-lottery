<?php

declare(strict_types=1);

namespace App\Filament\Resources\LedgerAccountResource\Pages;

use App\Filament\Resources\LedgerAccountResource;
use App\Support\Admin\AdminFormat;
use Filament\Resources\Pages\ListRecords;

/**
 * The chart of accounts as a single read-only screen.
 *
 * The only behaviour here is dropping the resource's memoised entry aggregate on each
 * render. The memo exists so a page of accounts costs one grouped query instead of one per
 * row; clearing it in mount() means a Livewire re-render after a posting elsewhere shows the
 * new balance rather than a cached one from the previous request. That is the whole trade:
 * one query per render, never one per row, and never a figure older than this render.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * No header actions and no row actions. There is no create, edit, delete or export path: an
 * account code is referenced by every posting that used it, so the panel treats the chart as
 * seeded infrastructure it may read and nothing more.
 */
class ListLedgerAccounts extends ListRecords
{
    protected static string $resource = LedgerAccountResource::class;

    public function mount(): void
    {
        LedgerAccountResource::forgetEntrySums();

        parent::mount();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        LedgerAccountResource::forgetEntrySums();

        return parent::render();
    }

    public function getSubheading(): ?string
    {
        return sprintf(
            'Balances are computed from %s posted ledger entries with bcmath, not read from the cached current_balance column.',
            AdminFormat::count(LedgerAccountResource::entryCount()),
        );
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
