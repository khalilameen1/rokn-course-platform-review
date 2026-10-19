<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\FinancialOverview;
use App\Filament\Widgets\OverviewRevenueChart;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use App\Services\AdminHomeReadService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentFinancialOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_overview_keeps_financial_sections_and_validates_period(): void
    {
        $page = Livewire::test(FinancialOverview::class)->assertOk()->assertSee('تحصيل الفترة حسب الشهر')
            ->assertSee('تحصيل باقات العملات حسب القناة')->assertSee('ملخص فتح الكورسات')
            ->assertSee('لم تسجل فواتير لهذه الفترة بعد وهذا لا يعني أن التشغيل بلا تكلفة');
        $page->fillForm(['period' => '90d'])->call('applyReportFilters')->assertHasNoFormErrors();
        self::assertSame('90d', $page->instance()->report()['period']->key);
        $page->fillForm(['period' => 'future'])->call('applyReportFilters')->assertHasFormErrors(['period']);
        self::assertSame('90d', $page->instance()->report()['period']->key);
    }

    public function test_moderator_cannot_resolve_financial_read_owner_or_direct_chart(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'moderator', 'active' => true]), 'web');
        $this->app->bind(AdminHomeReadService::class, static function (): never {
            throw new \LogicException('A content operator must not resolve the financial home report');
        });
        $this->get(FinancialOverview::getUrl())->assertForbidden();
        Livewire::test(OverviewRevenueChart::class, ['months' => []])->assertForbidden();
    }
}
