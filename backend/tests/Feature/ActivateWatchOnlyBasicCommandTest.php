<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class ActivateWatchOnlyBasicCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_is_a_read_only_preview(): void
    {
        $course = $this->course();
        $this->passingAudit();
        $before = $this->databaseFacts();
        $this->artisan('courses:activate-watch-only-basic', ['course' => $course->id, '--expected-version' => 4])
            ->expectsOutputToContain('Dry run only')->assertExitCode(0);
        self::assertSame($before, $this->databaseFacts());
    }

    public function test_an_explicit_positive_expected_version_is_required(): void
    {
        $course = $this->course();
        $before = $this->databaseFacts();
        $this->artisan('courses:activate-watch-only-basic', ['course' => $course->id, '--apply' => true])
            ->expectsOutputToContain('are required')->assertExitCode(1);
        self::assertSame($before, $this->databaseFacts());
    }

    public function test_existing_draft_is_never_adopted_or_published(): void
    {
        $course = $this->course();
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        $draft->update(['name_ar' => 'مسودة تخص المحرر']);
        $before = $this->databaseFacts();
        $this->artisan('courses:activate-watch-only-basic', ['course' => $course->id, '--expected-version' => 4, '--apply' => true])
            ->expectsOutputToContain('existing working draft')->assertExitCode(1);
        self::assertSame($before, $this->databaseFacts());
    }

    public function test_stale_version_is_rejected_without_creating_a_draft(): void
    {
        $course = $this->course();
        $before = $this->databaseFacts();
        $this->artisan('courses:activate-watch-only-basic', ['course' => $course->id, '--expected-version' => 3, '--apply' => true])
            ->expectsOutputToContain('version changed')->assertExitCode(1);
        self::assertSame($before, $this->databaseFacts());
    }

    public function test_only_new_basic_capabilities_change_and_old_receipts_remain_identical(): void
    {
        $course = $this->course();
        [$order, $enrollment] = $this->legacyPurchase($course);
        $orderBefore = $order->fresh()->getAttributes();
        $enrollmentBefore = $enrollment->fresh()->getAttributes();
        $higherBefore = $this->higherOffers($course);
        $prices = $course->accessPlans()->pluck('price_coins', 'id')->all();
        $basic = $course->accessPlans()->where('code', 'basic')->firstOrFail();
        $basicId = $basic->id;
        $paidFloor = $basic->minimum_paid_coins;
        $this->passingAudit();

        $this->artisan('courses:activate-watch-only-basic', ['course' => $course->id, '--expected-version' => 4, '--apply' => true])
            ->expectsOutputToContain('published; authoring version 6')
            ->expectsOutputToContain('notification processing completed')->assertExitCode(0);

        $basic->refresh();
        self::assertSame($basicId, $basic->id);
        self::assertFalse($basic->projects_enabled);
        self::assertFalse($basic->certificate_enabled);
        self::assertFalse($basic->chat_enabled);
        self::assertFalse($basic->chat_attachments_enabled);
        self::assertFalse($basic->project_followup_attachments_enabled);
        self::assertFalse($basic->project_output_enabled);
        self::assertSame('pass_only', $basic->project_feedback_level);
        foreach (['chat_message_limit', 'chat_token_budget', 'chat_attachment_max_files', 'ai_budget_usd', 'request_reserve_usd',
            'project_feedback_token_budget', 'project_feedback_budget_usd', 'project_feedback_reserve_usd',
            'project_followup_message_limit', 'project_followup_token_budget', 'project_followup_budget_usd',
            'project_followup_reserve_usd', 'project_followup_attachment_max_files'] as $field) {
            self::assertSame(0.0, (float) $basic->{$field}, $field);
        }
        self::assertSame($paidFloor, $basic->minimum_paid_coins);
        self::assertSame($prices, $course->accessPlans()->pluck('price_coins', 'id')->all());
        self::assertSame($higherBefore, $this->higherOffers($course));
        self::assertSame($orderBefore, $order->fresh()->getAttributes());
        self::assertSame($enrollmentBefore, $enrollment->fresh()->getAttributes());
        self::assertTrue(app(CourseAccessPlanService::class)->projectsEnabledForEnrollment($enrollment->fresh()));
        self::assertTrue(app(CourseAccessPlanService::class)->termsForEnrollment($enrollment->fresh())['certificate_enabled']);
        self::assertFalse(app(CourseAccessPlanService::class)->snapshot($basic)['projects_enabled']);
        self::assertTrue($course->fresh()->is_main_course);
        self::assertFalse($course->fresh()->is_catalog_visible);
        self::assertSame(400, (int) $course->fresh()->price);
        self::assertSame(6, $course->fresh()->last_published_authoring_version);
        self::assertSame(0, CourseAuthoringRevision::query()->where('status', CourseAuthoringRevision::DRAFT)->count());
    }

    public function test_repeated_run_is_a_no_op_with_no_second_publication(): void
    {
        $course = $this->course();
        $this->passingAudit();
        $this->artisan('courses:activate-watch-only-basic', ['course' => $course->id, '--expected-version' => 4, '--apply' => true])->assertExitCode(0);
        $before = $this->databaseFacts();
        $this->artisan('courses:activate-watch-only-basic', ['course' => $course->id, '--expected-version' => 6, '--apply' => true])
            ->expectsOutputToContain('already watch-only')->assertExitCode(0);
        self::assertSame($before, $this->databaseFacts());
    }

    public function test_publication_health_failure_rolls_back_new_draft_and_all_changes(): void
    {
        $course = $this->course();
        $this->legacyPurchase($course);
        $audit = Mockery::mock(CoursePublishingService::class);
        $audit->shouldReceive('audit')->once()->ordered()->andReturn(['ready' => true, 'issues' => [], 'warnings' => []]);
        $audit->shouldReceive('audit')->once()->ordered()->andReturn(['ready' => false, 'issues' => ['محتوى الكورس غير جاهز'], 'warnings' => []]);
        $this->app->instance(CoursePublishingService::class, $audit);
        $before = $this->databaseFacts();
        $this->artisan('courses:activate-watch-only-basic', ['course' => $course->id, '--expected-version' => 4, '--apply' => true])
            ->expectsOutputToContain('محتوى الكورس غير جاهز')->assertExitCode(1);
        self::assertSame($before, $this->databaseFacts());
    }

    public function test_real_publication_audit_is_not_bypassed_for_incomplete_content(): void
    {
        $course = $this->course();
        $before = $this->databaseFacts();
        $this->artisan('courses:activate-watch-only-basic', ['course' => $course->id, '--expected-version' => 4, '--apply' => true])->assertExitCode(1);
        self::assertSame($before, $this->databaseFacts());
    }

    private function course(): Course
    {
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس منشور', 'description_ar' => 'وصف الكورس', 'price' => 400,
            'is_coming_soon' => false, 'is_catalog_visible' => false, 'is_main_course' => true,
            'authoring_version' => 4, 'last_published_authoring_version' => 4, 'published_at' => now(),
        ]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'مقطع', 'duration_minutes' => 1]);
        CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id, 'title_ar' => 'مقطع', 'section_type' => 'lesson',
            'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id, 'order' => 1,
        ]);
        app(CourseAccessPlanService::class)->createDefaults($course);
        $course->accessPlans()->where('code', 'basic')->update([
            'projects_enabled' => true, 'certificate_enabled' => true,
            'minimum_paid_coins' => 100,
            'chat_enabled' => true, 'chat_message_limit' => 5, 'chat_token_budget' => 1000,
            'chat_attachments_enabled' => true, 'chat_attachment_max_files' => 1,
            'ai_budget_usd' => .1, 'request_reserve_usd' => .01,
        ]);
        // Intentionally different from global defaults: the rollout must not
        // silently resynchronize higher plans, prices or sort positions.
        $course->accessPlans()->where('code', 'guided')->update(['name_ar' => 'Plus خاص', 'chat_message_limit' => 41, 'sort_order' => 41]);
        $course->accessPlans()->where('code', 'mentor')->update(['name_ar' => 'Pro خاص', 'chat_message_limit' => 121, 'sort_order' => 51]);
        return $course->fresh();
    }

    private function passingAudit(): void
    {
        $audit = Mockery::mock(CoursePublishingService::class);
        $audit->shouldReceive('audit')->andReturn(['ready' => true, 'issues' => [], 'warnings' => []]);
        $this->app->instance(CoursePublishingService::class, $audit);
    }

    private function legacyPurchase(Course $course): array
    {
        $plan = $course->accessPlans()->where('code', 'basic')->firstOrFail();
        $snapshot = app(CourseAccessPlanService::class)->snapshot($plan);
        $snapshot['version'] = 5;
        unset($snapshot['projects_enabled']);
        $user = User::query()->forceCreate(['name' => 'Legacy learner', 'email' => 'legacy-basic@example.test', 'password' => 'unused', 'role' => 'client', 'active' => true]);
        $order = Order::query()->create([
            'user_id' => $user->id, 'course_id' => $course->id, 'access_plan_id' => $plan->id, 'access_plan_snapshot' => $snapshot,
            'payment_method' => 'wallet_coins', 'amount' => 400, 'discount_amount' => 0, 'final_amount' => 400,
            'total_coins' => 400, 'paid_coins' => 400, 'reward_coins' => 0,
            'status' => 'approved', 'financial_status' => 'settled', 'approved_at' => now(),
        ]);
        $enrollment = CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id, 'order_id' => $order->id,
            'access_plan_id' => $plan->id, 'access_plan_order_id' => $order->id, 'access_plan_snapshot' => $snapshot,
            'enrolled_at' => now(), 'is_active' => true, 'access_granted_at' => now(),
        ]);
        return [$order, $enrollment];
    }

    private function higherOffers(Course $course): array
    {
        return $course->accessPlans()->where('code', '<>', 'basic')->orderBy('code')->get()
            ->map(fn ($plan): array => collect($plan->getAttributes())->except(['updated_at'])->all())->all();
    }

    private function databaseFacts(): array
    {
        return collect(['courses', 'course_access_plans', 'course_authoring_revisions', 'course_authoring_revision_entities', 'lessons', 'course_modules', 'course_sections', 'orders', 'course_enrollments', 'notification_campaigns'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
