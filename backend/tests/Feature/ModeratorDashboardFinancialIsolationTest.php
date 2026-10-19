<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ContentWorkspace;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use App\Services\AdminPaymentOperationsReadService;
use App\Services\CourseFinancialLedgerReportService;
use App\Services\PaymentChannelReportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ModeratorDashboardFinancialIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_dashboard_does_not_resolve_or_expose_financial_reports(): void
    {
        $moderator = new User;
        $moderator->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'moderator-financial-isolation@example.test',
            'role' => 'moderator',
            'active' => true,
        ])->save();

        $resolved = [];
        foreach ([
            PaymentChannelReportService::class,
            AdminPaymentOperationsReadService::class,
            CourseFinancialLedgerReportService::class,
        ] as $financialService) {
            $this->app->resolving($financialService, static function () use (&$resolved, $financialService): void {
                $resolved[] = $financialService;
            });
        }

        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($moderator, 'web')
            ->get(route('admin.dashboard'))
            ->assertRedirect(ContentWorkspace::getUrl(['page' => 1]));
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $page = Livewire::test(ContentWorkspace::class)->assertOk();
        Livewire::test(Dashboard::class)->assertOk()->assertActionHasUrl('content', ContentWorkspace::getUrl());

        foreach ([
            'designSettings',
            'revenueStats',
            'monthlyRevenue',
            'paymentChannelReport',
            'courseStats',
            'platformStats',
        ] as $financialViewKey) {
            self::assertArrayNotHasKey($financialViewKey, $page->instance()->report());
        }

        self::assertSame([], $resolved);
    }
}
