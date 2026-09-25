<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CoinEarningMethodController;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\Setting;
use App\Services\CourseAccessPlanService;
use App\Services\CoursePlanEconomicsService;
use App\Support\CourseAccessPlanSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CourseCheckoutPolicyEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_basic_is_watch_only_with_an_explicit_v6_receipt(): void
    {
        $course = $this->course();
        $plans = app(CourseAccessPlanService::class);
        app(\App\Services\CoursePlanAuthoringService::class)->createDefaults($course);
        $basic = $course->accessPlans()->where('code', 'basic')->firstOrFail();
        $snapshot = $plans->snapshot($basic);

        self::assertFalse($basic->projects_enabled);
        self::assertFalse($basic->certificate_enabled);
        self::assertFalse($basic->chat_enabled);
        self::assertSame(6, $snapshot['version']);
        self::assertFalse($snapshot['projects_enabled']);
        CourseAccessPlanSnapshot::assertValidForPlan($basic->id, $snapshot);
        self::assertFalse($plans->publicPayload($basic)['projects_enabled']);
    }

    public function test_editing_legacy_basic_changes_future_offer_not_legacy_receipt(): void
    {
        $course = $this->course();
        $plans = app(CourseAccessPlanService::class);
        app(\App\Services\CoursePlanAuthoringService::class)->createDefaults($course);
        $basic = $course->accessPlans()->where('code', 'basic')->firstOrFail();
        $basic->update(['projects_enabled' => true, 'certificate_enabled' => true]);
        $legacy = $plans->snapshot($basic);
        $legacy['version'] = 5;
        unset($legacy['projects_enabled']);
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill(['access_plan_id' => $basic->id, 'access_plan_snapshot' => $legacy]);

        app(\App\Services\CoursePlanAuthoringService::class)->syncAdminPlans($course, $this->input($course));

        self::assertFalse($basic->fresh()->projects_enabled);
        self::assertFalse($basic->fresh()->certificate_enabled);
        self::assertSame($legacy, $plans->termsForEnrollment($enrollment));
        self::assertTrue($plans->projectsEnabledForEnrollment($enrollment));
        self::assertTrue($plans->publicPayloadFromTerms($legacy)['certificate_enabled']);
        self::assertTrue($plans->publicPayloadFromTerms($legacy)['projects_enabled']);
    }

    public function test_missing_cost_or_unverified_net_value_is_not_treated_as_zero(): void
    {
        config(['course_plans.economics_configured' => true]);
        $service = app(CoursePlanEconomicsService::class);
        $unknownCost = $service->evaluate(['price_coins' => 1000], 20);
        self::assertFalse($unknownCost['configured']);
        self::assertNull($unknownCost['required_price_coins']);
        config(['course_plans.economics_configured' => false]);
        $unknownNet = $service->evaluate(['delivery_cost_usd' => 0], 20);
        self::assertFalse($unknownNet['configured']);
        self::assertNull($unknownNet['fully_loaded_cost_usd']);
    }

    public function test_full_cost_floor_accounts_for_promotion_and_margin_once(): void
    {
        config([
            'course_plans.economics_configured' => true,
            'course_plans.net_usd_per_paid_coin' => 0.01,
            'course_plans.ai_cost_safety_multiplier' => 2,
            'course_plans.target_contribution_margin_percent' => 40,
        ]);
        $result = app(CoursePlanEconomicsService::class)->evaluate([
            'delivery_cost_usd' => 1.5,
            'chat_enabled' => true,
            'ai_budget_usd' => 0.8,
            'project_feedback_level' => 'report',
            'project_feedback_budget_usd' => 0.25,
            'project_followup_budget_usd' => 99, // Dormant costs do not count.
            'price_coins' => 750,
            'minimum_paid_coins' => 600,
        ], 20);

        self::assertTrue($result['configured']);
        self::assertEqualsWithDelta(3.6, $result['fully_loaded_cost_usd'], 0.000001);
        self::assertSame(600, $result['required_paid_coins']);
        self::assertSame(750, $result['required_price_coins']);
        self::assertTrue($result['meets_floor']);
    }

    public function test_configured_plan_cannot_be_saved_below_its_financial_floor(): void
    {
        config([
            'course_plans.economics_configured' => true,
            'course_plans.net_usd_per_paid_coin' => 0.01,
            'course_plans.ai_cost_safety_multiplier' => 2,
        ]);
        $this->expectException(ValidationException::class);
        app(CoursePlanEconomicsService::class)->assertCommercialFloor([
            'delivery_cost_usd' => 3.6,
            'price_coins' => 749,
            'minimum_paid_coins' => 600,
        ], 'basic');
    }

    public function test_strict_mode_rejects_unknown_cost_instead_of_claiming_margin(): void
    {
        config(['course_plans.enforce_commercial_floor' => true]);
        $this->expectException(ValidationException::class);
        app(CoursePlanEconomicsService::class)->assertCommercialFloor(['price_coins' => 100], 'basic');
    }

    public function test_commercial_mode_blocks_an_existing_uncosted_offer_without_leaking_internal_costs(): void
    {
        $course = $this->course();
        $service = app(CourseAccessPlanService::class);
        app(\App\Services\CoursePlanAuthoringService::class)->createDefaults($course);
        config(['course_plans.enforce_commercial_floor' => true]);
        try {
            $service->selectedPlan($course, 'basic');
            self::fail('An uncosted existing offer must not bypass commercial activation.');
        } catch (ValidationException $exception) {
            self::assertSame([
                'access_plan_code' => ['هذا الاشتراك غير متاح للشراء الآن جرّب لاحقًا'],
            ], $exception->errors());
        }
    }

    public function test_promotion_percentage_is_independent_of_legacy_coin_amount(): void
    {
        Setting::query()->create([
            'max_reward_contribution_per_course' => 20,
            'max_course_promotion_percent' => 15,
        ]);
        self::assertSame(15, app(CoursePlanEconomicsService::class)->promotionPercent());
    }

    public function test_dashboard_rejects_percentage_above_approved_twenty_percent_ceiling(): void
    {
        $this->expectException(ValidationException::class);
        app(CoinEarningMethodController::class)->updateSettings(Request::create('/', 'POST', [
            'reward_balance_cap' => 1200,
            'max_reward_contribution_per_course' => 20,
            'max_course_promotion_percent' => 21,
            'recommended_social_provider' => 'google',
            'recommended_provider_bonus_coins' => 0,
            'editor_version' => str_repeat('0', 64),
        ]));
    }

    public function test_editor_renders_explicit_legacy_offer_notice_and_unknown_cost_state(): void
    {
        $course = $this->course();
        app(\App\Services\CoursePlanAuthoringService::class)->createDefaults($course);
        $course->accessPlans()->where('code', 'basic')->update([
            'projects_enabled' => true, 'certificate_enabled' => true,
        ]);
        $html = view('admin.courses.partials.edit.access-plans', [
            'course' => $course->load('accessPlans'),
            'canViewCommercialReport' => true,
            'planStats' => collect(),
            'enableEnglish' => false,
        ])->render();

        self::assertStringContainsString('اشتراكات الكورس', $html);
        self::assertStringContainsString('سيصبح Basic للمشاهدة فقط', $html);
        self::assertStringContainsString('التسعير غير معتمد', $html);
        self::assertStringNotContainsString('name="access_plans[basic][certificate_enabled]" value="1"', $html);
        self::assertStringContainsString('name="access_plans[guided][delivery_cost_usd]"', $html);
    }

    public function test_snapshot_schema_preserves_all_historical_branches_and_adds_v6(): void
    {
        $oldMigration = require database_path('migrations/2026_09_05_000002_allow_current_access_plan_snapshots.php');
        $newMigration = require database_path('migrations/2026_09_15_000002_allow_watch_only_access_plan_snapshots.php');
        $oldSchema = json_decode((new \ReflectionMethod($oldMigration, 'snapshotJsonSchema'))->invoke($oldMigration), true);
        $newSchema = json_decode((new \ReflectionMethod($newMigration, 'snapshotJsonSchema'))->invoke($newMigration), true);
        self::assertSame($oldSchema['oneOf'], array_slice($newSchema['oneOf'], 0, 5));
        self::assertSame([1, 2, 3, 4, 5, 6], $newSchema['properties']['version']['enum']);
        self::assertContains('projects_enabled', $newSchema['oneOf'][5]['required']);
        self::assertSame(['type' => 'boolean'], $newSchema['oneOf'][5]['properties']['projects_enabled']);
    }

    public function test_policy_migration_can_resume_partial_ddl_without_resetting_existing_values(): void
    {
        $course = $this->course();
        app(\App\Services\CoursePlanAuthoringService::class)->createDefaults($course);
        $basic = $course->accessPlans()->where('code', 'basic')->firstOrFail();
        $setting = Setting::firstOrCreate([]);
        $setting->update(['max_course_promotion_percent' => 15]);

        // Simulate the first two MySQL DDL statements having committed before
        // an interruption. Only the final nullable cost column is missing.
        \Illuminate\Support\Facades\Schema::table('course_access_plans', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropColumn('delivery_cost_usd');
        });
        $migration = require database_path('migrations/2026_09_15_000001_add_course_checkout_policy_fields.php');
        $migration->up();
        $migration->up();

        self::assertSame(15, $setting->fresh()->max_course_promotion_percent);
        self::assertFalse($basic->fresh()->projects_enabled);
        self::assertNull($basic->fresh()->delivery_cost_usd);
    }

    private function course(): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس تجريبي', 'price' => 900,
            'authoring_version' => 1, 'is_coming_soon' => true, 'is_catalog_visible' => false,
        ])->save();
        return $course;
    }

    private function input(Course $course): array
    {
        return $course->accessPlans()->get()->mapWithKeys(fn (CourseAccessPlan $plan): array => [
            $plan->code => [
                'name_ar' => $plan->name_ar, 'name_en' => $plan->name_en,
                'price_coins' => $plan->price_coins, 'minimum_paid_coins' => $plan->minimum_paid_coins,
                'is_active' => true, 'certificate_enabled' => $plan->certificate_enabled,
            ],
        ])->all();
    }
}
