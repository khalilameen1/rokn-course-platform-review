<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class CourseStagedAccessPlanIdentityTest extends TestCase
{
    private bool $mysqlTransactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'mysql') {
            self::assertSame('testing', app()->environment());
            self::assertMatchesRegularExpression('/(?:^|_)test(?:_|$)/i', DB::connection()->getDatabaseName());
            DB::beginTransaction();
            $this->mysqlTransactionStarted = true;
            return;
        }
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);

        // The legacy migration installs these composite identities only on
        // MySQL. Install the same RESTRICT guard on the isolated SQLite schema
        // so the production graph-swap failure is actually exercised here.
        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach (['orders', 'course_enrollments', 'ai_usage_events'] as $table) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->foreign(['access_plan_id', 'course_id'])
                        ->references(['id', 'course_id'])->on('course_access_plans')
                        ->restrictOnUpdate()->restrictOnDelete();
                });
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->mysqlTransactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_repeated_publish_preserves_used_plan_identities_and_purchased_receipts(): void
    {
        $canonical = $this->publishedCourse();
        $plans = app(CourseAccessPlanService::class);
        $plans->createDefaults($canonical);
        $liveIds = $canonical->accessPlans()->pluck('id', 'code')->all();
        $guided = $canonical->accessPlans()->where('code', CourseAccessPlan::GUIDED)->firstOrFail();
        [$order, $enrollment, $event] = $this->purchasedPlan($canonical, $guided);
        $ledgerBefore = $this->ledgerRows();
        $receipt = $plans->termsForEnrollment($enrollment);

        $service = $this->serviceWithPassingAudit();
        foreach ([1, 2] as $publication) {
            $oldPlans = $this->offerRows($canonical);
            $draft = $service->draftFor($canonical->fresh());
            $draftIds = $draft->accessPlans()->pluck('id', 'code')->all();
            $draftGuided = $draft->accessPlans()->where('code', CourseAccessPlan::GUIDED)->firstOrFail();
            $draftGuided->update([
                'name_ar' => 'الفئة المنشورة '.$publication,
                'price_coins' => (int) $draftGuided->price_coins + 100,
                'chat_message_limit' => (int) $draftGuided->chat_message_limit + 10,
            ]);
            $draft->updateQuietly(['image' => 'images/new-cover-'.$publication.'.png']);
            $expectedPlans = $this->offerRows($draft);
            $identities = DB::table('course_access_plans')->orderBy('id')
                ->get(['id', 'course_id', 'code', 'created_at'])->toJson();

            $published = $service->publish($draft, (int) $draft->authoring_version, true);

            self::assertSame((int) $canonical->id, (int) $published['course']->id);
            self::assertSame($liveIds, $canonical->accessPlans()->pluck('id', 'code')->all());
            self::assertSame($draftIds, $draft->accessPlans()->pluck('id', 'code')->all());
            self::assertSame($expectedPlans, $this->offerRows($canonical));
            self::assertSame($oldPlans, $this->offerRows($draft));
            self::assertSame($identities, DB::table('course_access_plans')->orderBy('id')
                ->get(['id', 'course_id', 'code', 'created_at'])->toJson());
            self::assertStringEndsWith('new-cover-'.$publication.'.png', $canonical->fresh()->image);
            self::assertSame($ledgerBefore, $this->ledgerRows());
            self::assertSame($receipt, $plans->termsForEnrollment($enrollment->fresh()));
            self::assertSame((int) $guided->id, (int) $order->fresh()->access_plan_id);
            self::assertSame((int) $guided->id, (int) $event->fresh()->access_plan_id);
            self::assertSame((int) $canonical->id, (int) $guided->fresh()->course_id);
        }
        self::assertSame(2, CourseAuthoringRevision::query()->where('canonical_course_id', $canonical->id)
            ->where('status', CourseAuthoringRevision::ARCHIVED)->count());
    }

    public function test_publish_handles_sort_permutations_at_unsigned_smallint_boundary(): void
    {
        $canonical = $this->publishedCourse();
        app(CourseAccessPlanService::class)->createDefaults($canonical);
        foreach ($canonical->accessPlans()->orderBy('sort_order')->get() as $position => $plan) {
            $plan->update(['sort_order' => 65533 + $position]);
        }
        $service = $this->serviceWithPassingAudit();
        $draft = $service->draftFor($canonical);
        $oldPlans = $this->offerRows($canonical);
        foreach ($draft->accessPlans()->orderBy('id')->get() as $position => $plan) {
            $plan->update(['sort_order' => $position]);
        }
        foreach ($draft->accessPlans()->orderBy('id')->get() as $position => $plan) {
            $plan->update(['sort_order' => 65535 - $position]);
        }
        $expectedPlans = $this->offerRows($draft);

        $service->publish($draft, (int) $draft->authoring_version, true);

        self::assertSame($expectedPlans, $this->offerRows($canonical));
        self::assertSame($oldPlans, $this->offerRows($draft));
        self::assertSame(0, CourseAccessPlan::query()->whereIn('course_id', [$canonical->id, $draft->id])
            ->whereNotBetween('sort_order', [0, 65535])->count());
    }

    public function test_legacy_missing_live_plans_get_new_identities_without_moving_draft_rows(): void
    {
        $canonical = $this->publishedCourse();
        $service = $this->serviceWithPassingAudit();
        $draft = $service->draftFor($canonical);
        app(CourseAccessPlanService::class)->createDefaults($draft);
        $draftIds = $draft->accessPlans()->pluck('id', 'code')->all();
        $expectedPlans = $this->offerRows($draft);

        $service->publish($draft, (int) $draft->authoring_version, true);

        self::assertSame($expectedPlans, $this->offerRows($canonical));
        self::assertSame($draftIds, $draft->accessPlans()->pluck('id', 'code')->all());
        self::assertSame([], array_intersect($draftIds, $canonical->accessPlans()->pluck('id')->all()));
        self::assertSame(0, $draft->accessPlans()->where('is_active', true)->count());
        self::assertSame(3, $canonical->accessPlans()->where('is_active', true)->count());
    }

    public function test_failure_after_plan_publication_rolls_back_both_offers_and_ledger(): void
    {
        $canonical = $this->publishedCourse();
        app(CourseAccessPlanService::class)->createDefaults($canonical);
        $guided = $canonical->accessPlans()->where('code', CourseAccessPlan::GUIDED)->firstOrFail();
        $this->purchasedPlan($canonical, $guided);
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->once()->ordered()->andReturn(['ready' => true, 'issues' => []]);
        $publishing->shouldReceive('audit')->once()->ordered()->andThrow(new \RuntimeException('notification preparation unavailable'));
        $this->app->instance(CoursePublishingService::class, $publishing);
        $service = new CourseStagedAuthoringService($publishing);
        $draft = $service->draftFor($canonical);
        $draft->accessPlans()->where('code', CourseAccessPlan::GUIDED)->firstOrFail()->update(['name_ar' => 'تعديل غير منشور']);
        $plansBefore = DB::table('course_access_plans')->orderBy('id')->get()->toJson();
        $ledgerBefore = $this->ledgerRows();
        $coursesBefore = DB::table('courses')->orderBy('id')->get()->toJson();

        try {
            $service->publish($draft, (int) $draft->authoring_version, true);
            self::fail('The notification failure must roll back the publication.');
        } catch (\RuntimeException $exception) {
            self::assertSame('notification preparation unavailable', $exception->getMessage());
        }

        self::assertSame($plansBefore, DB::table('course_access_plans')->orderBy('id')->get()->toJson());
        self::assertSame($coursesBefore, DB::table('courses')->orderBy('id')->get()->toJson());
        self::assertSame($ledgerBefore, $this->ledgerRows());
        self::assertTrue($service->isManagedDraft($draft));
    }

    private function publishedCourse(): Course
    {
        return Course::query()->forceCreate([
            ...(Schema::hasColumn('courses', 'tenant_id') ? ['tenant_id' => 1] : []),
            'name_ar' => 'كورس منشور',
            'description_ar' => 'وصف الكورس',
            'price' => 400,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'authoring_version' => 4,
            'last_published_authoring_version' => 4,
            'published_at' => now(),
        ]);
    }

    private function serviceWithPassingAudit(): CourseStagedAuthoringService
    {
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->andReturn(['ready' => true, 'issues' => []]);
        return new CourseStagedAuthoringService($publishing);
    }

    /** @return array{Order,CourseEnrollment,AiUsageEvent} */
    private function purchasedPlan(Course $course, CourseAccessPlan $plan): array
    {
        $user = User::query()->forceCreate([
            'name' => 'Plan learner', 'email' => 'plan-learner@example.test',
            'password' => 'unused', 'role' => 'client', 'active' => true,
        ]);
        $snapshot = app(CourseAccessPlanService::class)->snapshot($plan);
        $order = Order::query()->create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'access_plan_id' => $plan->id, 'access_plan_snapshot' => $snapshot,
            'payment_method' => 'wallet_coins', 'amount' => $plan->price_coins,
            'discount_amount' => 0, 'final_amount' => $plan->price_coins,
            'total_coins' => $plan->price_coins, 'paid_coins' => $plan->price_coins,
            'reward_coins' => 0, 'status' => 'approved', 'financial_status' => 'settled',
            'approved_at' => now(),
        ]);
        $enrollment = CourseEnrollment::query()->forceCreate([
            ...(Schema::hasColumn('course_enrollments', 'tenant_id') ? ['tenant_id' => 1] : []),
            'user_id' => $user->id, 'course_id' => $course->id, 'order_id' => $order->id,
            'access_plan_id' => $plan->id, 'access_plan_order_id' => $order->id,
            'access_plan_snapshot' => $snapshot, 'enrolled_at' => now(),
            'is_active' => true, 'access_granted_at' => now(),
        ]);
        DB::table('ai_entitlement_usages')->insert([
            'enrollment_id' => $enrollment->id, 'access_plan_id' => $plan->id,
            'feature' => 'course_chat', 'used_requests' => 1, 'used_tokens' => 100,
            'used_cost_usd' => 0.005, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $event = AiUsageEvent::query()->create([
            'request_id' => '77777777-7777-4777-8777-777777777777',
            'enrollment_id' => $enrollment->id, 'access_plan_id' => $plan->id,
            'user_id' => $user->id, 'course_id' => $course->id,
            'feature' => 'course_chat', 'status' => 'completed',
            'total_tokens' => 100, 'cost_usd' => 0.005, 'completed_at' => now(),
            'metadata' => ['cost_usage_source' => 'provider'],
        ]);
        return [$order, $enrollment, $event];
    }

    private function offerRows(Course $course): array
    {
        return $course->accessPlans()->orderBy('code')->get()->mapWithKeys(
            fn (CourseAccessPlan $plan): array => [$plan->code => collect($plan->getAttributes())
                ->except(['id', 'course_id', 'code', 'created_at', 'updated_at'])->all()]
        )->all();
    }

    private function ledgerRows(): array
    {
        return collect(['orders', 'course_enrollments', 'ai_usage_events', 'ai_entitlement_usages'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])
            ->all();
    }
}
