<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AiProviderExposureLimitReachedException;
use App\Models\AiEntitlementUsage;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\InternalSignal;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AiEntitlementBudgetService;
use App\Services\AiUsageSettlementService;
use App\Services\CourseAccessPlanService;
use App\Services\CoursePlanAuthoringService;
use App\Services\FinancialAnomalyService;
use App\Services\OpenRouterService;
use App\Services\PaidAiCallExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AiUsageResponsibilityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_settlement_does_not_resolve_admission_or_provider_and_replays_without_another_debit(): void
    {
        [$enrollment, $usage, $event] = $this->reservation();
        DB::table('settings')->insert(['openrouter_usd_to_egp_rate' => 50]);
        foreach ([AiEntitlementBudgetService::class, CourseAccessPlanService::class,
            FinancialAnomalyService::class, OpenRouterService::class, PaidAiCallExecutionService::class] as $dependency) {
            $this->app->bind($dependency, static fn () => throw new \LogicException('Unexpected settlement dependency: '.$dependency));
        }
        $result = $this->providerResult();
        $settlements = app(AiUsageSettlementService::class);
        self::assertSame(AiUsageSettlementService::SETTLEMENT_ACCEPTED,
            $settlements->settleForActiveUser($event, $result, $enrollment->user_id));
        self::assertSame(AiUsageSettlementService::SETTLEMENT_ALREADY_ACCEPTED,
            $settlements->settleForActiveUser($event, $result, $enrollment->user_id));
        $fresh = $usage->fresh();
        self::assertSame(1, $fresh->used_requests);
        self::assertSame(0, $fresh->reserved_requests);
        self::assertSame(80, $fresh->used_tokens);
        self::assertSame('0.012500', $fresh->used_cost_usd);
        self::assertSame('0.625000', $event->fresh()->cost_egp);
        self::assertSame('50.0000', $event->fresh()->fx_rate_to_egp);
        self::assertSame(1, InternalSignal::query()->where('type', 'ai_usage.settled')->count());
        Http::assertNothingSent();
    }

    #[DataProvider('providerCostFacts')]
    public function test_explicit_zero_and_missing_provider_cost_have_different_settlement_meanings(array $cost, string $expected, string $source): void
    {
        [, $usage, $event] = $this->reservation();
        app(AiUsageSettlementService::class)->settle($event, ['message' => 'answer', 'usage' => $cost]);
        self::assertSame($expected, $event->fresh()->cost_usd);
        self::assertSame($expected, $usage->fresh()->used_cost_usd);
        self::assertSame($source, data_get($event->fresh()->metadata, 'cost_usage_source'));
    }

    public static function providerCostFacts(): array
    {
        return [
            'known zero' => [['cost' => 0, 'cost_reported' => true], '0.000000', 'provider'],
            'unknown cost' => [['cost' => 0, 'cost_reported' => false], '0.020000', 'reservation_fallback'],
        ];
    }

    public function test_event_aggregate_and_signal_share_one_transaction(): void
    {
        [, $usage, $event] = $this->reservation();
        $beforeEvent = $event->fresh()->getAttributes();
        $beforeUsage = $usage->fresh()->getAttributes();
        InternalSignal::creating(static function (InternalSignal $signal): void {
            if ($signal->type === 'ai_usage.settled') throw new \RuntimeException('signal write failed');
        });
        try {
            app(AiUsageSettlementService::class)->settle($event, $this->providerResult());
            self::fail('A failed durable signal must roll back settlement.');
        } catch (\RuntimeException $error) {
            self::assertSame('signal write failed', $error->getMessage());
        }
        self::assertSame($beforeEvent, $event->fresh()->getAttributes());
        self::assertSame($beforeUsage, $usage->fresh()->getAttributes());
    }

    public function test_expiry_records_unknown_cost_but_preserves_an_already_landed_paid_result(): void
    {
        [$enrollment, $usage, $unknown] = $this->reservation();
        $unknown->forceFill(['created_at' => now()->subHour(),
            'reservation_expires_at' => now()->subMinutes(30),
            'metadata' => ['provider_call_state' => 'started']])->save();
        $landed = $unknown->replicate(['request_id']);
        $landed->request_id = (string) Str::uuid();
        $landed->metadata = ['provider_call_state' => PaidAiCallExecutionService::LANDED,
            'provider_success_landing' => $this->providerResult()];
        $landed->save();
        $landed->forceFill(['created_at' => now()->subHour()])->save();
        $usage->forceFill(['reserved_requests' => 2, 'reserved_tokens' => 200,
            'reserved_cost_usd' => '0.040000'])->save();
        $budget = app(AiEntitlementBudgetService::class);
        self::assertSame(1, $budget->releaseExpiredReservations());
        self::assertSame(0, $budget->releaseExpiredReservations());
        self::assertSame('completed', $unknown->fresh()->status);
        self::assertFalse(data_get($unknown->fresh()->metadata, 'entitlement_delivered'));
        self::assertSame('0.020000', $unknown->fresh()->cost_usd);
        self::assertSame('reserved', $landed->fresh()->status);
        self::assertSame($this->providerResult(), data_get($landed->fresh()->metadata, 'provider_success_landing'));
        self::assertSame(0, $usage->fresh()->used_requests);
        self::assertSame(1, $usage->fresh()->reserved_requests);
        self::assertSame('0.020000', $usage->fresh()->reserved_cost_usd);
        self::assertSame(1, $usage->fresh()->unanswered_provider_requests);
    }

    public function test_unknown_outcome_releases_allowance_and_the_exposure_pause_recovers_after_cooldown(): void
    {
        [$enrollment, $usage, $event] = $this->reservation(true);
        config(['course_plans.ai_unanswered_provider_request_limit' => 1,
            'course_plans.ai_provider_exposure_cooldown_seconds' => 60]);
        $settlements = app(AiUsageSettlementService::class);
        $settlements->settleUnknown($event, []);
        $settlements->settleUnknown($event, []);
        self::assertSame(0, $usage->fresh()->used_requests);
        self::assertSame(0, $usage->fresh()->reserved_requests);
        self::assertSame('0.000000', $usage->fresh()->used_cost_usd);
        self::assertSame('0.020000', $event->fresh()->cost_usd);
        self::assertSame(1, InternalSignal::query()->where('type', 'ai_usage.threshold')->count());
        $budget = app(AiEntitlementBudgetService::class);
        try {
            $budget->reserve($enrollment, 'course_chat', 100, 'test/model', (string) Str::uuid());
            self::fail('The exposure cooldown must block another provider attempt.');
        } catch (AiProviderExposureLimitReachedException) {
            self::assertSame(1, AiUsageEvent::query()->count());
        }
        $this->travel(61)->seconds();
        $next = $budget->reserve($enrollment, 'course_chat', 100, 'test/model', (string) Str::uuid());
        self::assertSame('reserved', $next->status);
        self::assertNull($usage->fresh()->provider_exposure_paused_until);
        self::assertSame(0, $usage->fresh()->unanswered_provider_requests);
    }

    public function test_a_result_after_entitlement_replacement_records_cost_without_spending_the_new_allowance(): void
    {
        [$enrollment, $usage, $event] = $this->reservation();
        $event->forceFill(['metadata' => ['provider_call_state' => 'started']])->save();
        app(AiEntitlementBudgetService::class)->cancelOutstandingReservations($enrollment, 'entitlement_replaced');
        self::assertTrue(data_get($event->fresh()->metadata, 'reservation_detached'));
        $before = $usage->fresh()->getAttributes();
        $outcome = app(AiUsageSettlementService::class)->settleForActiveUser($event, $this->providerResult(), $enrollment->user_id);
        self::assertSame(AiUsageSettlementService::SETTLEMENT_TERMINAL_CONFLICT, $outcome);
        self::assertSame($before, $usage->fresh()->getAttributes());
        self::assertSame('0.012500', $event->fresh()->cost_usd);
        self::assertArrayNotHasKey('accepted_response', $event->fresh()->metadata);
    }

    private function providerResult(): array
    {
        return ['message' => 'A completed answer', 'provider_request_id' => 'provider-one',
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 30,
                'total_tokens' => 80, 'cost' => '0.012500', 'cost_reported' => true]];
    }

    /** @return array{CourseEnrollment, AiEntitlementUsage, AiUsageEvent} */
    private function reservation(bool $withPlan = false): array
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'Settlement fixture',
            'price' => 900, 'authoring_version' => 1, 'is_coming_soon' => true, 'is_catalog_visible' => false]);
        $user = User::query()->forceCreate(['name' => 'Learner', 'email' => Str::uuid().'@example.test',
            'password' => 'unused', 'role' => 'client', 'active' => true]);
        $terms = [];
        if ($withPlan) {
            app(CoursePlanAuthoringService::class)->createDefaults($course);
            $plan = $course->accessPlans()->where('code', CourseAccessPlan::MENTOR)->sole();
            $snapshot = app(CourseAccessPlanService::class)->snapshot($plan);
            $terms = ['access_plan_id' => $plan->id, 'access_plan_snapshot' => $snapshot];
        }
        $amount = $withPlan ? (int) $plan->price_coins : 900;
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id,
            'payment_method' => 'wallet_coins', 'amount' => $amount, 'final_amount' => $amount,
            'discount_amount' => 0, 'total_coins' => $amount, 'paid_coins' => $amount, 'reward_coins' => 0,
            'status' => 'approved', 'financial_status' => 'settled', 'approved_at' => now()] + $terms);
        if ($withPlan) {
            $transaction = WalletTransaction::query()->create(['public_id' => (string) Str::uuid(),
                'user_id' => $user->id, 'direction' => 'debit', 'category' => 'course_purchase', 'bucket' => 'paid',
                'amount' => $amount, 'paid_amount' => $amount, 'reward_amount' => 0, 'balance_after' => 0,
                'paid_balance_after' => 0, 'reward_balance_after' => 0, 'source_type' => Course::class,
                'source_id' => $course->id, 'idempotency_key' => 'boundary:'.$order->id,
                'metadata' => ['order_id' => $order->id], 'occurred_at' => now()]);
            $order->forceFill(['wallet_transaction_id' => $transaction->id])->save();
        }
        $enrollment = CourseEnrollment::query()->forceCreate(['tenant_id' => 1,
            'user_id' => $user->id, 'course_id' => $course->id, 'order_id' => $order->id,
            'access_plan_order_id' => $order->id, 'enrolled_at' => now(),
            'is_active' => true, 'access_granted_at' => now()] + $terms);
        $usage = AiEntitlementUsage::query()->create(['enrollment_id' => $enrollment->id,
            'access_plan_id' => $enrollment->access_plan_id, 'feature' => 'course_chat',
            'reserved_requests' => 1, 'reserved_tokens' => 100, 'reserved_cost_usd' => '0.020000',
            'used_requests' => 0, 'used_tokens' => 0, 'used_cost_usd' => '0.000000']);
        $event = AiUsageEvent::query()->create(['request_id' => (string) Str::uuid(),
            'enrollment_id' => $enrollment->id, 'access_plan_id' => $enrollment->access_plan_id,
            'user_id' => $user->id, 'course_id' => $course->id, 'feature' => 'course_chat',
            'model' => 'test/model', 'status' => 'reserved', 'reserved_tokens' => 100,
            'reserved_cost_usd' => '0.020000', 'reservation_expires_at' => now()->addHour()]);
        return [$enrollment, $usage, $event];
    }
}
