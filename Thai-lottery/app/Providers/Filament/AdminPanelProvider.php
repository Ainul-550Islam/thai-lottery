<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\OperationsDashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The operator panel (Phase 6).
 *
 * WHY THIS EXISTS
 * Phases 1-5.1 built a complete domain - wallets, a double-entry ledger, risk limits, a
 * betting engine, a draw lifecycle - that no human could reach. Approving a deposit,
 * publishing a result, setting a number limit or reading the audit trail all required a
 * developer with a tinker session. This panel is that missing surface.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No screen writes a model that a domain service owns. Every state change (open a
 *   draw, approve a withdrawal, credit a wallet) is delegated to the existing, tested
 *   service. The panel is an interface, not a second implementation of the rules.
 * - No screen invents a permission. Authorization reads the seeded catalogue through
 *   App\Support\Admin\AdminAccess.
 * - No player-facing route lives here. Phase 7 (the public frontend) is still unbuilt;
 *   this panel is reachable at /admin only.
 * - Database notifications, multi-tenancy and the agent portal are not enabled.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Thai Lottery — Operations')
            ->colors([
                'primary' => Color::Amber,
                'danger' => Color::Rose,
                'success' => Color::Emerald,
                'warning' => Color::Orange,
                'info' => Color::Sky,
            ])
            ->maxContentWidth(MaxWidth::Full)
            ->navigationGroups([
                NavigationGroup::make('Lottery'),
                NavigationGroup::make('Finance'),
                NavigationGroup::make('Risk'),
                NavigationGroup::make('People'),
                NavigationGroup::make('Compliance'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                OperationsDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
