<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CourseController;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Tests\TestCase;

final class CourseAccessPlanEditorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_course_defaults_are_previewed_without_writing_commercial_rows(): void
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس قديم',
            'price' => 1750,
            'authoring_version' => 9,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ])->save();

        self::assertSame(0, $course->accessPlans()->count());

        $plans = app(CourseAccessPlanService::class)->plansForEditor($course);

        self::assertSame(CourseAccessPlan::CODES, $plans->pluck('code')->all());
        self::assertSame(1750, (int) $plans->firstWhere('code', CourseAccessPlan::BASIC)?->price_coins);
        self::assertTrue($plans->every(fn (CourseAccessPlan $plan): bool => !$plan->exists));
        self::assertSame(0, $course->accessPlans()->count());
        self::assertSame(9, (int) $course->fresh()->authoring_version);
    }

    public function test_existing_plan_rows_are_returned_without_replacing_their_values(): void
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس بفئات',
            'price' => 900,
            'authoring_version' => 4,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ])->save();

        app(CourseAccessPlanService::class)->createDefaults($course);
        $guided = $course->accessPlans()->where('code', CourseAccessPlan::GUIDED)->firstOrFail();
        $guided->forceFill([
            'name_ar' => 'فئة مخصصة',
            'price_coins' => 4321,
        ])->save();

        $plans = app(CourseAccessPlanService::class)->plansForEditor($course);
        $returnedGuided = $plans->firstWhere('code', CourseAccessPlan::GUIDED);

        self::assertTrue((bool) $returnedGuided?->exists);
        self::assertSame('فئة مخصصة', $returnedGuided?->name_ar);
        self::assertSame(4321, (int) $returnedGuided?->price_coins);
        self::assertSame(4, (int) $course->fresh()->authoring_version);
    }

    public function test_course_save_clears_dormant_ai_budgets_without_editing_the_global_policy(): void
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس بفئات قديمة',
            'price' => 900,
            'authoring_version' => 4,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ])->save();

        $service = app(CourseAccessPlanService::class);
        $service->createDefaults($course);
        $guided = $course->accessPlans()->where('code', CourseAccessPlan::GUIDED)->firstOrFail();
        $guided->forceFill([
            'chat_enabled' => false,
            'chat_message_limit' => 0,
            'chat_token_budget' => 0,
            'ai_budget_usd' => 9,
            'request_reserve_usd' => 1,
            'project_feedback_level' => CourseAccessPlan::FEEDBACK_PASS_ONLY,
            'project_feedback_token_budget' => 0,
            'project_feedback_budget_usd' => 8,
            'project_feedback_reserve_usd' => 1,
            'project_followup_message_limit' => 0,
            'project_followup_token_budget' => 0,
            'project_followup_budget_usd' => 7,
            'project_followup_reserve_usd' => 1,
        ])->save();

        $input = $service->plansForEditor($course)->mapWithKeys(
            fn (CourseAccessPlan $plan): array => [
                $plan->code => [
                    'name_ar' => $plan->name_ar,
                    'name_en' => $plan->name_en,
                    'price_coins' => $plan->price_coins,
                    'minimum_paid_coins' => $plan->minimum_paid_coins,
                    'is_active' => $plan->is_active,
                    'certificate_enabled' => $plan->certificate_enabled,
                ],
            ]
        )->all();
        $input[CourseAccessPlan::BASIC]['price_coins'] = 1250;

        $service->syncAdminPlans($course, $input);

        $guided->refresh();
        self::assertSame('0.000000', (string) $guided->ai_budget_usd);
        self::assertSame('0.000000', (string) $guided->request_reserve_usd);
        self::assertSame('0.000000', (string) $guided->project_feedback_budget_usd);
        self::assertSame('0.000000', (string) $guided->project_feedback_reserve_usd);
        self::assertSame('0.000000', (string) $guided->project_followup_budget_usd);
        self::assertSame('0.000000', (string) $guided->project_followup_reserve_usd);
        self::assertSame(1250, (int) $course->fresh()->price);
    }

    public function test_opening_course_editor_does_not_persist_missing_plans_or_advance_revision(): void
    {
        $moderator = new User();
        $moderator->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'moderator-editor@example.test',
            'role' => 'moderator',
            'active' => true,
            'social_provider' => 'google',
            'social_id' => 'moderator-editor',
        ])->save();
        $this->actingAs($moderator);

        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس بلا فئات',
            'price' => 2100,
            'authoring_version' => 12,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ])->save();

        $response = app(CourseController::class)->edit($course);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(route('admin.courses.show', $course), $response->getTargetUrl());
        self::assertSame(0, $course->accessPlans()->count());
        self::assertSame(12, (int) $course->fresh()->authoring_version);
    }
}
