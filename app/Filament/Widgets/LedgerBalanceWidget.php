<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\LedgerEntry;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Does the ledger still balance?
 *
 * A double-entry ledger has exactly one invariant that matters: total debits equal total
 * credits. The domain enforces it on every posting, so this widget should always read
 * "balanced" - which is precisely why it is worth showing. The day it does not, an
 * operator needs to know before the next reconciliation, not after.
 *
 * The comparison is done with bcmath on the raw strings. Summing money into a float here
 * would let a rounding artefact masquerade as an imbalance, or hide a real one.
 */
class LedgerBalanceWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::RECONCILE_LEDGER,
            AdminAccess::VIEW_FINANCIAL_REPORTS,
        ]);
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $sums = LedgerEntry::query()
            ->selectRaw("type, COUNT(*) as entry_count, COALESCE(SUM(amount), 0) as total")
            ->groupBy('type')
            ->get()
            ->keyBy(fn ($row): string => (string) (is_object($row->type) ? $row->type->value : $row->type));

        $debit = (string) ($sums['debit']->total ?? '0');
        $credit = (string) ($sums['credit']->total ?? '0');
        $entries = (int) ($sums['debit']->entry_count ?? 0) + (int) ($sums['credit']->entry_count ?? 0);

        $difference = bcsub(self::normalise($debit), self::normalise($credit), 2);
        $balanced = bccomp($difference, '0.00', 2) === 0;

        return [
            Stat::make('Total debits', AdminFormat::money($debit, AdminFormat::currency()))
                ->description(AdminFormat::count($sums['debit']->entry_count ?? 0).' entries')
                ->icon('heroicon-o-arrow-up-right'),

            Stat::make('Total credits', AdminFormat::money($credit, AdminFormat::currency()))
                ->description(AdminFormat::count($sums['credit']->entry_count ?? 0).' entries')
                ->icon('heroicon-o-arrow-down-right'),

            Stat::make($balanced ? 'Ledger balanced' : 'LEDGER OUT OF BALANCE', AdminFormat::money($difference))
                ->description($entries === 0
                    ? 'No entries posted yet'
                    : ($balanced ? 'Debits equal credits across '.AdminFormat::count($entries).' entries' : 'Investigate before the next reconciliation'))
                ->icon($balanced ? 'heroicon-o-scale' : 'heroicon-o-exclamation-triangle')
                ->color($balanced ? 'success' : 'danger'),
        ];
    }

    private static function normalise(string $amount): string
    {
        $amount = trim($amount);

        return $amount === '' ? '0.00' : bcadd($amount, '0', 2);
    }
}
