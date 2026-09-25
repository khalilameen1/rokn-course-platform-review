<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Order;
use App\Services\CommercialLearnerSummaryService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseCommercialReportService;
use App\Services\CourseCostReportService;
use App\Services\CoursePlanHistoryReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CoursePlanHistoryBoundaryTest extends TestCase
{
    private ReportPeriod $period;
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->period = ReportPeriod::fromKey('7d', CarbonImmutable::parse('2026-09-25T12:00:00Z'));
        $this->course = (new Course())->forceFill(['id' => 10]);
        // No live plan, enrollment, settings or provider-cost tables are present.
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->json('access_plan_snapshot')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->softDeletes();
        });
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->string('status');
            $table->unsignedInteger('total_tokens');
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->json('metadata');
            $table->timestamps();
        });
        foreach ([CourseCommercialReportService::class, CourseCostReportService::class, CourseAccessPlanService::class] as $service) {
            $this->app->bind($service, static function () use ($service): never {
                throw new \LogicException('History must not resolve ' . $service);
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('orders');
        parent::tearDown();
    }

    public function test_usage_keeps_the_accepted_snapshot_even_when_the_plan_identity_was_reused_and_the_order_retired(): void
    {
        $this->contract('guided', $this->period->start->subDay(), true);
        $this->contract('mentor', $this->period->start->addDays(2));
        $this->usage($this->period->start->addDay(), 0.02);
        $this->usage($this->period->start->addDays(3), 0.03);
        $this->usage($this->period->end, 99.0);

        $history = $this->history();
        self::assertSame(1, $history['guided']['period_metrics']['ai_requests']);
        self::assertSame(0.02, $history['guided']['period_metrics']['ai_cost_usd']);
        self::assertSame(1, $history['mentor']['period_metrics']['ai_requests']);
        self::assertSame(0.03, $history['mentor']['period_metrics']['ai_cost_usd']);
        self::assertTrue($history['guided']['historical_attribution_complete']);
        self::assertSame(0, $history['guided']['period_metrics']['students']);
    }

    public function test_missing_contract_marks_tier_usage_unknown_instead_of_assigning_it_to_the_current_tier(): void
    {
        $this->usage($this->period->start, 0.01);
        $history = $this->history();
        self::assertTrue($history->has('unattributed'));
        foreach ($history as $plan) {
            self::assertNull($plan['period_metrics']['ai_requests']);
            self::assertNull($plan['period_metrics']['ai_cost_usd']);
            self::assertFalse($plan['historical_attribution_complete']);
        }
    }

    public function test_failed_provider_charge_is_counted_but_undelivered_output_is_not_a_delivered_request(): void
    {
        $this->contract('guided', $this->period->start->subDay());
        $this->usage($this->period->start, 0.02, 'failed');
        $this->usage($this->period->start->addHour(), 0.03, 'completed', ['entitlement_delivered' => false]);
        $history = $this->history();
        self::assertSame(0, $history['guided']['period_metrics']['ai_requests']);
        self::assertSame(100, $history['guided']['period_metrics']['ai_tokens']);
        self::assertSame(0.05, $history['guided']['period_metrics']['ai_cost_usd']);
    }

    public function test_a_purchase_without_a_contract_does_not_create_known_tier_growth(): void
    {
        $order = (new Order())->forceFill(['id' => 1, 'user_id' => 1, 'access_plan_snapshot' => null]);
        $history = app(CoursePlanHistoryReportService::class)->forPeriod(
            $this->course, $this->period, collect([$order]), collect(),
            collect([1 => ['total_coins' => 20, 'paid_coins' => 0, 'reward_coins' => 20, 'complete' => true]]),
            $this->currentPlans(),
        );
        self::assertTrue($history->has('unattributed'));
        foreach ($history as $plan) {
            foreach (['students', 'paid_coins', 'reward_coins', 'cash_gross_egp', 'cash_net_egp'] as $metric) {
                self::assertNull($plan['period_metrics'][$metric]);
            }
            self::assertFalse($plan['historical_attribution_complete']);
        }
    }

    private function contract(string $code, CarbonImmutable $approved, bool $retired = false): void
    {
        DB::table('orders')->insert([
            'course_id' => 10, 'user_id' => 1, 'access_plan_id' => 7,
            'access_plan_snapshot' => json_encode(['code' => $code, 'name_ar' => $code]),
            'approved_at' => $approved,
            'deleted_at' => $retired ? $approved->addDay() : null,
        ]);
    }

    private function usage(CarbonImmutable $created, float $cost, string $status = 'completed', array $metadata = []): void
    {
        DB::table('ai_usage_events')->insert([
            'course_id' => 10, 'user_id' => 1, 'access_plan_id' => 7,
            'status' => $status, 'total_tokens' => 100, 'cost_usd' => $cost,
            'metadata' => json_encode(array_replace(['cost_usage_source' => 'provider'], $metadata)),
            'created_at' => $created, 'updated_at' => $created,
        ]);
    }

    private function currentPlans(): Collection
    {
        return collect(['mentor' => app(CommercialLearnerSummaryService::class)->forRows(collect()) + [
            'plan_code' => 'mentor', 'plan_name' => 'current tier',
        ]]);
    }

    private function history(): Collection
    {
        return app(CoursePlanHistoryReportService::class)->forPeriod(
            $this->course, $this->period, collect(), collect(), collect(), $this->currentPlans(),
        );
    }
}
