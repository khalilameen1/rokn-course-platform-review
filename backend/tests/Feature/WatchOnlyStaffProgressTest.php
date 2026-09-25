<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\StudentProgressController;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CourseLeaderboardService;
use App\Services\AdminStudentProgressReadService;
use App\Services\StudentProgressSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WatchOnlyStaffProgressTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;
    private CourseAccessPlan $basic;
    private CourseAccessPlan $plus;
    private User $watcher;
    private User $practical;
    private User $legacy;
    private CourseEnrollment $watchEnrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->course = new Course();
        $this->course->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس للمقارنة', 'price' => 400,
            'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1,
            'published_at' => now(),
        ])->save();
        $module = CourseModule::query()->create([
            'course_id' => $this->course->id, 'title_ar' => 'الوحدة', 'order' => 1,
        ]);
        foreach ([1, 3] as $position) {
            $lesson = Lesson::query()->create([
                'list_id' => $this->course->id, 'title_ar' => 'مقطع '.$position, 'duration_minutes' => 1,
            ]);
            CourseSection::query()->create([
                'course_id' => $this->course->id, 'module_id' => $module->id,
                'title_ar' => 'مقطع '.$position, 'section_type' => 'lesson',
                'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id, 'order' => $position,
            ]);
        }
        $project = Project::query()->create(['requirements_text' => 'نفذ المشروع']);
        CourseSection::query()->create([
            'course_id' => $this->course->id, 'module_id' => $module->id,
            'title_ar' => 'المشروع', 'section_type' => 'project',
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 2,
        ]);
        $this->basic = $this->plan('basic', false);
        $this->plus = $this->plan('guided', true);
        $this->watcher = $this->student('watcher');
        $this->practical = $this->student('practical');
        $this->legacy = $this->student('legacy');
        $this->watchEnrollment = $this->enroll($this->watcher, $this->basic);
        $this->enroll($this->practical, $this->plus);
        $this->enroll($this->legacy, $this->basic, true);
        foreach ([$this->watcher, $this->practical, $this->legacy] as $learner) {
            foreach ($this->course->sections()->where('section_type', 'lesson')->get() as $section) {
                DB::table('student_section_progress')->insert([
                    'user_id' => $learner->id, 'course_section_id' => $section->id,
                    'is_completed' => true, 'completed_at' => now()->subDays(8),
                    'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8),
                ]);
            }
        }
        // A live offer edit must not turn the watcher's old receipt practical.
        $this->basic->update(['projects_enabled' => true, 'certificate_enabled' => true]);
    }

    public function test_staff_show_and_compare_use_each_purchased_path(): void
    {
        $controller = app(StudentProgressController::class);
        $watch = $controller->show($this->watcher->id)->getData()['coursesProgress']->first();
        self::assertSame(2, $watch['progress']['total_sections']);
        self::assertSame(100, $watch['progress']['progress_percentage']);
        self::assertFalse($watch['progress']['projects_enabled']);
        self::assertSame(['lesson' => 2], $watch['progress']['sections_by_type']);
        self::assertCount(2, $watch['sections_detail']);

        $legacy = $controller->show($this->legacy->id)->getData()['coursesProgress']->first();
        self::assertSame(3, $legacy['progress']['total_sections']);
        self::assertSame(67, $legacy['progress']['progress_percentage']);
        self::assertTrue($legacy['progress']['projects_enabled']);

        $comparison = $controller->compare(Request::create('/', 'POST', [
            'user_ids' => [$this->watcher->id, $this->practical->id], 'course_id' => $this->course->id,
        ]))->getData(true)['comparisons'];
        self::assertSame(2, $comparison[0]['progress']['total_sections']);
        self::assertEquals(100, $comparison[0]['progress']['progress_percentage']);
        self::assertSame(3, $comparison[1]['progress']['total_sections']);
        self::assertEquals(67, $comparison[1]['progress']['progress_percentage']);
    }

    public function test_aggregate_staff_progress_does_not_penalize_watch_only_enrollment(): void
    {
        $statistics = app(StudentProgressController::class)->statistics()->getData(true);
        self::assertSame(3, $statistics['active_enrollments']);
        self::assertEquals(77.78, $statistics['average_progress']);
        self::assertSame([2, 2, 2], array_column($statistics['top_students'], 'completed_count'));
    }

    public function test_staff_read_owner_is_independent_of_http_and_keeps_all_projections_consistent(): void
    {
        $this->app->bind(StudentProgressController::class, static function (): never {
            throw new \LogicException('Progress reads must not resolve a controller.');
        });
        $read = app(AdminStudentProgressReadService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $detail = $read->workspace((int) $this->watcher->id)['coursesProgress']->first()['progress'];
            $comparison = $read->compare([$this->watcher->id, $this->practical->id], (int) $this->course->id);
            $listing = $read->listing(['course_id' => $this->course->id], ['course_id' => $this->course->id]);
            $latest = app(StudentProgressSummaryService::class)->latestForUsers(collect([$this->watcher]))->get($this->watcher->id)['progress'];
            unset($detail['projects_enabled']);
            self::assertEquals($latest, $detail);
            self::assertEquals($latest, $comparison->first()['progress']);
            self::assertSame(3, $listing['users']->total());
            self::assertEquals(77.78, $read->statistics()['average_progress']);
            foreach (DB::getQueryLog() as $query) {
                self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_read_owner_preserves_empty_enrollment_and_comparison_contracts(): void
    {
        $outsider = $this->student('not-enrolled');
        $read = app(AdminStudentProgressReadService::class);
        self::assertSame(0, $read->workspace((int) $outsider->id)['totalEnrollments']);
        $progress = $read->compare([$outsider->id, $this->watcher->id], (int) $this->course->id)->first()['progress'];
        self::assertSame(0, $progress['total_sections']);
        self::assertSame(0, $progress['last_activity']);
        $listing = $read->listing(['search' => 'not-enrolled'], []);
        self::assertFalse($listing['usersWithProgress']->sole()['has_enrollment']);
        self::assertNull($listing['usersWithProgress']->sole()['progress']);
    }

    public function test_leaderboard_progress_preserves_legacy_projects_and_watch_only_completion(): void
    {
        $result = app(CourseLeaderboardService::class)->forCourse($this->course->id);
        $students = collect($result['data']['best_students'])->keyBy('user_id');
        $watch = $students[$this->watcher->id]['progress'];
        self::assertSame(2, $watch['total_sections']);
        self::assertTrue($watch['is_fully_completed']);
        self::assertFalse($watch['projects_enabled']);
        self::assertSame(1, $students[$this->watcher->id]['rank']);
        self::assertSame(3, $students[$this->legacy->id]['progress']['total_sections']);
        self::assertFalse($students[$this->legacy->id]['progress']['is_fully_completed']);
    }

    public function test_watching_completion_does_not_report_full_practical_progress_after_upgrade(): void
    {
        $this->watchEnrollment->forceFill([
            'completed_curriculum_revision' => 1, 'curriculum_completed_at' => now()->subDays(8),
            'completed_with_projects' => false,
        ])->save();
        $receipt = app(CourseAccessPlanService::class)->snapshot($this->plus);
        $this->watchEnrollment->forceFill([
            'access_plan_id' => $this->plus->id, 'access_plan_snapshot' => $receipt,
        ])->save();

        $watch = app(StudentProgressController::class)->show($this->watcher->id)
            ->getData()['coursesProgress']->first();
        self::assertSame(3, $watch['progress']['total_sections']);
        self::assertSame(67, $watch['progress']['progress_percentage']);
        $ranked = collect(app(CourseLeaderboardService::class)->forCourse($this->course->id)['data']['best_students'])
            ->firstWhere('user_id', $this->watcher->id);
        self::assertFalse($ranked['progress']['is_fully_completed']);
    }

    public function test_leaderboard_week_boundary_terminates_for_every_weekday(): void
    {
        $original = \Carbon\CarbonImmutable::getTestNow();
        $start = \Carbon\CarbonImmutable::parse('2026-09-19 12:00:00', 'Africa/Cairo');
        try {
            foreach (range(0, 6) as $day) {
                \Carbon\CarbonImmutable::setTestNow($start->addDays($day));
                $result = app(CourseLeaderboardService::class)->forCourse($this->course->id);
                self::assertCount(3, $result['data']['best_students']);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow($original);
        }
    }

    private function plan(string $code, bool $projects): CourseAccessPlan
    {
        return $this->course->accessPlans()->create([
            'code' => $code, 'name_ar' => $code, 'price_coins' => 400, 'minimum_paid_coins' => 0,
            'projects_enabled' => $projects, 'certificate_enabled' => $projects,
            'chat_enabled' => false, 'project_feedback_level' => 'pass_only', 'is_active' => true,
            'max_output_tokens' => 260,
            'sort_order' => $projects ? 20 : 10,
        ]);
    }

    private function student(string $name): User
    {
        $student = new User();
        $student->forceFill([
            'name' => $name, 'name_ar' => $name, 'email' => $name.'@staff-progress.test',
            'role' => 'client', 'active' => true,
        ])->save();
        return $student;
    }

    private function enroll(User $user, CourseAccessPlan $plan, bool $legacy = false): CourseEnrollment
    {
        $receipt = app(CourseAccessPlanService::class)->snapshot($plan);
        if ($legacy) {
            $receipt['version'] = 5;
            $receipt['certificate_enabled'] = true;
            unset($receipt['projects_enabled']);
        }
        $order = Order::query()->create([
            'user_id' => $user->id, 'course_id' => $this->course->id,
            'access_plan_id' => $plan->id, 'access_plan_snapshot' => $receipt,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 400, 'discount_amount' => 0, 'final_amount' => 400,
            'total_coins' => 400, 'paid_coins' => 400, 'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill([
            'tenant_id' => 1,
            'user_id' => $user->id, 'course_id' => $this->course->id, 'order_id' => $order->id,
            'access_plan_id' => $plan->id, 'access_plan_order_id' => $order->id,
            'access_plan_snapshot' => $receipt, 'is_active' => true, 'enrolled_at' => now(),
        ])->save();
        return $enrollment;
    }
}
