<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ProductAnalytics;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentProductAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_analytics_renders_existing_sections_and_changes_period_without_writes(): void
    {
        $page = Livewire::test(ProductAnalytics::class)->assertOk()->assertSee('مسار شراء الكورس المرصود')
            ->assertSee('لا توجد بدايات متابعة مرصودة في هذه الفترة');
        $page->fillForm(['period' => 'all'])->call('applyReportFilters')->assertHasNoFormErrors();
        self::assertSame('all', $page->instance()->report()['period']->key);
        self::assertSame('unavailable', $page->instance()->report()['analytics']['changes']['events']['status']);
        $page->fillForm(['period' => '365d'])->call('applyReportFilters')->assertHasFormErrors(['period']);
        self::assertSame('all', $page->instance()->report()['period']->key);
    }

    public function test_selected_course_never_displays_platform_topups_as_course_revenue(): void
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس محدد', 'is_coming_soon' => true]);
        $page = Livewire::test(ProductAnalytics::class)->fillForm(['period' => '7d', 'course_id' => $course->id])
            ->call('applyReportFilters')->assertHasNoFormErrors()->assertSee('تحصيل باقات العملات على مستوى المنصة لا يُنسب إلى كورس بعينه');
        self::assertNull($page->instance()->report()['paymentChannelReport']);
        self::assertNull($page->instance()->report()['invoiceReport']);
    }

    public function test_content_operator_cannot_open_product_analytics(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'moderator', 'active' => true]), 'web');
        $this->get(ProductAnalytics::getUrl())->assertForbidden();
    }
}
