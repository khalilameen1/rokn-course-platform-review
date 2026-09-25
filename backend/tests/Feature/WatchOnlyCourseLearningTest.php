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
use App\Services\CourseEntitlementService;
use App\Services\CourseCompletionService;
use App\Services\CourseSectionAccessService;
use App\Services\CoursePresentationService;
use App\Services\CurriculumCompletionService;
use App\Services\LearningDashboardService;
use App\Services\LearningAchievementSignalService;
use App\Services\StudentProgressSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
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
        $access = app(CourseEntitlementService::class);
        self::assertTrue($access->hasLearningAccess($this->learner->id, $this->course->id));
        self::assertNull($access->activeProjectEnrollmentFor($this->learner->id, $this->course->id));
        self::assertFalse($access->hasCertificateAccess($this->learner->id, $this->course->id));
        $completion = app(CourseCompletionService::class);
        $sections = app(CourseSectionAccessService::class);
        self::assertTrue($sections->canAccessSection($this->learner, $this->lastLesson));
        self::assertFalse($sections->canAccessSection($this->learner, $this->projectSection));
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
        $this->travelTo(now()->startOfSecond());
        $this->completeLessons();
        $completion = app(CurriculumCompletionService::class);
        self::assertSame(1, $completion->markCompleted($this->learner->id, $this->course->id));
        self::assertFalse($this->enrollment->fresh()->completed_with_projects);
        self::assertNull($completion->earnedRevision($this->enrollment->fresh()));

        $watchOnlyCompletedAt = $this->enrollment->fresh()->curriculum_completed_at;
        $this->travel(1)->days();

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
        self::assertFalse(app(CourseSectionAccessService::class)->canAccessSection($this->learner, $this->lastLesson));
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
        self::assertTrue($this->enrollment->fresh()->curriculum_completed_at->greaterThan($watchOnlyCompletedAt));
    }

    public function test_earned_completion_cannot_be_rewritten_or_downgraded(): void
    {
        $this->enrollment->forceFill([
            'completed_curriculum_revision' => 1,
            'curriculum_completed_at' => now()->subDay(),
            'completed_with_projects' => true,
        ])->save();
        $original = $this->enrollment->fresh();

        foreach ([
            ['completed_curriculum_revision' => 2],
            ['curriculum_completed_at' => now()],
            ['completed_with_projects' => false],
        ] as $mutation) {
            try {
                $this->enrollment->fresh()->forceFill($mutation)->save();
                self::fail('An earned completion was changed.');
            } catch (\LogicException $exception) {
                self::assertSame('Earned curriculum completion is immutable.', $exception->getMessage());
            }
        }
        $unchanged = $this->enrollment->fresh();
        self::assertSame(1, $unchanged->completed_curriculum_revision);
        self::assertTrue($unchanged->completed_with_projects);
        self::assertTrue($unchanged->curriculum_completed_at->equalTo($original->curriculum_completed_at));
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
        $access = app(CourseEntitlementService::class);
        self::assertTrue($access->hasLearningAccess($this->learner->id, $this->course->id));
        self::assertNull($access->activeProjectEnrollmentFor($this->learner->id, $this->course->id));
        self::assertFalse($access->hasCertificateAccess($this->learner->id, $this->course->id));
        $completion = app(CourseCompletionService::class);
        $sections = app(CourseSectionAccessService::class);
        self::assertTrue($sections->canAccessSection($this->learner, $this->lastLesson));
        self::assertFalse($sections->canAccessSection($this->learner, $this->projectSection));
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
            $this->course, 'grant'
        );
        self::assertNull($preview['error']);
        self::assertSame('grant', $preview['selectedPlan']['code']);
        self::assertFalse($preview['selectedPlan']['chat_enabled']);
        self::assertFalse($preview['selectedPlan']['projects_enabled']);
        self::assertFalse($preview['selectedPlan']['certificate_enabled']);
        $payload = app(CoursePresentationService::class)->dashboardPreview(
            $preview['previewCourse'], $this->learner, $preview['selectedPlan'], 'scholarship'
        )->resolve(Request::create('/admin/courses/preview'));
        self::assertFalse($payload['projects_available']);
        self::assertSame($before, $this->enrollment->fresh()->getAttributes());
    }

    public function test_section_access_reads_do_not_resolve_completion_or_presentation_writers_or_modify_learning_state(): void
    {
        Http::preventStrayRequests();
        foreach ([CourseCompletionService::class, CoursePresentationService::class, LearningAchievementSignalService::class] as $otherOwner) {
            app()->bind($otherOwner, static function (): never {
                throw new \LogicException('A section access read must not resolve completion or presentation.');
            });
        }
        $before = $this->enrollment->fresh()->getAttributes();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $sections = app(CourseSectionAccessService::class);
            self::assertTrue($sections->canAccessSection($this->learner, $this->lastLesson));
            self::assertSame([
                'can_access' => false, 'is_locked' => true, 'lock_reason' => 'projects_not_included',
            ], $sections->sectionAccessState($this->learner, $this->projectSection));
            $map = $sections->sectionLockStatus($this->course->sections()->get(), collect(), (int) $this->learner->id);
            self::assertCount(2, $map);
            self::assertFalse($map->contains('section_id', $this->projectSection->id));
            self::assertTrue($map->every(fn (array $state): bool => $state['can_access'] && !$state['is_completed']));
            self::assertSame([], array_values(array_filter(
                DB::getQueryLog(),
                static fn (array $query): bool => (bool) preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query'])
            )));
        } finally {
            DB::disableQueryLog();
        }
        self::assertSame($before, $this->enrollment->fresh()->getAttributes());
        $this->assertDatabaseCount('student_section_progress', 0);
        $this->assertDatabaseCount('project_submissions', 0);
        $this->assertDatabaseCount('certificates', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['inactive_user'])]
    #[TestWith(['no_enrollment'])]
    #[TestWith(['inactive_enrollment'])]
    #[TestWith(['expired_enrollment'])]
    #[TestWith(['unpublished_course'])]
    public function test_section_reader_preserves_course_access_denial_before_sequence_checks(string $scenario): void
    {
        match ($scenario) {
            'inactive_user' => $this->learner->forceFill(['active' => false])->save(),
            'no_enrollment' => $this->enrollment->delete(),
            'inactive_enrollment' => $this->enrollment->forceFill(['is_active' => false])->save(),
            'expired_enrollment' => $this->enrollment->forceFill(['expires_at' => now()->subDay()])->save(),
            'unpublished_course' => $this->course->forceFill(['is_coming_soon' => true])->save(),
        };
        self::assertSame([
            'can_access' => false, 'is_locked' => true, 'lock_reason' => 'course_purchase_required',
        ], app(CourseSectionAccessService::class)->sectionAccessState($this->learner, $this->lastLesson));
        $this->assertDatabaseCount('student_section_progress', 0);
    }

    public function test_section_reader_fails_closed_for_a_section_outside_the_current_learning_map(): void
    {
        $missing = new CourseSection();
        $missing->forceFill([
            'id' => 999999, 'course_id' => $this->course->id,
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class,
        ]);
        self::assertSame([
            'can_access' => false, 'is_locked' => true, 'lock_reason' => null,
        ], app(CourseSectionAccessService::class)->sectionAccessState($this->learner, $missing));
        $this->assertDatabaseCount('student_section_progress', 0);
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
