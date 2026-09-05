<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use App\Services\AdminPaymentOperationsReadService;
use App\Services\CourseFinancialLedgerReportService;
use App\Services\PaymentChannelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ModeratorDashboardFinancialIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_dashboard_does_not_resolve_or_expose_financial_reports(): void
    {
        $moderator = new User();
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
        $response = $this->actingAs($moderator, 'web')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.home.moderator');

        foreach ([
            'designSettings',
            'revenueStats',
            'monthlyRevenue',
            'paymentChannelReport',
            'courseStats',
            'platformStats',
        ] as $financialViewKey) {
            $response->assertViewMissing($financialViewKey);
        }

        self::assertSame([], $resolved);
    }
}
