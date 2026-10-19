<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CommercialReport;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentCommercialReportTest extends TestCase
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

    public function test_native_report_validates_period_and_keeps_current_cohort_invoices_unassigned(): void
    {
        $page = Livewire::test(CommercialReport::class)->assertOk()->assertSee('اقتصاديات التشغيل')->assertSee('الخدمات المدفوعة');
        $page->fillForm(['period' => '7d', 'q' => 'طالب'])->call('applyReportFilters')->assertHasNoFormErrors();
        self::assertSame('7d', $page->instance()->report()['period']->key);
        self::assertNull($page->instance()->report()['provider_invoice_report']);
        $page->assertSee('لا تُنسب فواتير المنصة إلى هذا التجمع');
        $page->fillForm(['period' => 'future'])->call('applyReportFilters')->assertHasFormErrors(['period']);
        self::assertSame('7d', $page->instance()->report()['period']->key);
    }

    public function test_provider_unknown_is_not_zero_and_student_details_preserve_actual_zero_metrics(): void
    {
        $student = User::query()->forceCreate(['name_ar' => 'طالب التكلفة', 'email' => 'cost-student@example.test', 'role' => 'client', 'active' => true]);
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس التكلفة', 'is_coming_soon' => true]);
        $enrollment = CourseEnrollment::query()->forceCreate(['tenant_id' => 1, 'user_id' => $student->id, 'course_id' => $course->id, 'is_active' => true, 'enrolled_at' => now()]);
        $usage = AiUsageEvent::query()->create(['request_id' => (string) Str::uuid(), 'enrollment_id' => $enrollment->id, 'user_id' => $student->id,
            'course_id' => $course->id, 'feature' => 'course_chat', 'status' => 'completed', 'total_tokens' => 100, 'cost_usd' => .025,
            'metadata' => ['cost_usage_source' => 'reservation_fallback'], 'completed_at' => now()]);
        $page = Livewire::test(CommercialReport::class)->assertOk()->assertSee('بانتظار التأكيد')->assertDontSee('$0.025000');
        $page->mountAction(TestAction::make('details')->table(1))->assertSee('طالب التكلفة')->assertSee('إشعارات مقروءة')->assertSee('0')->unmountAction();
        $usage->update(['cost_usd' => 0, 'metadata' => ['cost_usage_source' => 'provider']]);
        $confirmed = Livewire::test(CommercialReport::class)->assertSee('$0.000000');
        self::assertTrue($confirmed->instance()->report()['ai_cost_complete']);
        self::assertNull($confirmed->instance()->report()['service_cost_egp']);
        $courseEntry = $confirmed->instance()->getSchema('infolist')->getComponentByStatePath('courses.0.name', withHidden: true);
        self::assertInstanceOf(TextEntry::class, $courseEntry);
        self::assertSame(route('admin.courses.show', ['course' => $course->id, 'tab' => 'commercial-report', 'period' => 'all']).'#commercial-report', $courseEntry->getUrl());
    }

    public function test_content_operator_cannot_open_financial_report(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'moderator', 'active' => true]), 'web');
        $this->get(CommercialReport::getUrl())->assertForbidden();
    }
}
