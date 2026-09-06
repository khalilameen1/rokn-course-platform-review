<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\AdminCourseReportService;
use App\Services\CourseAccessPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminCourseAiReviewReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_project_review_is_counted_separately_from_plan_messages(): void
    {
        $student = User::query()->forceCreate([
            'name' => 'Student',
            'email' => 'student-review-report@example.test',
            'password' => 'unused',
            'role' => 'client',
            'active' => true,
        ]);
        $course = Course::query()->forceCreate([
            'tenant_id' => 1,
            'name_ar' => 'كورس',
            'price' => 400,
            'currency' => 'EGP',
        ]);
        $plan = CourseAccessPlan::query()->create([
            'course_id' => $course->id,
            'code' => CourseAccessPlan::BASIC,
            'name_ar' => 'التعلّم',
            'price_coins' => 400,
            'chat_enabled' => false,
            'project_feedback_level' => CourseAccessPlan::FEEDBACK_PASS_ONLY,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $enrollment = CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1,
            'user_id' => $student->id,
            'course_id' => $course->id,
            'access_plan_id' => $plan->id,
            'access_plan_snapshot' => app(CourseAccessPlanService::class)->snapshot($plan),
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
        ]);
        AiUsageEvent::query()->create([
            'request_id' => '44444444-4444-4444-8444-444444444444',
            'enrollment_id' => $enrollment->id,
            'access_plan_id' => $plan->id,
            'user_id' => $student->id,
            'course_id' => $course->id,
            'feature' => AiUsageEvent::FEATURE_PROJECT_REVIEW,
            'status' => 'completed',
            'total_tokens' => 180,
            'cost_usd' => 0.075,
            'metadata' => [
                'funding_source' => 'platform',
                'entitlement_delivered' => true,
                'cost_usage_source' => 'provider',
            ],
            'completed_at' => now(),
        ]);

        $stats = app(AdminCourseReportService::class)
            ->accessPlanStats($course->fresh())
            ->get(CourseAccessPlan::BASIC);

        self::assertSame(1, $stats['review_requests']);
        self::assertSame(0, $stats['project_requests']);
        self::assertSame(0, $stats['followup_requests']);
        self::assertSame(180, $stats['review_tokens']);
        self::assertSame(0.075, $stats['review_cost_usd']);
        self::assertSame(0, $stats['estimated_cost_requests']);
        self::assertSame(0, $stats['total_unanswered_requests']);
    }
}
