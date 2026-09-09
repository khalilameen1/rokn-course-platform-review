<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CourseReportPeriodPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC'));
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public static function periods(): array
    {
        return [['today'], ['7d'], ['30d'], ['90d'], ['all']];
    }

    #[DataProvider('periods')]
    public function test_course_report_renders_every_period_and_exports_the_same_selection(string $key): void
    {
        $course = $this->course();
        $this->actingAs($this->user('admin'), 'web');
        $response = $this->get(route('admin.courses.show', [
            $course, 'tab' => 'commercial-report', 'period' => $key,
        ]))->assertOk()->assertViewIs('admin.courses.show')
            ->assertViewHas('reportPeriod', fn (ReportPeriod $period): bool => $period->key === $key)
            ->assertSee('value="'.$key.'" selected', false)
            ->assertSee('name="tab" value="commercial-report"', false)
            ->assertSee(route('admin.courses.commercial-report.export', [$course, 'period' => $key]), false)
            ->assertSee('الفئات خلال الفترة')->assertSee('بانتظار الاكتمال');
        if ($key === 'all') {
            self::assertSame('unavailable', $response->viewData('commercialReport')['comparisons']['new_students']['status']);
        }
        $csv = $this->get(route('admin.courses.commercial-report.export', [$course, 'period' => $key]))
            ->assertOk()->assertDownload('course-'.$course->id.'-commercial-report.csv')->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('طلبات AI بانتظار تكلفة المزود', $csv);
        self::assertStringNotContainsString('التكلفة شاملة التقديرات', $csv);
    }

    public function test_lazy_report_link_retains_period_without_loading_finances_before_the_tab_is_requested(): void
    {
        $course = $this->course();
        $this->actingAs($this->user('admin'), 'web')->get(route('admin.courses.show', [$course, 'period' => '90d']))
            ->assertOk()->assertViewHas('commercialReport', null)
            ->assertSee(route('admin.courses.show', [$course, 'tab' => 'commercial-report', 'period' => '90d']).'#commercial-report');
    }

    public function test_canonical_and_working_draft_pages_report_canonical_learners_and_keep_canonical_export_target(): void
    {
        $canonical = $this->course('النسخة المنشورة');
        $canonical->update(['is_coming_soon' => false, 'published_at' => now()->subDay()]);
        $draft = $this->course('مسودة المحتوى');
        CourseAuthoringRevision::create([
            'canonical_course_id' => $canonical->id, 'revision_course_id' => $draft->id,
            'base_authoring_version' => 1, 'status' => CourseAuthoringRevision::DRAFT,
            'active_slot' => 'course-draft:'.$canonical->id, 'clone_key' => Str::uuid(),
        ]);
        $enrollment = $this->enroll($canonical);
        $this->actingAs($this->user('admin'), 'web');
        foreach ([$canonical, $draft] as $routeCourse) {
            $response = $this->get(route('admin.courses.show', [
                $routeCourse, 'tab' => 'commercial-report', 'period' => '7d',
            ]))->assertOk()
                ->assertViewHas('course', fn (Course $course): bool => $course->is($draft))
                ->assertViewHas('reportCourse', fn (Course $course): bool => $course->is($canonical))
                ->assertSee(route('admin.courses.commercial-report.export', [$canonical, 'period' => '7d']), false)
                ->assertSee(route('admin.courses.show', $canonical).'#commercial-report', false);
            self::assertSame(1, $response->viewData('commercialReport')['active_students']);
            self::assertSame($enrollment->id, $response->viewData('commercialReport')['student_rows']->first()['enrollment']->id);
            $csv = $this->get(route('admin.courses.commercial-report.export', [$routeCourse, 'period' => '7d']))
                ->assertOk()->assertDownload('course-'.$canonical->id.'-commercial-report.csv')->streamedContent();
            self::assertStringContainsString($enrollment->user->email, $csv);
        }
    }

    public function test_pagination_keeps_period_and_export_includes_all_rows_not_just_the_visible_page(): void
    {
        $course = $this->course();
        $learners = collect(range(1, 26))->map(fn (): CourseEnrollment => $this->enroll($course));
        $this->actingAs($this->user('admin'), 'web');
        $response = $this->get(route('admin.courses.show', [
            $course, 'tab' => 'commercial-report', 'period' => '30d', 'commercial_page' => 2,
        ]))->assertOk();
        $rows = $response->viewData('commercialReport')['student_rows'];
        self::assertSame(26, $rows->total());
        self::assertCount(1, $rows->items());
        self::assertStringContainsString('period=30d', $rows->url(1));
        self::assertStringContainsString('tab=commercial-report', $rows->url(1));
        self::assertStringEndsWith('#commercial-report', $rows->url(1));
        $csv = $this->get(route('admin.courses.commercial-report.export', [$course, 'period' => '30d']))
            ->assertOk()->streamedContent();
        foreach ($learners as $enrollment) self::assertStringContainsString($enrollment->user->email, $csv);
        self::assertCount(27, $this->csvRows($csv));
    }

    public function test_page_and_csv_use_period_costs_and_plan_at_time_of_usage_not_current_enrollment_plan(): void
    {
        $course = $this->course();
        $period = ReportPeriod::fromKey('7d');
        $guided = $this->plan($course, 'guided');
        $mentor = $this->plan($course, 'mentor');
        $enrollment = $this->enroll($course, $period->start->subMonth());
        $enrollment->update([
            'access_plan_id' => $mentor->id,
            'access_plan_snapshot' => app(CourseAccessPlanService::class)->snapshot($mentor->fresh()),
        ]);
        $this->contract($enrollment, $guided, $period->previous()->start->subDay());
        $this->contract($enrollment, $mentor, $period->start->addDay());
        $this->usage($enrollment, $guided, $period->previous()->start, .01);
        $this->usage($enrollment, $guided, $period->start, .02);
        $this->usage($enrollment, $mentor, $period->end, 20);
        $this->actingAs($this->user('admin'), 'web');
        $response = $this->get(route('admin.courses.show', [
            $course, 'tab' => 'commercial-report', 'period' => '7d',
        ]))->assertOk();
        $report = $response->viewData('commercialReport');
        self::assertSame(0, $report['new_students']);
        self::assertSame(1, $report['active_students']);
        self::assertSame(.02, $report['ai_cost_usd']);
        self::assertSame(100.0, $report['comparisons']['ai_cost_usd']['percentage']);
        self::assertSame(1, $report['plan_breakdown']['guided']['period_metrics']['ai_requests']);
        self::assertSame(.02, $report['plan_breakdown']['guided']['period_metrics']['ai_cost_usd']);
        self::assertSame(100.0, $report['plan_breakdown']['guided']['comparisons']['ai_cost_usd']['percentage']);
        self::assertSame(0, $report['plan_breakdown']['mentor']['period_metrics']['ai_requests']);
        $csv = $this->get(route('admin.courses.commercial-report.export', [$course, 'period' => '7d']))
            ->assertOk()->streamedContent();
        [$headings, $row] = $this->csvRows($csv);
        self::assertSame(count($headings), count($row));
        self::assertSame('0.02', $row[array_search('تكلفة AI بالدولار', $headings, true)]);
        self::assertSame('1', $row[array_search('طلبات AI', $headings, true)]);
    }

    public function test_invalid_period_is_rejected_by_page_and_export(): void
    {
        $course = $this->course();
        $this->actingAs($this->user('admin'), 'web');
        foreach (['admin.courses.show', 'admin.courses.commercial-report.export'] as $route) {
            $this->getJson(route($route, [$course, 'period' => 'invalid']))
                ->assertUnprocessable()->assertJsonValidationErrors('period');
        }
    }

    public function test_moderator_cannot_load_or_export_financial_report_even_with_valid_filter(): void
    {
        $course = $this->course();
        $this->actingAs($this->user('moderator'), 'web')->get(route('admin.courses.show', [
            $course, 'tab' => 'commercial-report', 'period' => '7d',
        ]))->assertOk()->assertViewHas('commercialReport', null)->assertDontSee('الفئات خلال الفترة');
        $this->get(route('admin.courses.commercial-report.export', [$course, 'period' => '7d']))->assertForbidden();
    }

    private function user(string $role): User
    {
        return User::forceCreate(['name_ar' => 'طالب التقرير', 'email' => Str::uuid().'@example.test', 'role' => $role, 'active' => true]);
    }

    private function course(string $name = 'كورس التقرير'): Course
    {
        return Course::forceCreate(['tenant_id' => 1, 'name_ar' => $name, 'price' => 500, 'is_coming_soon' => true, 'authoring_version' => 1]);
    }

    private function enroll(Course $course, ?CarbonImmutable $at = null): CourseEnrollment
    {
        return CourseEnrollment::forceCreate([
            'tenant_id' => 1, 'user_id' => $this->user('client')->id, 'course_id' => $course->id,
            'enrolled_at' => $at ?? CarbonImmutable::now('UTC')->subHour(), 'is_active' => true,
        ]);
    }

    private function plan(Course $course, string $code): CourseAccessPlan
    {
        return CourseAccessPlan::create([
            'course_id' => $course->id, 'code' => $code, 'name_ar' => $code,
            'price_coins' => 500, 'sort_order' => $code === 'guided' ? 2 : 3,
        ]);
    }

    private function usage(CourseEnrollment $enrollment, CourseAccessPlan $plan, CarbonImmutable $at, float $cost): void
    {
        AiUsageEvent::create([
            'request_id' => Str::uuid(), 'enrollment_id' => $enrollment->id, 'access_plan_id' => $plan->id,
            'user_id' => $enrollment->user_id, 'course_id' => $enrollment->course_id,
            'feature' => 'course_chat', 'status' => 'completed', 'total_tokens' => 100, 'cost_usd' => $cost,
            'cost_egp' => $cost * 50, 'metadata' => ['cost_usage_source' => 'provider'],
            'created_at' => $at, 'updated_at' => $at, 'completed_at' => $at,
        ]);
    }

    private function contract(CourseEnrollment $enrollment, CourseAccessPlan $plan, CarbonImmutable $at): void
    {
        Order::create([
            'user_id' => $enrollment->user_id, 'course_id' => $enrollment->course_id,
            'access_plan_id' => $plan->id,
            'access_plan_snapshot' => app(CourseAccessPlanService::class)->snapshot($plan->fresh(), $at),
            'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE, 'order_ref' => 'REPORT-'.Str::uuid(),
            'amount' => 500, 'discount_amount' => 500, 'final_amount' => 0, 'total_coins' => 0,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => $at,
        ]);
    }

    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) $rows[] = $row;
        fclose($stream);

        return $rows;
    }
}
