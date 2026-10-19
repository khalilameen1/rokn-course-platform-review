<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Navigation\LegacyWorkspaces;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\ApplyLocale;
use App\Http\Middleware\AuditAdminMutation;
use App\Http\Middleware\RequireAdminMfa;
use App\Http\Middleware\VerifyDashboardSession;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;

final class OperationsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('operations')
            ->path('operations')
            ->authGuard('web')
            ->plugin(FilamentShieldPlugin::make()->navigationGroup('الإدارة')->navigationLabel('الأدوار'))
            ->login(fn () => redirect()->route('login'))
            ->brandName('رُكن')
            ->colors(['primary' => Color::Blue])
            ->font('Cairo', url: asset('css/operations-font.css'), provider: LocalFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->navigationItems(LegacyWorkspaces::items())
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware(['web', ApplyLocale::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
            ->authMiddleware([
                VerifyDashboardSession::class,
                Authenticate::class,
                RequireAdminMfa::class,
                AuditAdminMutation::class,
            ], isPersistent: true);
    }
}
