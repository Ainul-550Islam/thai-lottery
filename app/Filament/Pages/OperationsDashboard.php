<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\DrawPipelineWidget;
use App\Filament\Widgets\LedgerBalanceWidget;
use App\Filament\Widgets\PendingApprovalsWidget;
use App\Filament\Widgets\PlatformStatsWidget;
use App\Support\Admin\AdminAccess;
use Filament\Pages\Dashboard;

/**
 * The landing screen: what an operator needs to know before touching anything.
 *
 * It answers three questions in order of urgency - is there work waiting for a human
 * (approvals), is the draw pipeline healthy, and does the ledger still balance - because
 * those are the three things this system can be wrong about in a way that matters.
 */
class OperationsDashboard extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $title = 'Operations';

    protected static ?string $navigationLabel = 'Operations';

    protected static ?int $navigationSort = -2;

    public static function canAccess(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DASHBOARD);
    }

    /**
     * @return list<class-string<\Filament\Widgets\Widget>>
     */
    public function getWidgets(): array
    {
        return [
            PlatformStatsWidget::class,
            PendingApprovalsWidget::class,
            DrawPipelineWidget::class,
            LedgerBalanceWidget::class,
        ];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return 2;
    }
}
