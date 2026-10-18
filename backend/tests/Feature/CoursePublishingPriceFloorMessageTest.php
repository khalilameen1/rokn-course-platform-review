<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Services\CoursePlanEconomicsService;
use App\Services\CoursePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CoursePublishingPriceFloorMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'course_plans.economics_configured' => true,
            'course_plans.net_usd_per_paid_coin' => 0.01,
            'course_plans.ai_cost_safety_multiplier' => 2,
            'course_plans.target_contribution_margin_percent' => 40,
        ]);
    }

    public function test_blocker_names_the_minimum_price_from_the_same_evaluation(): void
    {
        $terms = ['delivery_cost_usd' => 3.6, 'price_coins' => 590, 'minimum_paid_coins' => 590];
        $quote = app(CoursePlanEconomicsService::class)->evaluate($terms);
        self::assertFalse($quote['meets_floor']);
        $issues = $this->planIssues([
            CourseAccessPlan::BASIC => $terms,
            CourseAccessPlan::GUIDED => ['delivery_cost_usd' => 0, 'price_coins' => 900, 'minimum_paid_coins' => 900],
            CourseAccessPlan::MENTOR => ['delivery_cost_usd' => 0, 'price_coins' => 1000, 'minimum_paid_coins' => 1000],
        ]);

        $blocker = collect($issues)->first(fn (string $issue): bool => str_contains($issue, 'لا تغطي التكلفة'));
        self::assertNotNull($blocker);
        self::assertStringContainsString('«basic»', $blocker);
        self::assertStringContainsString("السعر {$quote['required_price_coins']} عملة على الأقل", $blocker);
        self::assertStringContainsString("الحد المدفوع {$quote['required_paid_coins']} عملة على الأقل", $blocker);
    }

    public function test_blocker_only_names_the_term_that_is_below_the_floor(): void
    {
        $quote = app(CoursePlanEconomicsService::class)->evaluate(['delivery_cost_usd' => 3.6, 'price_coins' => 0]);
        $terms = [
            'delivery_cost_usd' => 3.6,
            'price_coins' => $quote['required_price_coins'],
            'minimum_paid_coins' => $quote['required_paid_coins'] - 1,
        ];
        $issues = $this->planIssues([
            CourseAccessPlan::BASIC => $terms,
            CourseAccessPlan::GUIDED => ['delivery_cost_usd' => 0, 'price_coins' => 5000, 'minimum_paid_coins' => 5000],
            CourseAccessPlan::MENTOR => ['delivery_cost_usd' => 0, 'price_coins' => 6000, 'minimum_paid_coins' => 6000],
        ]);

        $blocker = collect($issues)->first(fn (string $issue): bool => str_contains($issue, 'لا تغطي التكلفة'));
        self::assertNotNull($blocker);
        self::assertStringNotContainsString('اجعل السعر', $blocker);
        self::assertStringContainsString("اجعل الحد المدفوع {$quote['required_paid_coins']} عملة على الأقل", $blocker);
    }

    /** @param array<string, array<string, mixed>> $terms @return list<string> */
    private function planIssues(array $terms): array
    {
        $plans = collect($terms)->map(fn (array $values, string $code): CourseAccessPlan => (new CourseAccessPlan)->forceFill([
            'code' => $code, 'name_ar' => $code, 'is_active' => true,
            'chat_enabled' => false, 'project_feedback_level' => CourseAccessPlan::FEEDBACK_PASS_ONLY,
            ...$values,
        ]))->values();
        $course = new Course;
        $course->setRelation('accessPlans', $plans);
        $issues = [];
        $warnings = [];
        $audit = new \ReflectionMethod(CoursePublishingService::class, 'auditAccessPlans');
        $audit->invokeArgs(app(CoursePublishingService::class), [$course, &$issues, &$warnings]);

        return $issues;
    }
}
