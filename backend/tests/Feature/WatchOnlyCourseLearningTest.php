<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseCode;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\AdminCoursePreviewService;
use App\Services\CertificateTextTemplateService;
use App\Services\CourseChatAccessService;
use App\Services\CourseCompletionService;
use App\Services\CoursePresentationService;
use App\Services\CurriculumCompletionService;
use App\Services\LearningDashboardService;
use App\Services\StudentProgressSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WatchOnlyCourseLearningTest extends TestCase
{
    use RefreshDatabase;

    private User $learner;
    private Course $course;
    private CourseEnrollment $enrollment;
    private CourseAccessPlan $plan;
    private CourseSection $projectSection;
    private CourseSection $lastLesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->learner = new User();
        $this->learner->forceFill([
            'name' => 'Watch-only learner', 'email' => 'watch-only@rokn.test',
            'password' => bcrypt('test-only'), 'role' => 'client', 'active' => true,
        ])->save();
        $this->course = new Course();
        $this->course->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس المشاهدة', 'name_en' => 'Watching course',
            'price' => 400, 'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1,
            'published_at' => now(),
        ])->save();
        $module = CourseModule::query()->create([
            'course_id' => $this->course->id, 'title_ar' => 'الوحدة', 'order' => 1,
        ]);
        foreach ([1, 3] as $position) {
            $lesson = Lesson::query()->create([
                'list_id' => $this->course->id, 'title_ar' => 'مقطع '.$position,
                'duration_minutes' => 1,
            ]);
            $this->lastLesson = CourseSection::query()->create([
                'course_id' => $this->course->id, 'module_id' => $module->id,
                'title_ar' => 'مقطع '.$position, 'section_type' => 'lesson',
                'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id,
                'order' => $position,
            ]);
        }
        $project = Project::query()->create(['requirements_text' => 'نفذ المشروع']);
        $this->projectSection = CourseSection::query()->create([
            'course_id' => $this->course->id, 'module_id' => $module->id,
            'title_ar' => 'مشروع العبور', 'section_type' => 'project',
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id,
            'order' => 2,
        ]);
        $this->plan = $this->course->accessPlans()->create([
            'code' => 'basic', 'name_ar' => 'Basic', 'name_en' => 'Basic',
            'price_coins' => 400, 'minimum_paid_coins' => 0,
            'chat_enabled' => false, 'certificate_enabled' => false,
            'projects_enabled' => false, 'project_feedback_level' => 'pass_only',
            'is_active' => true,
        ]);
        $order = $this->contractOrder($this->plan);
        $this->enrollment = new CourseEnrollment();
        $this->enrollment->forceFill([
            'tenant_id' => 1, 'user_id' => $this->learner->id, 'course_id' => $this->course->id,
            'order_id' => $order->id, 'access_plan_order_id' => $order->id,
            'access_plan_id' => $this->plan->id, 'access_plan_snapshot' => $order->access_plan_snapshot,
            'is_active' => true, 'enrolled_at' => now(), 'access_granted_at' => now(),
        ])->save();
    }

    public function test_watch_only_path_skips_projects_without_fabricating_project_completion(): void
    {
        $access = app(CourseChatAccessService::class);
        self::assertTrue($access->hasLearningAccess($this->learner->id, $this->course->id));
        self::assertNull($access->activeProjectEnrollmentFor($this->learner->id, $this->course->id));
        self::assertFalse($access->hasCertificateAccess($this->learner->id, $this->course->id));
        $completion = app(CourseCompletionService::class);
        self::assertTrue($completion->canAccessSection($this->learner, $this->lastLesson));
        self::assertFalse($completion->canAccessSection($this->learner, $this->projectSection));
        $denied = $completion->complete($this->learner, $this->course->id, $this->projectSection->id);
        self::assertSame(403, $denied['status']);
        self::assertSame('projects_not_included', $denied['code']);

        $this->completeLessons();
        $summary = app(CoursePresentationService::class)->progressSummary($this->learner->id, $this->course->id);
        self::assertSame(2, $summary['total_sections']);
        self::assertSame(2, $summary['completed_sections']);
        self::assertTrue($summary['is_completed']);
        self::assertNull($summary['next_section']);
        self::assertSame(0, DB::table('student_section_progress')->where('course_section_id', $this->projectSection->id)->count());
        self::assertSame(0, DB::table('project_submissions')->count());
    }

    public function test_watch_only_completion_does_not_become_a_practical_certificate_on_upgrade(): void
    {
        $this->completeLessons();
        $completion = app(CurriculumCompletionService::class);
        self::assertSame(1, $completion->markCompleted($this->learner->id, $this->course->id));
        self::assertFalse($this->enrollment->fresh()->completed_with_projects);
        self::assertNull($completion->earnedRevision($this->enrollment->fresh()));

        $this->plan->forceFill(['projects_enabled' => true, 'certificate_enabled' => true])->save();
        // Editing an offer cannot change the already purchased Basic contract.
        self::assertFalse(app(CourseAccessPlanService::class)->projectsEnabledForEnrollment($this->enrollment->fresh()));
        $upgraded = $this->contractOrder($this->plan, $this->enrollment->order_id);
        $this->enrollment->forceFill([
            'access_plan_order_id' => $upgraded->id,
            'access_plan_snapshot' => $upgraded->access_plan_snapshot,
        ])->save();

        self::assertNull($completion->markCompleted($this->learner->id, $this->course->id));
        self::assertNull($completion->earnedRevision($this->enrollment->fresh()));
        self::assertFalse(app(CourseCompletionService::class)->canAccessSection($this->learner, $this->lastLesson));
        self::assertSame(3, app(CoursePresentationService::class)->progressSummary($this->learner->id, $this->course->id)['total_sections']);
        self::assertSame(0, DB::table('certificates')->count());

        DB::table('student_section_progress')->insert([
            'user_id' => $this->learner->id, 'course_section_id' => $this->projectSection->id,
            'is_completed' => true, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::assertNull($completion->markCompleted($this->learner->id, $this->course->id));
        ProjectSubmission::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'watch-only-upgraded-project',
            'user_id' => $this->learner->id, 'project_id' => $this->projectSection->sectionable_id,
            'submission_text' => 'My completed project', 'effort_status' => 'valid',
            'review_status' => 'passed', 'review_source' => 'manual',
            'submitted_at' => now(), 'reviewed_at' => now(),
        ]);
        self::assertSame(1, $completion->markCompleted($this->learner->id, $this->course->id));
        self::assertTrue($this->enrollment->fresh()->completed_with_projects);
        self::assertSame(1, $completion->earnedRevision($this->enrollment->fresh()));
    }

    public function test_grant_can_finish_lessons_but_cannot_submit_projects_or_earn_a_certificate(): void
    {
        $this->enrollment->order->forceFill([
            'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE,
            'final_amount' => 0,
        ])->save();
        $this->enrollment->forceFill([
            'access_plan_id' => null,
            'access_plan_order_id' => null,
            'access_plan_snapshot' => null,
        ])->save();
        $access = app(CourseChatAccessService::class);
        self::assertTrue($access->hasLearningAccess($this->learner->id, $this->course->id));
        self::assertNull($access->activeProjectEnrollmentFor($this->learner->id, $this->course->id));
        self::assertFalse($access->hasCertificateAccess($this->learner->id, $this->course->id));
        $completion = app(CourseCompletionService::class);
        self::assertTrue($completion->canAccessSection($this->learner, $this->lastLesson));
        self::assertFalse($completion->canAccessSection($this->learner, $this->projectSection));
        self::assertSame('projects_not_included', $completion->complete(
            $this->learner, $this->course->id, $this->projectSection->id
        )['code']);
        $this->completeLessons();
        self::assertTrue(app(CoursePresentationService::class)->progressSummary(
            $this->learner->id, $this->course->id
        )['is_completed']);
        self::assertSame(0, DB::table('project_submissions')->count());
        self::assertSame(0, DB::table('certificates')->count());
    }

    public function test_learning_dashboard_and_staff_summary_use_the_captured_watch_only_path(): void
    {
        $this->completeLessons();
        $dashboard = app(LearningDashboardService::class)->forUser($this->learner);
        $course = collect($dashboard['items'])->firstWhere('course_id', $this->course->id);
        self::assertSame(2, $course['total_sections']);
        self::assertTrue($course['is_completed']);
        self::assertFalse($course['projects_available']);
        self::assertFalse($course['certificate_available']);

        $staff = app(StudentProgressSummaryService::class)->latestForUsers(collect([$this->learner]));
        self::assertSame(2, $staff[$this->learner->id]['progress']['total_sections']);
        self::assertSame(100, $staff[$this->learner->id]['progress']['progress_percentage']);
    }

    public function test_dashboard_grant_preview_has_the_same_watch_only_rights_without_changing_enrollment(): void
    {
        $this->course->forceFill([
            'certificate_text_template_key' => app(CertificateTextTemplateService::class)->keys()[0],
        ])->save();
        (new CourseCode())->forceFill([
            'tenant_id' => 1, 'type' => 'course',
            'course_id' => $this->course->id, 'code' => 'preview-grant-only',
            'is_grant' => true, 'is_active' => true, 'max_uses' => 10, 'used_count' => 0,
        ])->save();
        $before = $this->enrollment->fresh()->getAttributes();
        $preview = app(AdminCoursePreviewService::class)->prepare(
            $this->course, $this->learner, 'grant', Request::create('/admin/courses/preview')
        );
        self::assertNull($preview['error']);
        self::assertSame('grant', $preview['selectedPlan']['code']);
        self::assertFalse($preview['selectedPlan']['chat_enabled']);
        self::assertFalse($preview['selectedPlan']['projects_enabled']);
        self::assertFalse($preview['selectedPlan']['certificate_enabled']);
        self::assertFalse($preview['previewPayload']['projects_available']);
        self::assertSame($before, $this->enrollment->fresh()->getAttributes());
    }

    private function completeLessons(): void
    {
        foreach ($this->course->sections()->where('section_type', 'lesson')->get() as $section) {
            DB::table('student_section_progress')->insert([
                'user_id' => $this->learner->id, 'course_section_id' => $section->id,
                'is_completed' => true, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function contractOrder(CourseAccessPlan $plan, ?int $parent = null): Order
    {
        return Order::query()->create([
            'user_id' => $this->learner->id, 'course_id' => $this->course->id,
            'access_plan_id' => $plan->id, 'access_plan_snapshot' => app(CourseAccessPlanService::class)->snapshot($plan),
            'parent_order_id' => $parent, 'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 400, 'discount_amount' => 0, 'final_amount' => 400,
            'total_coins' => 400, 'paid_coins' => 400, 'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);
    }
}
