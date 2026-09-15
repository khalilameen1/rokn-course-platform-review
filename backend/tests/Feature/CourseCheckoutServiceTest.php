<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseCheckout;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Coupon;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Package;
use App\Models\Setting;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CourseCheckoutService;
use App\Services\FinancialProvenanceService;
use App\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CourseCheckoutServiceTest extends TestCase
{
    use RefreshDatabase;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-15T12:00:00Z');
        if (Schema::hasColumn('bills', 'tenant_id')) Bill::creating(static fn (Bill $row) => $row->setAttribute('tenant_id', 1));
        if (Schema::hasColumn('course_enrollments', 'tenant_id')) CourseEnrollment::creating(static fn (CourseEnrollment $row) => $row->setAttribute('tenant_id', 1));
        Setting::query()->create(['site_name_ar' => 'Rokn', 'max_course_promotion_percent' => 20]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_read_only_status_does_not_authorize_or_spend(): void
    {
        [$user, $course, $service] = $this->fixture(500, 200);
        $quote = $service->create($user, $this->input($course));
        self::assertSame('quoted', $quote['status']);
        self::assertSame(80, $quote['allocation']['reward_coins']);
        self::assertSame(320, $quote['allocation']['paid_coins']);
        self::assertSame('quoted', $service->show($user, $quote['id'])['status']);
        self::assertSame(0, CourseEnrollment::query()->count());
        self::assertSame(700, (int) $user->fresh()->wallet_coins);
    }

    public function test_authorization_and_resume_are_idempotent_and_use_existing_ledger(): void
    {
        [$user, $course, $service] = $this->fixture(500, 200);
        $quote = $service->create($user, $this->input($course));
        $first = $service->authorize($user, $quote['id']);
        self::assertSame('completed', $first['status'], json_encode($first));
        $again = $service->resume($user, $quote['id']);
        self::assertSame($first['purchase']['order_id'], $again['purchase']['order_id']);
        self::assertSame(1, Order::query()->where('course_id', $course->id)->count());
        self::assertSame(180, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(120, (int) $user->fresh()->wallet_reward_coins);
    }

    public function test_selected_topup_preserves_rewards_that_do_not_reduce_cash(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $package = $this->storePackage(350);
        $quote = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        self::assertSame(350, $quote['deficit']);
        self::assertSame(['paid_coins' => 350, 'reward_coins' => 50], $quote['allocation']);
        self::assertSame(150, $quote['remaining_reward_balance']);
        self::assertSame('pending_payment', $service->authorize($user, $quote['id'])['status']);
        self::assertSame(0, CourseEnrollment::query()->count());
        $receipt = $this->fund($user, $package);
        $complete = $service->bindStorePurchase($user, $quote['id'], $receipt);
        self::assertSame('completed', $complete['status'], json_encode($complete));
        self::assertSame(150, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame('completed', $service->bindStorePurchase($user, $quote['id'], $receipt)['status']);
        self::assertSame(1, CourseEnrollment::query()->count());
    }

    public function test_large_package_does_not_burn_any_rewards(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $package = $this->storePackage(900);
        $quote = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        self::assertSame(0, $quote['allocation']['reward_coins']);
        self::assertSame(400, $quote['allocation']['paid_coins']);
        self::assertSame(400, $quote['deficit']);
        self::assertSame(500, $quote['remaining_purchased_balance']);
    }

    public function test_unbound_quote_keeps_all_reward_eligible_packages_for_native_price_selection(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $small = $this->storePackage(320);
        $large = $this->storePackage(900);
        // Catalogue price order need not match the native store's localized
        // prices. Do not preselect the large package and hide the small one.
        $large->forceFill(['price' => 50])->save();
        $quote = $service->create($user, $this->input($course));
        self::assertNull($quote['selected_package']);
        self::assertSame(['paid_coins' => 320, 'reward_coins' => 80], $quote['allocation']);
        self::assertSame(320, $quote['deficit']);
        self::assertSame([(int) $large->id, (int) $small->id], array_column($quote['recommended_packages'], 'id'));
        try {
            $service->authorize($user, $quote['id']);
            self::fail('A native funding package must be bound before authorization');
        } catch (\DomainException $exception) {
            self::assertSame('checkout_package_required', $exception->getMessage());
        }
        self::assertSame('quoted', $service->show($user, $quote['id'])['status']);
        self::assertSame(200, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(0, CourseEnrollment::query()->count());

        $bound = $service->create($user, $this->input($course) + ['package_id' => $small->id]);
        self::assertSame(320, $bound['deficit']);
        self::assertSame(80, $bound['allocation']['reward_coins']);
        self::assertSame('pending_payment', $service->authorize($user, $bound['id'])['status']);
    }

    public function test_http_reviewer_wallet_quote_has_consistent_deficit_before_and_after_package_binding(): void
    {
        [$user, $course] = $this->fixture(20, 30);
        $this->withoutMiddleware(\App\Http\Middleware\WebsiteVisitorCount::class);
        $package = $this->storePackage(900);
        foreach (['basic' => 400, 'guided' => 650] as $code => $price) {
            $input = array_replace($this->input($course), ['access_plan_code' => $code]);
            $unbound = $this->actingAs($user, 'api')->postJson('/api/v1/course-checkouts', $input)->assertOk()->json('data');
            self::assertNull($unbound['selected_package']);
            self::assertSame($price - 50, $unbound['deficit']);
            self::assertSame(30, $unbound['allocation']['reward_coins']);

            $bound = $this->postJson('/api/v1/course-checkouts', $input + ['package_id' => $package->id])->assertOk()->json('data');
            self::assertSame(0, $bound['allocation']['reward_coins']);
            self::assertSame($price, $bound['allocation']['paid_coins']);
            self::assertSame($price - 20, $bound['deficit']);
            self::assertSame(920 - $price, $bound['remaining_purchased_balance']);
            self::assertSame(30, $bound['remaining_reward_balance']);
            foreach ([$unbound, $bound] as $snapshot) {
                self::assertSame($snapshot['final_price'], array_sum($snapshot['allocation']));
                self::assertSame(max(0, $snapshot['allocation']['paid_coins'] - $snapshot['purchased_balance']), $snapshot['deficit']);
                self::assertSame($snapshot['final_price'] + $snapshot['discount_amount'], $snapshot['original_price']);
            }
            $this->getJson('/api/v1/course-checkouts/'.$bound['id'])->assertOk()
                ->assertJsonPath('data.deficit', $price - 20);
        }
        self::assertSame(20, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(30, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(0, CourseEnrollment::query()->count());
    }

    public function test_cancelled_or_expired_authorization_never_auto_spends_late_funding(): void
    {
        foreach (['cancelled', 'expired'] as $state) {
            [$user, $course, $service] = $this->fixture(0, 200);
            $package = $this->storePackage(350);
            $quote = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
            $service->authorize($user, $quote['id']);
            if ($state === 'cancelled') $service->cancel($user, $quote['id']);
            else $this->travel(16)->minutes();
            $receipt = $this->fund($user, $package);
            self::assertSame($state, $service->bindStorePurchase($user, $quote['id'], $receipt)['status']);
            self::assertSame(350, (int) $user->fresh()->wallet_purchased_coins);
            self::assertFalse(CourseEnrollment::query()->where('user_id', $user->id)->exists());
            $this->travelBack();
        }
    }

    public function test_price_revision_change_after_payment_requires_new_consent(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $package = $this->storePackage(350);
        $quote = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        $service->authorize($user, $quote['id']);
        $course->forceFill(['authoring_version' => 2, 'last_published_authoring_version' => 2])->save();
        $receipt = $this->fund($user, $package);
        self::assertSame('reconfirm_required', $service->bindStorePurchase($user, $quote['id'], $receipt)['status']);
        self::assertSame(350, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(0, CourseEnrollment::query()->count());
    }

    public function test_stale_store_receipt_is_credit_only_not_new_checkout_authorization(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $package = $this->storePackage(350);
        $quote = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        $service->authorize($user, $quote['id']);
        $receipt = $this->fund($user, $package);
        $receipt->forceFill(['provider_payload' => ['purchase_completed_at' => now()->subHour()->toIso8601String()]])->save();
        $result = $service->bindStorePurchase($user, $quote['id'], $receipt);
        self::assertSame('checkout_receipt_not_bound', $result['error_code']);
        self::assertSame(0, CourseEnrollment::query()->count());
    }

    public function test_upgrade_charges_only_the_plan_difference_from_purchased_coins(): void
    {
        [$user, $course, $service] = $this->fixture(1000, 500);
        Coupon::query()->forceCreate(['name_ar' => 'Discount', 'name_en' => 'Discount', 'code' => 'HALFPRICE', 'balance' => 50, 'active' => true]);
        $quote = $service->create($user, $this->input($course) + ['coupon_code' => 'HALFPRICE']);
        self::assertSame(80, $quote['discount_amount']);
        self::assertSame(0, $quote['allocation']['reward_coins']);
        self::assertSame('completed', $service->authorize($user, $quote['id'])['status']);
        $upgrade = $service->create($user, ['course_id' => $course->id, 'access_plan_code' => 'guided', 'mode' => 'upgrade', 'channel' => 'google']);
        self::assertSame(130, $upgrade['promotion']['cap']);
        self::assertSame(80, $upgrade['promotion']['used']);
        self::assertSame(250, $upgrade['final_price']);
        self::assertSame(['paid_coins' => 250, 'reward_coins' => 0], $upgrade['allocation']);
        self::assertSame('completed', $service->authorize($user, $upgrade['id'])['status']);
        self::assertSame(500, (int) $user->fresh()->wallet_reward_coins);
    }

    private function fixture(int $paid, int $reward): array
    {
        $user = $this->user(); $course = $this->course(true); $this->plans($course);
        if ($paid > 0) $this->creditPaid($user, $paid);
        if ($reward > 0) $this->creditReward($user, $reward);
        return [$user->fresh(), $course, app(CourseCheckoutService::class)];
    }

    public function test_http_gets_are_read_only_and_another_account_cannot_resume(): void
    {
        [$user, $course] = $this->fixture(500, 200);
        $this->withoutMiddleware(\App\Http\Middleware\WebsiteVisitorCount::class);
        $quote = $this->actingAs($user, 'api')->postJson('/api/v1/course-checkouts', $this->input($course))->assertOk()->json('data');
        $this->getJson('/api/v1/course-checkouts/'.$quote['id'])->assertOk()->assertJsonPath('data.status', 'quoted');
        self::assertSame(0, CourseEnrollment::query()->count());
        $other = $this->user();
        $this->actingAs($other, 'api')->postJson('/api/v1/course-checkouts/'.$quote['id'].'/resume')->assertNotFound();
        $this->actingAs($user, 'api')->postJson('/api/v1/course-checkouts/'.$quote['id'].'/authorize')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson('/api/v1/course-checkouts/'.$quote['id'].'/authorize')->assertOk()->assertJsonPath('data.status', 'completed');
        self::assertSame(1, CourseEnrollment::query()->count());
    }

    public function test_legacy_purchase_can_use_rewards_but_upgrade_quote_cannot(): void
    {
        [$user, $course] = $this->fixture(1000, 500);
        $this->withoutMiddleware(\App\Http\Middleware\WebsiteVisitorCount::class);
        $this->actingAs($user, 'api')->postJson('/api/v1/courses/authorize', [
            'course_id' => $course->id, 'access_plan_code' => 'basic', 'idempotency_key' => 'legacy-new-policy-0001',
        ])->assertOk()->assertJsonPath('data.reward_contribution_cap_per_course', 80)
            ->assertJsonPath('data.reward_contribution_remaining_for_course', 0);
        $this->getJson('/api/v1/courses/'.$course->id.'/full-track-upgrade?target_plan_code=guided')
            ->assertOk()->assertJsonPath('data.reward_contribution_cap_per_course', 0)
            ->assertJsonPath('data.reward_contribution_remaining_for_course', 0);
    }

    public function test_verified_store_endpoint_explicitly_fulfills_authorized_checkout_once(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $this->withoutMiddleware(\App\Http\Middleware\WebsiteVisitorCount::class);
        $package = $this->storePackage(350);
        $quote = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        $service->authorize($user, $quote['id']);
        $binding = app(\App\Services\StoreBillingAccountIdentity::class)->google($user);
        $gateway = $this->mock(\App\Contracts\StorePurchaseProviderGateway::class);
        $gateway->shouldReceive('verify')->once()->andReturn(new \App\Data\VerifiedStorePurchase(
            provider: 'google', productId: $package->google_product_id, externalTransactionId: 'GPA-checkout-test',
            environment: 'test', auditPayload: ['purchase_completed_at' => now()->addSecond()->toIso8601String()], accountBinding: $binding));
        $gateway->shouldReceive('consumeGoogle')->andReturnNull();
        $body = ['provider' => 'google', 'product_id' => $package->google_product_id, 'purchase_token' => 'isolated-checkout-test-token', 'checkout_id' => $quote['id']];
        $first = $this->actingAs($user, 'api')->postJson('/api/v1/store-purchases/verify', $body)->assertOk();
        $first->assertJsonPath('data.credited', true)->assertJsonPath('data.checkout.status', 'completed')
            ->assertJsonPath('data.wallet.purchased_balance', 0)->assertJsonPath('data.wallet.reward_balance', 150);
        $this->postJson('/api/v1/store-purchases/verify', $body)->assertOk()->assertJsonPath('data.checkout.status', 'completed');
        self::assertSame(1, CourseEnrollment::query()->count());
        self::assertSame(150, (int) $user->fresh()->wallet_reward_coins);
    }

    public function test_direct_pending_funding_does_not_enroll_until_verified_approval(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $package = $this->storePackage(350);
        $package->forceFill(['direct_enabled' => true])->save();
        $input = $this->input($course); $input['channel'] = 'direct'; $input['package_id'] = $package->id;
        $quote = $service->create($user, $input);
        $service->authorize($user, $quote['id']);
        $order = Order::query()->create(['user_id' => $user->id, 'package_id' => $package->id, 'package_coins' => 350,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER, 'amount' => 90, 'final_amount' => 90,
            'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING]);
        self::assertSame('pending_payment', $service->bindFundingOrder($user, $quote['id'], $order)['status']);
        self::assertSame(0, CourseEnrollment::query()->count());
        app(\App\Services\OrderLifecycleService::class)->approve($order, null, null, true);
        $service->resumeFundingOrder($order->fresh());
        self::assertSame('completed', $service->show($user, $quote['id'])['status']);
        self::assertSame(1, CourseEnrollment::query()->count());
        self::assertSame('completed', $service->bindFundingOrder($user, $quote['id'], $order->fresh())['status']);
        self::assertSame(1, CourseEnrollment::query()->count());
    }

    public function test_google_signed_profile_is_recovered_without_device_checkout_id(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $this->withoutMiddleware(\App\Http\Middleware\WebsiteVisitorCount::class);
        $package = $this->storePackage(350);
        $quote = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        $service->authorize($user, $quote['id']);
        $binding = app(\App\Services\StoreBillingAccountIdentity::class)->google($user);
        $gateway = $this->mock(\App\Contracts\StorePurchaseProviderGateway::class);
        $gateway->shouldReceive('verify')->once()->andReturn(new \App\Data\VerifiedStorePurchase(
            provider: 'google', productId: $package->google_product_id, externalTransactionId: 'GPA-profile-recovery',
            environment: 'test', auditPayload: ['purchase_completed_at' => now()->addSecond()->toIso8601String(),
                'checkout_profile_id' => $quote['id']], accountBinding: $binding));
        $gateway->shouldReceive('consumeGoogle')->andReturnNull();
        $this->actingAs($user, 'api')->postJson('/api/v1/store-purchases/verify', [
            'provider' => 'google', 'product_id' => $package->google_product_id, 'purchase_token' => 'profile-recovery-test-token',
        ])->assertOk()->assertJsonPath('data.credited', true);
        // The RefreshDatabase outer transaction defers afterCommit callbacks;
        // exercise the same persistent profile recovery used after a crash.
        $this->artisan('courses:resume-checkouts')->assertExitCode(0);
        self::assertSame('completed', $service->show($user, $quote['id'])['status']);
        $this->artisan('courses:resume-checkouts')->assertExitCode(0);
        self::assertSame(1, CourseEnrollment::query()->count());
    }

    public function test_direct_http_binding_replays_completed_order_and_wrong_owner_rolls_back_new_order(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $this->withoutMiddleware([\App\Http\Middleware\AppFrontNameSpace::class, \App\Http\Middleware\WebsiteVisitorCount::class]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $package = $this->storePackage(350);
        $package->forceFill(['direct_enabled' => true])->save();
        $input = $this->input($course); $input['channel'] = 'direct'; $input['package_id'] = $package->id;
        $quote = $service->create($user, $input);
        $service->authorize($user, $quote['id']);
        $payload = ['package_id' => $package->id, 'expected_coins' => 350, 'expected_amount' => 90,
            'course_checkout_id' => $quote['id'], 'idempotency_key' => 'direct-checkout-contract-test-0001'];
        $first = $this->actingAs($user, 'api')->postJson('/api/v1/payment/initiate', $payload)->assertOk();
        $order = Order::query()->where('order_ref', $first->json('data.order_ref'))->firstOrFail();
        app(\App\Services\OrderLifecycleService::class)->approve($order, null, null, true);
        $service->resumeFundingOrder($order->fresh());
        $this->postJson('/api/v1/payment/initiate', $payload)->assertOk();
        self::assertSame(1, CourseEnrollment::query()->count());
        self::assertSame(1, Order::query()->where('package_id', $package->id)->count());
        $other = $this->user();
        $payload['idempotency_key'] = 'different-owner-direct-attempt-0001';
        $this->actingAs($other, 'api')->postJson('/api/v1/payment/initiate', $payload)
            ->assertForbidden()->assertJsonPath('code', 'course_checkout_unavailable');
        self::assertFalse(Order::query()->where('user_id', $other->id)->exists());
    }

    public function test_changed_capabilities_same_price_and_revision_invalidate_consent(): void
    {
        [$user, $course, $service] = $this->fixture(500, 200);
        $quote = $service->create($user, $this->input($course));
        $course->accessPlans()->where('code', 'basic')->update(['certificate_enabled' => false]);
        self::assertSame('reconfirm_required', $service->authorize($user, $quote['id'])['status']);
        self::assertSame(700, (int) $user->fresh()->wallet_coins);
        self::assertSame(0, CourseEnrollment::query()->count());
    }

    public function test_paid_floor_can_reduce_promotional_reward_allowance(): void
    {
        [$user, $course, $service] = $this->fixture(500, 200);
        $course->accessPlans()->where('code', 'basic')->update(['minimum_paid_coins' => 390]);
        $quote = $service->create($user, $this->input($course));
        self::assertSame(['paid_coins' => 390, 'reward_coins' => 10], $quote['allocation']);
        self::assertSame('completed', $service->authorize($user, $quote['id'])['status']);
        self::assertSame(110, (int) $user->fresh()->wallet_purchased_coins);
    }

    public function test_reward_heavy_old_receipt_cannot_upgrade_below_target_paid_floor(): void
    {
        [$user, $course, $service] = $this->fixture(2000, 500);
        $this->withoutMiddleware(\App\Http\Middleware\WebsiteVisitorCount::class);
        $course->accessPlans()->where('code', 'basic')->update(['price_coins' => 900]);
        $course->accessPlans()->where('code', 'mentor')->update(['price_coins' => 1000, 'minimum_paid_coins' => 950]);
        $quote = $service->create($user, $this->input($course));
        self::assertSame(720, $quote['allocation']['paid_coins']);
        self::assertSame('completed', $service->authorize($user, $quote['id'])['status']);
        $balance = (int) $user->fresh()->wallet_coins;
        $this->actingAs($user, 'api')->getJson('/api/v1/courses/'.$course->id.'/full-track-upgrade?target_plan_code=mentor')
            ->assertStatus(409)->assertJsonPath('code', 'full_track_upgrade_paid_floor_unfunded');
        $this->postJson('/api/v1/courses/'.$course->id.'/full-track-upgrade', [
            'target_plan_code' => 'mentor', 'expected_price' => 100, 'idempotency_key' => 'unfunded-upgrade-test-0001',
        ])->assertStatus(409)->assertJsonPath('code', 'full_track_upgrade_paid_floor_unfunded');
        $this->postJson('/api/v1/course-checkouts', ['course_id' => $course->id, 'access_plan_code' => 'mentor', 'mode' => 'upgrade', 'channel' => 'google'])
            ->assertStatus(409)->assertJsonPath('code', 'full_track_upgrade_paid_floor_unfunded');
        self::assertSame($balance, (int) $user->fresh()->wallet_coins);
        self::assertSame('basic', CourseEnrollment::query()->where('user_id', $user->id)->firstOrFail()->access_plan_snapshot['code']);
        self::assertSame(1, Order::query()->where('course_id', $course->id)->count());
    }

    public function test_projects_only_higher_plan_is_upgradeable_but_basic_is_never_an_upgrade(): void
    {
        [$user, $course, $service] = $this->fixture(2000, 500);
        $this->withoutMiddleware(\App\Http\Middleware\WebsiteVisitorCount::class);
        $course->accessPlans()->where('code', 'basic')->update(['projects_enabled' => false, 'certificate_enabled' => false]);
        $course->accessPlans()->where('code', 'guided')->update(['chat_enabled' => false, 'chat_message_limit' => 0,
            'chat_token_budget' => 0, 'ai_budget_usd' => 0, 'request_reserve_usd' => 0,
            'projects_enabled' => true, 'certificate_enabled' => true]);
        $base = $service->create($user, $this->input($course));
        self::assertSame('completed', $service->authorize($user, $base['id'])['status']);
        $this->actingAs($user, 'api')->getJson('/api/v1/courses/'.$course->id.'/full-track-upgrade?target_plan_code=guided')
            ->assertOk()->assertJsonPath('data.target_plan_code', 'guided');
        $upgrade = $service->create($user, ['course_id' => $course->id, 'access_plan_code' => 'guided', 'mode' => 'upgrade', 'channel' => 'google']);
        self::assertSame('completed', $service->authorize($user, $upgrade['id'])['status']);
        $this->postJson('/api/v1/course-checkouts', ['course_id' => $course->id, 'access_plan_code' => 'basic', 'mode' => 'upgrade', 'channel' => 'google'])
            ->assertStatus(409)->assertJsonPath('code', 'full_track_upgrade_not_available');
        self::assertSame('guided', CourseEnrollment::query()->where('user_id', $user->id)->firstOrFail()->access_plan_snapshot['code']);
    }

    public function test_an_existing_pending_intent_is_not_silently_replaced(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $package = $this->storePackage(350);
        $first = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        $second = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        $service->authorize($user, $first['id']);
        try {
            $service->authorize($user, $second['id']);
            self::fail('A second payment authorization must require explicit cancellation');
        } catch (\DomainException $exception) {
            self::assertSame('checkout_already_pending', $exception->getMessage());
        }
        self::assertSame('pending_payment', $service->show($user, $first['id'])['status']);
        self::assertSame('quoted', $service->show($user, $second['id'])['status']);
        self::assertSame(0, CourseEnrollment::query()->count());
    }

    public function test_pending_lookup_and_cross_course_conflict_keep_recovery_visible_to_its_owner(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $this->withoutMiddleware(\App\Http\Middleware\WebsiteVisitorCount::class);
        $package = $this->storePackage(350);
        $first = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        $service->authorize($user, $first['id']);
        $newer = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        CourseCheckout::query()->where('public_id', $newer['id'])->firstOrFail()->forceFill(['status' => 'reconfirm_required'])->save();
        self::assertSame($first['id'], $service->latest($user, $course->id)['id']);
        $otherCourse = $this->course(true); $this->plans($otherCourse);
        $other = $service->create($user, $this->input($otherCourse) + ['package_id' => $package->id]);
        $this->actingAs($user, 'api')->postJson('/api/v1/course-checkouts/'.$other['id'].'/authorize')
            ->assertStatus(409)->assertJsonPath('data.active_checkout.id', $first['id'])
            ->assertJsonPath('data.active_checkout.course_id', (int) $course->id);
        $this->travel(16)->minutes();
        self::assertSame($newer['id'], $service->latest($user, $course->id)['id']);
        $this->travelBack();
    }

    public function test_reversed_funding_never_auto_enrolls_and_inactive_accounts_never_auto_spend(): void
    {
        [$user, $course, $service] = $this->fixture(0, 200);
        $package = $this->storePackage(350);
        $quote = $service->create($user, $this->input($course) + ['package_id' => $package->id]);
        $service->authorize($user, $quote['id']);
        $receipt = $this->fund($user, $package);
        $receipt->order->forceFill(['financial_status' => Order::FINANCIAL_REFUNDED, 'reversed_at' => now()])->save();
        self::assertSame('checkout_funding_not_effective', $service->bindStorePurchase($user, $quote['id'], $receipt)['error_code']);
        self::assertSame(0, CourseEnrollment::query()->count());
        [$other, $otherCourse] = $this->fixture(500, 200);
        $otherQuote = $service->create($other, $this->input($otherCourse));
        $other->forceFill(['active' => false])->save();
        self::assertSame('reconfirm_required', $service->authorize($other, $otherQuote['id'])['status']);
        self::assertSame(700, (int) $other->fresh()->wallet_coins);
    }

    private function input(Course $course): array
    {
        return ['course_id' => $course->id, 'access_plan_code' => 'basic', 'mode' => 'purchase', 'channel' => 'google'];
    }

    private function storePackage(int $coins): Package
    {
        return Package::query()->create(['name_ar' => 'Funding', 'name_en' => 'Funding', 'coins' => $coins,
            'price' => 100, 'is_active' => true, 'google_enabled' => true, 'google_product_id' => 'rokn.test.'.$this->key('package')]);
    }

    private function fund(User $user, Package $package): StorePurchase
    {
        $order = Order::query()->create(['user_id' => $user->id, 'package_id' => $package->id, 'package_coins' => $package->coins,
            'payment_method' => Order::PAYMENT_METHOD_GOOGLE_PLAY, 'amount' => 100, 'final_amount' => 100,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now()]);
        $credit = app(WalletService::class)->credit($user->id, $package->coins, 'package_purchase', $this->key('fund'), $order, [], WalletTransaction::BUCKET_PAID);
        app(FinancialProvenanceService::class)->recordPaidPackageCredit($order, $credit);
        return StorePurchase::query()->create(['public_id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id,
            'package_id' => $package->id, 'order_id' => $order->id, 'provider' => 'google', 'product_id' => $package->google_product_id,
            'external_transaction_id' => $this->key('transaction'), 'purchase_token_hash' => hash('sha256', $this->key('token')), 'purchase_token' => $this->key('encrypted'),
            'environment' => 'test', 'status' => 'credited', 'verified_at' => now(),
            'provider_payload' => ['purchase_completed_at' => now()->addSecond()->toIso8601String()]]);
    }

    private function user(): User
    {
        return User::query()->forceCreate([
            'name' => 'Reward cap learner',
            'email' => 'reward-cap-' . (++$this->sequence) . '@example.test',
            'phone' => '0100000' . str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'role' => 'client',
            'gender' => 'other',
            'active' => true,
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
    }

    private function course(bool $withSection): Course
    {
        $course = Course::query()->forceCreate([
            'tenant_id' => 1,
            'name_ar' => 'Reward cap course',
            'name_en' => 'Reward cap course',
            'description_ar' => 'Test course',
            'description_en' => 'Test course',
            'course_type' => 'online',
            'price' => 40,
            'is_main_course' => true,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'ai_chat_enabled' => true,
        ]);

        if ($withSection) {
            $module = CourseModule::query()->create([
                'course_id' => $course->id,
                'title_ar' => 'Test module',
                'order' => 1,
            ]);
            $lesson = Lesson::query()->create([
                'list_id' => $course->id,
                'title_ar' => 'Test reel',
                'duration_minutes' => 1,
            ]);
            CourseSection::query()->create([
                'course_id' => $course->id,
                'module_id' => $module->id,
                'title_ar' => 'Test section',
                'title_en' => 'Test section',
                'section_type' => 'lesson',
                'sectionable_type' => Lesson::class,
                'sectionable_id' => $lesson->id,
                'order' => 1,
            ]);
        }

        return $course;
    }

    private function plans(Course $course): void
    {
        foreach ([
            [
                'code' => 'basic', 'name_ar' => 'Basic', 'price_coins' => 400,
                'minimum_paid_coins' => 0,
                'chat_enabled' => false, 'chat_message_limit' => 0, 'chat_token_budget' => 0,
                'ai_budget_usd' => 0, 'request_reserve_usd' => 0,
                'project_feedback_token_budget' => 0,
                'project_feedback_budget_usd' => 0, 'project_feedback_reserve_usd' => 0,
                'project_followup_message_limit' => 0, 'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => 0, 'project_followup_reserve_usd' => 0,
                'max_output_tokens' => 260, 'project_feedback_level' => 'pass_only',
                'project_output_enabled' => false, 'certificate_enabled' => true,
                'is_active' => true, 'sort_order' => 10,
            ],
            [
                'code' => 'guided', 'name_ar' => 'Guided', 'price_coins' => 650,
                'minimum_paid_coins' => 1,
                'chat_enabled' => true, 'chat_message_limit' => 25, 'chat_token_budget' => 12000,
                'ai_budget_usd' => 0.45, 'request_reserve_usd' => 0.015,
                'project_feedback_token_budget' => 6000,
                'project_feedback_budget_usd' => 0.20, 'project_feedback_reserve_usd' => 0.04,
                'project_followup_message_limit' => 0, 'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => 0, 'project_followup_reserve_usd' => 0,
                'max_output_tokens' => 320, 'project_feedback_level' => 'report',
                'project_output_enabled' => false, 'certificate_enabled' => true,
                'is_active' => true, 'sort_order' => 20,
            ],
            [
                'code' => 'mentor', 'name_ar' => 'Mentor', 'price_coins' => 900,
                'minimum_paid_coins' => 1,
                'chat_enabled' => true, 'chat_message_limit' => 80, 'chat_token_budget' => 42000,
                'ai_budget_usd' => 1.50, 'request_reserve_usd' => 0.025,
                'project_feedback_token_budget' => 16000,
                'project_feedback_budget_usd' => 0.60, 'project_feedback_reserve_usd' => 0.08,
                'project_followup_message_limit' => 20, 'project_followup_token_budget' => 12000,
                'project_followup_budget_usd' => 0.30, 'project_followup_reserve_usd' => 0.025,
                'max_output_tokens' => 480, 'project_feedback_level' => 'enhanced',
                'project_output_enabled' => true, 'certificate_enabled' => true,
                'is_active' => true, 'sort_order' => 30,
            ],
        ] as $definition) {
            $course->accessPlans()->create($definition);
        }
    }

    private function creditPaid(User $user, int $coins): void
    {
        $package = Package::query()->create([
            'name_ar' => 'Test package',
            'name_en' => 'Test package',
            'price' => $coins,
            'coins' => $coins,
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_coins' => $coins,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => $coins,
            'discount_amount' => 0,
            'final_amount' => $coins,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);
        $credit = app(WalletService::class)->credit(
            (int) $user->id,
            $coins,
            'package_purchase',
            $this->key('paid-credit'),
            $order,
            ['package_id' => (int) $package->id],
            WalletTransaction::BUCKET_PAID
        );
        app(FinancialProvenanceService::class)->recordPaidPackageCredit($order, $credit);
    }

    private function creditReward(User $user, int $coins): void
    {
        app(WalletService::class)->credit(
            (int) $user->id,
            $coins,
            'test_reward_refill',
            $this->key('reward-credit'),
            null,
            [],
            WalletTransaction::BUCKET_REWARD
        );
    }

    private function key(string $operation): string
    {
        return sprintf('test:%s:%04d', $operation, ++$this->sequence);
    }
}
