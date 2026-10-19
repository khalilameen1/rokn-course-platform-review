<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CommercialReport;
use App\Filament\Pages\FinancialOverview;
use App\Filament\Pages\ProductAnalytics;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\OperatingCostPool;
use App\Models\Order;
use App\Models\Package;
use App\Models\ProductEvent;
use App\Models\User;
use App\Services\AiUsageReportService;
use App\Services\ProductAnalyticsService;
use App\Services\ProviderInvoiceReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PlatformReportPeriodTest extends TestCase
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
    public function test_actual_dashboard_and_analytics_render_and_retain_every_period(string $key): void
    {
        $this->actingAs($this->user('admin'), 'web');
        $home = $this->financial(['period' => $key])
            ->assertOk()->assertSet('appliedPeriod', $key)
            ->assertSee('لم تسجل فواتير لهذه الفترة بعد وهذا لا يعني أن التشغيل بلا تكلفة');
        $analytics = $this->analytics(['period' => $key])
            ->assertOk()->assertSet('appliedFilters.period', $key)
            ->assertSee('مسار شراء الكورس المرصود')->assertSee('نشاط الاستخدام')
            ->assertSee('إكمال الدروس المرصود')->assertSee('لا توجد بدايات متابعة مرصودة في هذه الفترة')
            ->assertDontSee('مسار الاستخدام');

        if ($key === 'all') {
            self::assertNull($home->instance()->report()['revenueStats']['revenue_change']['percentage']);
            self::assertSame('unavailable', $analytics->instance()->report()['analytics']['changes']['events']['status']);
        }
    }

    public function test_analytics_uses_adjacent_half_open_windows_for_course_events_and_acquisition(): void
    {
        $course = $this->course();
        $other = $this->course();
        $period = ReportPeriod::fromKey('7d');
        $this->event($course, $period->previous()->start, 'existing');
        $this->event($course, $period->start, 'existing');
        $this->event($course, $period->end->subSecond(), 'new');
        $this->event($course, $period->end, 'at-end');
        $this->event($other, $period->start, 'other-course');

        $overview = app(ProductAnalyticsService::class)->overview($course->id, $period);
        self::assertSame(2, $overview['quality']['events']);
        self::assertSame(2, $overview['quality']['actors']);
        self::assertSame(100.0, $overview['changes']['events']['percentage']);
        self::assertSame(1.0, $overview['changes']['events']['previous']);
        $opened = collect($overview['activity'])->firstWhere('event', 'course_opened');
        self::assertSame(2, $opened['total']);
        self::assertSame(100.0, $opened['change']['percentage']);
        self::assertSame(1, $overview['cohorts']->sum('actors'), 'Returning actors are not new acquisitions');
        self::assertSame(2, $overview['attribution']->sum('total'));
        self::assertSame(3, app(ProductAnalyticsService::class)->overview(null, $period)['quality']['events']);
    }

    public function test_dashboard_renders_verified_lesson_names_and_counts_for_the_selected_canonical_course(): void
    {
        $canonical = $this->course();
        $canonical->update(['name_ar' => 'كورس <em>التقرير</em>']);
        $archive = $this->course();
        $other = $this->course();
        CourseAuthoringRevision::create([
            'canonical_course_id' => $canonical->id, 'revision_course_id' => $archive->id,
            'base_authoring_version' => 1, 'status' => CourseAuthoringRevision::ARCHIVED,
            'clone_key' => Str::uuid(),
        ]);
        $lesson = Lesson::forceCreate(['list_id' => $archive->id,
            'title' => 'Previous lesson', 'title_ar' => 'درس <script>قديم</script>',
            'video_link' => 'https://example.test/video.mp4']);
        $at = CarbonImmutable::now('UTC')->subHour();
        foreach ([
            ['lesson_started', 'a', $canonical, $at],
            ['lesson_completed', 'a', $canonical, $at->addMinute()],
            ['lesson_started', 'b', $canonical, $at],
            ['lesson_started', 'other', $other, $at],
        ] as [$name, $actor, $course, $occurredAt]) {
            ProductEvent::create([
                'event_id' => Str::uuid(), 'actor_key' => hash('sha256', $actor),
                'event_name' => $name, 'course_id' => $course->id, 'lesson_id' => $lesson->id,
                'source' => 'server', 'occurred_at' => $occurredAt, 'received_at' => now(),
            ]);
        }
        $this->actingAs($this->user('admin'), 'web');
        $response = $this->analytics([
            'course_id' => $archive->id, 'period' => '7d',
        ])->assertOk()->assertSee('كورس <em>التقرير</em>')->assertSee('درس <script>قديم</script>')
            ->assertDontSee('<script>قديم</script>', false)->assertSee('50.0%')
            ->assertSee('نسخة سابقة أو درس لم يعد متاحًا')
            ->assertSee('ولا يعني أنهم تركوا الكورس نهائيًا')
            ->assertDontSee('لا توجد بدايات متابعة مرصودة في هذه الفترة');
        self::assertSame($canonical->id, $response->instance()->report()['filters']['course_id']);
        $row = $response->instance()->report()['analytics']['lesson_completion']['rows']->sole();
        self::assertSame($lesson->id, $row['lesson_id']);
        self::assertSame(2, $row['starts']);
        self::assertSame(1, $row['completions']);
        self::assertSame(1, $row['pending']);
    }

    public function test_today_starts_at_cairo_midnight_and_zero_baseline_is_new_not_one_hundred_percent(): void
    {
        $course = $this->course();
        $period = ReportPeriod::fromKey('today');
        self::assertSame('2026-09-08 21:00:00', $period->start->format('Y-m-d H:i:s'));
        $this->event($course, $period->start->subSecond(), 'outside');
        $this->event($course, $period->start, 'inside');
        $this->event($course, $period->end, 'future');

        $overview = app(ProductAnalyticsService::class)->overview($course->id, $period);
        self::assertSame(1, $overview['quality']['events']);
        self::assertSame('new', $overview['changes']['events']['status']);
        self::assertNull($overview['changes']['events']['percentage']);
    }

    public function test_platform_cash_is_filtered_by_captured_approval_and_incomplete_net_is_not_compared(): void
    {
        $admin = $this->user('admin');
        $package = Package::create(['name_ar' => 'باقة', 'name_en' => 'Package', 'price' => 100, 'coins' => 500]);
        $period = ReportPeriod::fromKey('7d');
        $this->payment($admin, $package, $period->previous()->start, 100, ['gateway_net_amount' => 90]);
        $this->payment($admin, $package, $period->start, 200, ['gateway_net_amount' => null]);
        $this->payment($admin, $package, $period->end, 900);
        $this->payment($admin, $package, $period->end->subDay(), 800, [
            'gateway_settlement_status' => 'test_purchase',
        ]);
        $this->payment($admin, $package, $period->end->subDay(), 700, [
            'gateway_settlement_status' => 'catalog_estimate',
        ]);
        $this->payment($admin, $package, $period->end->subDay(), 600, [
            'gateway_currency' => 'USD',
        ]);
        $this->actingAs($admin, 'web');

        $home = $this->financial(['period' => '7d'])->assertOk();
        $stats = $home->instance()->report()['revenueStats'];
        self::assertSame(200.0, $stats['total_revenue']);
        self::assertSame(100.0, $stats['previous_period_revenue']);
        self::assertSame('unavailable', $stats['revenue_change']['status']);
        self::assertSame('unavailable', $stats['net_change']['status']);
        self::assertSame(200.0, (float) collect($home->instance()->report()['monthlyRevenue'])->sum('course_revenue'));
        $analytics = $this->analytics(['period' => '7d'])->assertOk();
        self::assertSame(200.0, $analytics->instance()->report()['paymentChannelReport']['egp']['confirmed_gross_amount']);
        self::assertSame('unavailable', $analytics->instance()->report()['paymentChanges']['net']['status']);
        $analytics->assertSee('بانتظار التسوية');
    }

    public function test_confirmed_cash_growth_and_pending_only_cash_remain_distinct(): void
    {
        $admin = $this->user('admin');
        $package = Package::create(['name_ar' => 'باقة', 'name_en' => 'Package', 'price' => 100, 'coins' => 500]);
        $period = ReportPeriod::fromKey('7d');
        $this->payment($admin, $package, $period->previous()->start, 100);
        $this->payment($admin, $package, $period->start, 200);
        $this->actingAs($admin, 'web');
        $home = $this->financial(['period' => '7d'])->assertOk();
        self::assertSame(100.0, $home->instance()->report()['revenueStats']['revenue_change']['percentage']);
        self::assertSame(100.0, $home->instance()->report()['revenueStats']['net_change']['percentage']);
        Order::where('approved_at', $period->start)->update([
            'gateway_settlement_status' => 'catalog_estimate', 'gateway_net_amount' => null,
        ]);
        $this->financial(['period' => '7d'])->assertOk()->assertSee('بانتظار تأكيد التحصيل');
        $analytics = $this->analytics(['period' => '7d'])
            ->assertOk()->assertSee('بانتظار تأكيد التحصيل');
        self::assertSame('unavailable', $analytics->instance()->report()['paymentChanges']['gross']['status']);
    }

    public function test_course_selection_keeps_period_but_never_attributes_global_topups_or_invoices_to_course(): void
    {
        $admin = $this->user('admin');
        $course = $this->course();
        $other = $this->course();
        $this->event($course, CarbonImmutable::now('UTC')->subHour(), 'selected');
        $this->event($other, CarbonImmutable::now('UTC')->subHour(), 'other');
        OperatingCostPool::create([
            'name' => 'فاتورة مشتركة', 'service_key' => 'infrastructure',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-08',
            'amount' => 50, 'currency' => 'EGP', 'allocation_driver' => 'active_students', 'is_final' => true,
        ]);
        $this->actingAs($admin, 'web');
        $response = $this->analytics([
            'course_id' => $course->id, 'period' => '90d',
        ])->assertOk()->assertSet('appliedFilters.period', '90d')
            ->assertDontSee('تحصيل مؤكد لباقات العملات (جنيه)')->assertDontSee('فواتير التشغيل المسجلة');
        self::assertSame(1, $response->instance()->report()['analytics']['quality']['events']);
        self::assertNull($response->instance()->report()['paymentChannelReport']);
        self::assertNull($response->instance()->report()['invoiceReport']);
        $global = $this->analytics(['period' => '90d'])->assertOk();
        self::assertSame(50.0, $global->instance()->report()['invoiceReport']['known_total_egp']);
    }

    public function test_ai_reporting_excludes_estimated_cost_and_marks_partial_growth_unavailable(): void
    {
        $admin = $this->user('admin');
        $course = $this->course();
        $other = $this->course();
        $enrollment = $this->enrollment($admin, $course);
        $period = ReportPeriod::fromKey('7d');
        $this->usage($enrollment, $period->previous()->start, .01, 'provider');
        $this->usage($enrollment, $period->start, .02, 'provider');
        $this->usage($enrollment, $period->end->subSecond(), 9, 'reservation_fallback');
        $this->usage($enrollment, $period->start, 0, 'cache_zero_cost');
        $this->usage($enrollment, $period->end, 20, 'provider');
        $otherEnrollment = $this->enrollment($admin, $other);
        $this->usage($otherEnrollment, $period->start, .3, 'provider');

        $this->actingAs($admin, 'web');
        $response = $this->analytics([
            'period' => '7d', 'course_id' => $course->id,
        ])->assertOk()->assertSee('قياس جزئي')->assertDontSee('9.020000');
        $analytics = $response->instance()->report()['analytics'];
        self::assertSame(.02, $analytics['ai']['cost_usd']);
        self::assertSame(1, $analytics['ai']['estimated_cost_requests']);
        self::assertSame(3, $analytics['ai']['completed_requests']);
        self::assertSame('unavailable', $analytics['changes']['cost_usd']['status']);
        $home = $this->financial(['period' => '7d'])->assertOk();
        self::assertSame(.32, $home->instance()->report()['ai']['cost_usd']);
        self::assertSame('unavailable', $home->instance()->report()['aiChange']['status']);
    }

    public function test_analytics_resolves_revision_links_to_canonical_events_and_preserves_archived_history(): void
    {
        $canonical = $this->course();
        $draft = $this->course();
        $archive = $this->course();
        $independentDraft = $this->course();
        foreach ([[$draft, CourseAuthoringRevision::DRAFT], [$archive, CourseAuthoringRevision::ARCHIVED]] as [$copy, $status]) {
            CourseAuthoringRevision::create([
                'canonical_course_id' => $canonical->id, 'revision_course_id' => $copy->id,
                'base_authoring_version' => 1, 'status' => $status,
                'active_slot' => $status === CourseAuthoringRevision::DRAFT ? 'course-draft:'.$canonical->id : null,
                'clone_key' => Str::uuid(),
            ]);
        }
        $this->event($canonical, CarbonImmutable::now('UTC')->subHour(), 'canonical');
        $this->event($independentDraft, CarbonImmutable::now('UTC')->subHour(), 'independent');
        $this->actingAs($this->user('admin'), 'web');
        foreach ([$draft, $archive] as $selected) {
            $response = $this->analytics([
                'course_id' => $selected->id, 'period' => '7d',
            ])->assertOk();
            self::assertSame($canonical->id, $response->instance()->report()['filters']['course_id']);
            self::assertSame(1, $response->instance()->report()['analytics']['quality']['events']);
            self::assertEqualsCanonicalizing([$canonical->id, $independentDraft->id], $response->instance()->report()['courses']->modelKeys());
        }
        $canonical->delete();
        foreach ([$canonical, $archive] as $selected) {
            $response = $this->analytics([
                'course_id' => $selected->id, 'period' => '7d',
            ])->assertOk();
            self::assertSame($canonical->id, $response->instance()->report()['filters']['course_id']);
            self::assertSame(1, $response->instance()->report()['analytics']['quality']['events']);
            self::assertContains($canonical->id, $response->instance()->report()['courses']->modelKeys());
        }
    }

    public function test_unknown_only_ai_cost_is_not_presented_as_zero_and_known_increase_is_neutral(): void
    {
        $admin = $this->user('admin');
        $course = $this->course();
        $enrollment = $this->enrollment($admin, $course);
        $period = ReportPeriod::fromKey('7d');
        $pending = $this->usage($enrollment, $period->start, 8, 'reservation_fallback');
        $this->actingAs($admin, 'web');
        $unknown = $this->analytics(['period' => '7d'])->assertOk();
        self::assertSame('غير متاح', $this->analyticsEntry($unknown, 'confirmed_ai_cost')->getState());
        $pending->update(['cost_usd' => .02, 'metadata' => ['cost_usage_source' => 'provider']]);
        $this->usage($enrollment, $period->previous()->start, .01, 'provider');
        $known = $this->analytics(['period' => '7d'])->assertOk();
        self::assertSame(100.0, $known->instance()->report()['analytics']['changes']['cost_usd']['percentage']);
        self::assertSame('ارتفاع 100.0٪ عن الفترة السابقة', $this->analyticsEntry($known, 'ai_change')->getState());
        self::assertNull($this->analyticsEntry($known, 'ai_change')->getColor($this->analyticsEntry($known, 'ai_change')->getState()));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->user('admin'), 'web');
        foreach (['admin.dashboard', 'admin.product-analytics.index'] as $route) {
            $this->getJson(route($route, ['period' => '365d']))->assertUnprocessable()->assertJsonValidationErrors('period');
        }
        $this->getJson(route('admin.product-analytics.index', ['course_id' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors('course_id');
    }

    public function test_operating_report_distinguishes_pending_cost_from_confirmed_zero(): void
    {
        $admin = $this->user('admin');
        $enrollment = $this->enrollment($admin, $this->course());
        $usage = $this->usage($enrollment, now()->toImmutable()->subMinute(), .025, 'reservation_fallback');
        $this->actingAs($admin, 'web');
        $pending = $this->commercial(['period' => '7d'])->assertOk()
            ->assertSee('بانتظار التأكيد')
            ->assertDontSee('$0.025000');
        self::assertNull($pending->instance()->report()['ai_cost_per_1000_tokens_usd']);
        $pending->assertDontSee('فاتورة مزود مسجلة؛');

        $usage->update(['cost_usd' => 0, 'metadata' => ['cost_usage_source' => 'provider']]);
        $confirmed = $this->commercial(['period' => '7d'])->assertOk()
            ->assertSee('$0.000000')->assertDontSee('بانتظار التأكيد');
        self::assertTrue($confirmed->instance()->report()['ai_cost_complete']);
    }

    public function test_moderators_do_not_resolve_new_financial_services(): void
    {
        $resolved = [];
        foreach ([AiUsageReportService::class, ProviderInvoiceReportService::class] as $class) {
            $this->app->resolving($class, static function () use (&$resolved, $class): void {
                $resolved[] = $class;
            });
        }
        $this->actingAs($this->user('moderator'), 'web')->get(route('admin.dashboard', ['period' => '7d']))
            ->assertOk()->assertViewIs('admin.home.moderator')->assertViewMissing('ai')->assertViewMissing('invoiceReport');
        $this->get(route('admin.product-analytics.index', ['period' => '7d']))->assertForbidden();
        self::assertSame([], $resolved);
    }

    /** Exercise the actual saved URL adapter and then the actual native component. */
    private function analytics(array $filters): Testable
    {
        $redirect = $this->get(route('admin.product-analytics.index', $filters))->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);
        Filament::setCurrentPanel(Filament::getPanel('operations'));

        return Livewire::withQueryParams($query)->test(ProductAnalytics::class);
    }

    private function commercial(array $filters): Testable
    {
        $redirect = $this->get(route('admin.operating-costs.report', $filters))->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);
        Filament::setCurrentPanel(Filament::getPanel('operations'));

        return Livewire::withQueryParams($query)->test(CommercialReport::class);
    }

    private function financial(array $filters): Testable
    {
        $redirect = $this->get(route('admin.dashboard', $filters))->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);
        Filament::setCurrentPanel(Filament::getPanel('operations'));

        return Livewire::withQueryParams($query)->test(FinancialOverview::class);
    }

    private function analyticsEntry(Testable $page, string $field): TextEntry
    {
        $entry = $page->instance()->getSchema('infolist')->getComponentByStatePath($field, withHidden: true);
        self::assertInstanceOf(TextEntry::class, $entry);

        return $entry;
    }

    private function user(string $role): User
    {
        return User::forceCreate(['name_ar' => 'مستخدم التقرير', 'email' => Str::uuid().'@example.test', 'role' => $role, 'active' => true]);
    }

    private function course(): Course
    {
        return Course::forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس التقرير', 'price' => 500, 'is_coming_soon' => true]);
    }

    private function enrollment(User $user, Course $course): CourseEnrollment
    {
        return CourseEnrollment::forceCreate([
            'tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id, 'enrolled_at' => now(),
        ]);
    }

    private function event(Course $course, CarbonImmutable $at, string $actor): void
    {
        ProductEvent::create([
            'event_id' => Str::uuid(), 'actor_key' => hash('sha256', $actor), 'session_key' => hash('sha256', $actor),
            'event_name' => 'course_opened', 'course_id' => $course->id, 'source' => 'app',
            'occurred_at' => $at, 'received_at' => $at,
        ]);
    }

    private function payment(User $user, Package $package, CarbonImmutable $at, float $amount, array $overrides = []): void
    {
        Order::create($overrides + [
            'user_id' => $user->id, 'package_id' => $package->id, 'package_coins' => $package->coins,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER, 'order_ref' => 'PERIOD-'.Str::uuid(),
            'amount' => $amount, 'discount_amount' => 0, 'final_amount' => $amount,
            'gateway_gross_amount' => $amount, 'gateway_currency' => 'EGP', 'gateway_net_amount' => $amount,
            'gateway_settlement_status' => 'settled', 'total_coins' => $package->coins,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => $at,
        ]);
    }

    private function usage(CourseEnrollment $enrollment, CarbonImmutable $at, float $cost, string $source): AiUsageEvent
    {
        return AiUsageEvent::create([
            'request_id' => Str::uuid(), 'enrollment_id' => $enrollment->id, 'user_id' => $enrollment->user_id,
            'course_id' => $enrollment->course_id, 'feature' => 'course_chat', 'status' => 'completed',
            'total_tokens' => 100, 'cost_usd' => $cost, 'metadata' => ['cost_usage_source' => $source],
            'created_at' => $at, 'updated_at' => $at, 'completed_at' => $at,
        ]);
    }
}
