<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\StudentProgressResource;
use App\Filament\Resources\StudentProgressResource\Pages\ListStudentProgress;
use App\Filament\Resources\StudentProgressResource\Pages\ViewStudentProgress;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\AdminStudentProgressReadService;
use App\Services\StudentProgressSummaryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\RendersAdminPages;
use Tests\TestCase;

/** Native progress views preserve the read owner's entitlement-aware projection. */
final class AdminStudentProgressViewsTest extends TestCase
{
    use RefreshDatabase;
    use RendersAdminPages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_saved_progress_links_redirect_and_native_pages_remain_read_only(): void
    {
        [$admin, $student] = $this->records();
        $this->actingAs($admin, 'web');
        $this->get(route('admin.student-progress.index', ['search' => 'progress-learner']))->assertRedirect(StudentProgressResource::listUrl(['search' => 'progress-learner']));
        $this->get(route('admin.student-progress.show', $student))->assertRedirect(StudentProgressResource::getUrl('view', ['record' => $student]));
        $this->get(StudentProgressResource::getUrl('view', ['record' => $student]))->assertOk()->assertSee('كورس التقدم');
        self::assertFalse(StudentProgressResource::canCreate());
        self::assertFalse(StudentProgressResource::canEdit($student));
        self::assertFalse(StudentProgressResource::canDelete($student));
        $this->get(StudentProgressResource::getUrl('view', ['record' => $admin]))->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->dashboardUser('moderator'), 'web')->get(StudentProgressResource::getUrl())->assertForbidden();
    }

    public function test_native_search_course_filter_and_pagination_preserve_the_same_students(): void
    {
        foreach (range(1, 26) as $index) {
            $this->dashboardUser('client', "progress-filler-{$index}@rokn.test");
        }
        [$admin, $student, $course] = $this->records();
        $this->actingAs($admin, 'web');
        $page = Livewire::test(ListStudentProgress::class)->assertCountTableRecords(27);
        $page->searchTable('progress-learner@rokn.test')->assertCountTableRecords(1)->assertCanSeeTableRecords([$student]);
        $page->searchTable('')->filterTable('course_id', $course->id)->assertCountTableRecords(1)
            ->assertTableActionHasUrl('progress', StudentProgressResource::getUrl('view', ['record' => $student]), $student);
        $row = $page->instance()->getTableRecords()->items()[0];
        self::assertSame('كورس التقدم', data_get($row->getAttribute('learning_progress'), 'course.title'));
        self::assertSame(0, data_get($row->getAttribute('learning_progress'), 'progress.total_sections'));
        $page->filterTable('course_id', null)->assertCountTableRecords(27)->set('paginators.page', 2);
        self::assertCount(2, $page->instance()->getTableRecords()->items());
    }

    public function test_detail_and_page_projection_do_not_write_and_cover_the_empty_student(): void
    {
        [$admin, $student] = $this->records();
        $empty = $this->dashboardUser('client', 'progress-empty@rokn.test');
        $this->actingAs($admin, 'web');
        $page = Livewire::test(ViewStudentProgress::class, ['record' => $student->id]);
        self::assertSame(['courses' => 1, 'completed' => 0, 'sections' => 0, 'percentage' => 0], $page->instance()->totals());
        Livewire::test(ViewStudentProgress::class, ['record' => $empty->id])->assertSee('لا توجد كورسات نشطة');
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(AdminStudentProgressReadService::class)->workspace((int) $student->id);
        foreach (DB::getQueryLog() as $query) {
            self::assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $query['query']);
        }
        DB::disableQueryLog();
    }

    public function test_an_unfinished_section_has_activity_but_never_a_completion_date(): void
    {
        [$admin, $student, $course] = $this->records();
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $project = Project::query()->create(['requirements_text' => 'نفذ المشروع']);
        $section = CourseSection::query()->create(['course_id' => $course->id, 'module_id' => $module->id,
            'title' => 'لم يكتمل', 'section_type' => 'project', 'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 1]);
        ProjectSubmission::query()->create(['public_id' => (string) Str::uuid(), 'user_id' => $student->id,
            'project_id' => $project->id, 'idempotency_key' => 'unfinished-progress', 'review_status' => 'pending', 'effort_status' => 'valid', 'submitted_at' => now()]);
        $detail = app(AdminStudentProgressReadService::class)->workspace((int) $student->id)['coursesProgress']->sole();
        self::assertNotNull($detail['progress']['last_activity']);
        self::assertFalse($detail['sections_detail']->sole()['is_completed']);
        self::assertNull($detail['sections_detail']->sole()['completed_at']);
        $this->actingAs($admin, 'web');
        Livewire::test(ViewStudentProgress::class, ['record' => $student->id])->assertSee('0 من 1')->assertDontSee('الاكتمال بتوقيت القاهرة');
    }

    public function test_progress_summary_is_one_batch_not_queries_for_every_student(): void
    {
        [, $student, $course] = $this->records();
        $users = collect([$student]);
        foreach (range(1, 7) as $index) {
            $user = $this->dashboardUser('client', 'batch-'.$index.'@progress.test');
            CourseEnrollment::query()->forceCreate(['tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
                'is_active' => true, 'enrolled_at' => now(), 'access_granted_at' => now()]);
            $users->push($user);
        }
        $summaries = app(StudentProgressSummaryService::class);
        $summaries->latestForUsers(collect([$student]));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $summaries->latestForUsers(collect([$student]));
        $singleQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $rows = $summaries->latestForUsers($users);
        self::assertCount(8, $rows);
        self::assertLessThanOrEqual($singleQueries, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    /** @return array{User,User,Course} */
    private function records(): array
    {
        $admin = $this->dashboardUser('admin');
        $student = $this->dashboardUser('client', 'progress-learner@rokn.test');
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس التقدم', 'price' => 100,
            'is_coming_soon' => false, 'is_catalog_visible' => true, 'authoring_version' => 1]);
        CourseEnrollment::query()->forceCreate(['tenant_id' => 1, 'user_id' => $student->id, 'course_id' => $course->id,
            'is_active' => true, 'enrolled_at' => now(), 'access_granted_at' => now()]);

        return [$admin, $student, $course];
    }
}
