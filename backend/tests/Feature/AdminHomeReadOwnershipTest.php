<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\HomeController;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AdminContentInventoryReadService;
use App\Services\AdminHomeReadService;
use App\Services\ModeratorHomeReadService;
use App\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminHomeReadOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_inventory_counts_logical_courses_and_their_content_not_revisions_or_deleted_parents(): void
    {
        $live = $this->course(false);
        $unpublished = $this->course(true);
        $draft = $this->revision($live, CourseAuthoringRevision::DRAFT);
        $archive = $this->revision($live, CourseAuthoringRevision::ARCHIVED);
        $deleted = $this->course(false);
        foreach ([$live, $unpublished, $draft, $archive, $deleted] as $course) {
            $this->lesson($course);
        }
        $deleted->delete();

        $inventory = app(AdminContentInventoryReadService::class);
        self::assertEqualsCanonicalizing([$live->id, $unpublished->id], $inventory->courses()->pluck('id')->all());
        self::assertSame([
            'courses' => 2, 'modules' => 2, 'sections' => 2, 'lessons' => 2, 'published' => 1,
        ], $inventory->summary());
    }

    public function test_both_home_projections_share_inventory_without_resolving_the_http_controller_or_writing(): void
    {
        $live = $this->course(false);
        $draft = $this->revision($live, CourseAuthoringRevision::DRAFT);
        $this->lesson($live);
        $this->lesson($draft);
        $this->user('client');
        $this->user('moderator');
        $this->forbidController();

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $moderator = app(ModeratorHomeReadService::class)->read();
            $admin = app(AdminHomeReadService::class)->read(ReportPeriod::fromKey('7d'));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        self::assertSame(1, $moderator['contentSummary']['courses']);
        self::assertSame(1, $moderator['courses']->total());
        self::assertSame($draft->id, $moderator['courses']->first()->id);
        self::assertArrayHasKey($draft->id, $moderator['publishingAudits']->all());
        self::assertSame(['courses' => 1, 'lessons' => 1, 'students' => 1], $admin['platformStats']);
        self::assertSame('7d', $admin['period']->key);
        self::assertArrayNotHasKey('revenueStats', $moderator);
        self::assertArrayNotHasKey('invoiceReport', $moderator);
        foreach ($queries as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter)\b/i', $query['query']);
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_moderator_pagination_is_explicit_and_working_copies_do_not_consume_slots(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $course = $this->course(false);
            $this->revision($course, CourseAuthoringRevision::DRAFT);
        }
        $this->forbidController();
        $page = app(ModeratorHomeReadService::class)->read(['filter' => 'kept'], 2)['courses'];
        self::assertSame(13, $page->total());
        self::assertSame(12, $page->perPage());
        self::assertSame(2, $page->currentPage());
        self::assertCount(1, $page->items());
        self::assertStringContainsString('filter=kept', $page->url(1));
        self::assertTrue(CourseAuthoringRevision::query()
            ->where('revision_course_id', $page->first()->id)->where('status', CourseAuthoringRevision::DRAFT)->exists());
    }

    public function test_http_adapter_preserves_period_validation_and_role_specific_views(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($this->user('admin'), 'web');
        $this->get(route('admin.dashboard', ['period' => 'invalid']))->assertSessionHasErrors('period');
        $this->get(route('admin.dashboard', ['period' => '30d']))
            ->assertOk()->assertViewIs('admin.home.index')
            ->assertViewHas('period', fn (ReportPeriod $period): bool => $period->key === '30d');
        // A different operator needs a new session, as in the real login flow.
        $this->flushSession();
        $this->actingAs($this->user('moderator'), 'web');
        $this->get(route('admin.dashboard', ['period' => 'invalid']))
            ->assertOk()->assertViewIs('admin.home.moderator')->assertViewMissing('revenueStats');
    }

    private function course(bool $unpublished): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس الاختبار', 'price' => 100,
            'is_coming_soon' => $unpublished, 'is_catalog_visible' => true,
        ]);
    }

    private function revision(Course $canonical, string $status): Course
    {
        $revision = $this->course(true);
        CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $canonical->id, 'revision_course_id' => $revision->id,
            'status' => $status, 'base_authoring_version' => 1,
            'active_slot' => $status === CourseAuthoringRevision::DRAFT
                ? CourseAuthoringRevision::draftSlot((int) $canonical->id) : null,
            'clone_key' => (string) Str::uuid(),
        ]);
        return $revision;
    }

    private function lesson(Course $course): void
    {
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'وحدة', 'order' => 1]);
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'درس', 'priority' => 1]);
        CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id, 'title_ar' => 'درس', 'order' => 1,
            'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id,
        ]);
    }

    private function user(string $role): User
    {
        return User::query()->forceCreate([
            'name_ar' => 'مستخدم الاختبار', 'email' => Str::uuid().'@rokn.test', 'role' => $role, 'active' => true,
        ]);
    }

    private function forbidController(): void
    {
        $this->app->bind(HomeController::class, static function (): never {
            throw new \LogicException('Home projections must not depend on the HTTP adapter.');
        });
    }
}
