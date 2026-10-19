<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\EditStaffProfile;
use App\Filament\Navigation\LegacyWorkspaces;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\ApplyLocale;
use App\Http\Middleware\AuditAdminMutation;
use App\Http\Middleware\RequireAdminMfa;
use App\Http\Middleware\VerifyDashboardSession;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\FontProviders\LocalFontProvider;
use Filament\Forms\Components\FileUpload;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Livewire\Component;

final class OperationsPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // FilePond's async initial reads must start after draft restoration.
        // Otherwise their older Livewire snapshots can replace restored values.
        FileUpload::configureUsing(static function (FileUpload $field): void {
            $field->visibleJs(static fn (Component $livewire): ?string => method_exists($livewire, 'authoringDraftConfig')
                && $livewire->authoringDraftConfig()['fields'] !== [] ? '$wire.authoringDraftReady' : null);
        });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('operations')
            ->path('operations')
            ->authGuard('web')
            ->profile(EditStaffProfile::class, isSimple: false)
            ->plugin(FilamentShieldPlugin::make()->navigationGroup('الإدارة')->navigationLabel('الأدوار')->navigationSort(40))
            ->login(fn () => redirect()->route('login'))
            ->brandName('رُكن')
            // Filament uses shade 500 for light-mode filled-button hover.
            // Blue 500 with white 14px text is only 3.76:1; retain the normal
            // approved blue (600) for that state rather than weakening contrast.
            ->colors(['primary' => array_replace(Color::Blue, [500 => Color::Blue[600]])])
            ->font('Cairo', url: asset('css/operations-font.css'), provider: LocalFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->navigationItems(LegacyWorkspaces::items())
            ->navigationGroups(['المحتوى', 'الطلبة', 'المراجعة', 'التجارة', 'التطبيق', 'التشغيل', 'الإدارة'])
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
