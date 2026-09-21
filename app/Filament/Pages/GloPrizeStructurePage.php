<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Lottery\GloPrizeCatalogue;
use App\Support\Admin\AdminAccess;
use Filament\Pages\Page;

/**
 * Filament Admin Panel Page — GLO Official Prize Structure.
 *
 * Shows the Government Lottery Office's official prize ladder, claim window, tax and
 * physical ticket rules as a read-only reference, sourced from config('glo') through
 * GloPrizeCatalogue. An operator records or audits official numbers with this page
 * open beside the draw resource, without any second implementation of the rules.
 */
class GloPrizeStructurePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'GLO Prize Structure';

    protected static ?string $title = 'GLO Official Prize Structure';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.glo-prize-structure-page';

    public static function canAccess(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DRAWS);
    }

    /**
     * The full catalogue for the blade template.
     *
     * @return array<string, mixed>
     */
    public function getCatalogue(): array
    {
        return app(GloPrizeCatalogue::class)->all();
    }
}
