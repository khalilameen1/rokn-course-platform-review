<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Console\Commands\ProductionPreflight;
use App\Exceptions\FinancialProvenanceException;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\FinancialEntitlementHold;
use App\Models\Order;
use App\Models\WalletCreditLot;
use App\Models\WalletTransaction;
use App\Services\CourseChatAccessService;
use App\Services\FinancialProvenanceService;
use App\Services\KashierPaymentService;
use App\Services\OrderLifecycleService;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinancialProvenanceTest extends ApiTestCase
{
    private int $packageSequence = 0;
    private int $operationSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('ai_chat_enabled')->default(true);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('package_coins')->nullable();
            $table->unsignedBigInteger('parent_order_id')->nullable();
        });
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('coins')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });
        Schema::create('package_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable()->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('package_id');
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedInteger('coins')->nullable();
            $table->timestamps();
        });
        Schema::create('order_financial_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_type', 32);
            $table->string('event_key', 96);
            $table->string('provider', 32)->nullable();
            $table->string('external_event_id', 191)->nullable();
            $table->unsignedInteger('recovered_coins')->default(0);
            $table->unsignedInteger('unrecovered_coins')->default(0);
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['order_id', 'event_key']);
            $table->unique(['provider', 'external_event_id']);
        });

        // ApiTestCase provides the hold table needed by the common API fixture.
        // This suite owns the complete provenance schema (including its unique
        // source/course constraint), so replace the shared fixture explicitly.
        Schema::dropIfExists('financial_entitlement_holds');
        $this->createProvenanceSchema();
        $this->setWallet(0, 0);
        DB::table('courses')->where('id', $this->courseId)->update(['ai_chat_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('financial_entitlement_holds');
        Schema::dropIfExists('wallet_debit_allocations');
        Schema::dropIfExists('wallet_credit_lots');
        Schema::dropIfExists('order_financial_events');
        Schema::dropIfExists('package_user');
        Schema::dropIfExists('packages');

        parent::tearDown();
    }

    public function test_partial_spend_reversal_recovers_only_unspent_paid_coins_and_is_idempotent(): void
    {
        $package = $this->paidPackage(1000);
        [$courseOrder, $enrollment, $certificate] = $this->courseSpend(400);

        $lifecycle = app(OrderLifecycleService::class);
        $first = $lifecycle->registerReversal(
            $package,
            Order::FINANCIAL_CHARGEBACK,
            'Provider chargeback',
            'gateway:chargeback:partial',
            null,
            'kashier',
            'chargeback-partial'
        );
        $second = $lifecycle->registerReversal(
            $package,
            Order::FINANCIAL_CHARGEBACK,
            'Provider chargeback',
            'gateway:chargeback:partial',
            null,
            'kashier',
            'chargeback-partial'
        );

        self::assertSame(600, $first->recovered_coins);
        self::assertSame(400, $first->unrecovered_coins);
        self::assertSame($first->recovered_coins, $second->recovered_coins);
        self::assertSame(0, (int) $this->user->fresh()->wallet_purchased_coins);
        self::assertSame(1, DB::table('wallet_transactions')->where('category', 'package_reversal')->count());
        self::assertSame(1, DB::table('order_financial_events')->where('order_id', $package->id)->count());
        self::assertSame(1, FinancialEntitlementHold::query()->where('course_order_id', $courseOrder->id)->count());
        self::assertFalse((bool) $enrollment->fresh()->is_active);
        self::assertSame('revoked', $certificate->fresh()->status);

        $lot = WalletCreditLot::query()->where('source_order_id', $package->id)->firstOrFail();
        self::assertSame(WalletCreditLot::STATUS_FROZEN, $lot->status);
        self::assertSame(0, $lot->remaining_amount);
        self::assertSame(600, $lot->recovered_amount);
    }

    public function test_kashier_transaction_fallback_deduplicates_retries_without_merging_later_states(): void
    {
        $order = $this->paidPackage(500, true, [
            'transaction_id' => 'TXN-REFUND-LIFECYCLE',
        ]);
        $payments = app(KashierPaymentService::class);

        $payments->recordFinancialReversal(
            $order,
            Order::FINANCIAL_REFUNDED,
            'REFUNDED',
            'TXN-REFUND-LIFECYCLE',
            [
                'id' => 'payment-row-shared-across-states',
                'transactionId' => 'TXN-REFUND-LIFECYCLE',
            ]
        );
        $payments->recordFinancialReversal(
            $order->fresh(),
            Order::FINANCIAL_REFUNDED,
            'REFUND',
            'TXN-REFUND-LIFECYCLE',
            [
                'id' => 'payment-row-shared-across-states',
                'transactionId' => 'TXN-REFUND-LIFECYCLE',
            ]
        );
        $payments->recordFinancialReversal(
            $order->fresh(),
            Order::FINANCIAL_CHARGEBACK,
            'CHARGEBACK',
            'TXN-REFUND-LIFECYCLE',
            [
                'id' => 'payment-row-shared-across-states',
                'transactionId' => 'TXN-REFUND-LIFECYCLE',
            ]
        );

        $events = DB::table('order_financial_events')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        self::assertCount(2, $events);
        self::assertSame('refunded', $events[0]->event_type);
        self::assertSame('chargeback', $events[1]->event_type);
        self::assertNotSame($events[0]->external_event_id, $events[1]->external_event_id);
        self::assertSame(0, (int) $this->user->fresh()->wallet_purchased_coins);
    }

    public function test_fully_spent_package_holds_only_its_course_and_revokes_its_certificate(): void
    {
        $package = $this->paidPackage(500);
        [$courseOrder, $enrollment, $certificate] = $this->courseSpend(500);

        $result = app(OrderLifecycleService::class)->registerReversal(
            $package,
            Order::FINANCIAL_REFUNDED,
            'Refunded capture',
            'gateway:refund:fully-spent'
        );

        self::assertSame(0, $result->recovered_coins);
        self::assertSame(500, $result->unrecovered_coins);
        self::assertFalse((bool) $enrollment->fresh()->is_active);
        self::assertSame('revoked', $certificate->fresh()->status);
        $hold = FinancialEntitlementHold::query()->where('course_order_id', $courseOrder->id)->firstOrFail();
        self::assertSame('course', $hold->entitlement_scope);
        self::assertSame($certificate->id, $hold->certificate_id);
        self::assertNotNull($hold->certificate_revoked_at);
    }

    public function test_chat_reversal_blocks_only_rokn_ai_not_learning_or_certificate(): void
    {
        $grant = $this->independentCourseOrder(Order::PAYMENT_METHOD_COURSE_CODE);
        $enrollment = CourseEnrollment::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'order_id' => $grant->id,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
        ]);
        $certificate = $this->certificate();
        $package = $this->paidPackage(300);
        [$chatOrder] = $this->courseSpend(300, 'chat', $enrollment, false);

        app(OrderLifecycleService::class)->registerReversal(
            $package,
            Order::FINANCIAL_CHARGEBACK,
            'Chat funding reversed',
            'gateway:chargeback:chat'
        );

        $hold = FinancialEntitlementHold::query()->where('course_order_id', $chatOrder->id)->firstOrFail();
        self::assertSame('chat', $hold->entitlement_scope);
        self::assertTrue((bool) $enrollment->fresh()->is_active);
        self::assertSame('active', $certificate->fresh()->status);

        $access = app(CourseChatAccessService::class)->entitlementFor(
            (int) $this->user->id,
            $this->courseId
        );
        self::assertTrue($access['has_learning_access']);
        self::assertFalse($access['chat_available']);
    }

    public function test_active_financial_hold_cannot_leak_the_full_course_contract(): void
    {
        $package = $this->paidPackage(400);
        [$courseOrder, $enrollment] = $this->courseSpend(400);

        FinancialEntitlementHold::query()->create([
            'public_id' => (string) str()->uuid(),
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'course_order_id' => $courseOrder->id,
            'source_order_id' => $package->id,
            'enrollment_id' => $enrollment->id,
            'status' => FinancialEntitlementHold::STATUS_ACTIVE,
            'entitlement_scope' => 'course',
            'reason' => 'Payment reversal pending review',
            'held_at' => now(),
        ]);

        self::assertTrue((bool) $enrollment->fresh()->is_active);

        $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/courses/{$this->courseId}/details")
            ->assertOk()
            ->assertJsonPath('data.access_type', 'none')
            ->assertJsonMissingPath('data.enrollment')
            ->assertJsonMissingPath('data.attachment_prompt');

        $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/courses/{$this->courseId}/progress")
            ->assertForbidden();

        $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/courses/{$this->courseId}/rate", [
                'rating' => 5,
                'version' => 0,
            ])
            ->assertForbidden();
    }

    public function test_base_purchase_reversal_still_revokes_learning_after_a_plan_upgrade(): void
    {
        $basePackage = $this->paidPackage(500);
        [$baseOrder, $enrollment, $certificate] = $this->courseSpend(500);
        $planPackage = $this->paidPackage(300);
        $planOrder = $this->planUpgradeSpend(300, $enrollment);

        self::assertSame($baseOrder->id, $enrollment->fresh()->order_id);
        self::assertSame($planOrder->id, $enrollment->fresh()->access_plan_order_id);

        app(OrderLifecycleService::class)->registerReversal(
            $basePackage,
            Order::FINANCIAL_CHARGEBACK,
            'Base course funding reversed',
            'gateway:chargeback:base-after-plan'
        );

        self::assertFalse((bool) $enrollment->fresh()->is_active);
        self::assertSame('revoked', $certificate->fresh()->status);
        self::assertSame(
            'course',
            FinancialEntitlementHold::query()
                ->where('course_order_id', $baseOrder->id)
                ->value('entitlement_scope')
        );
        self::assertSame(
            0,
            FinancialEntitlementHold::query()
                ->where('source_order_id', $planPackage->id)
                ->count()
        );
    }

    public function test_plan_reversal_downgrades_only_the_plan_and_resolution_restores_it(): void
    {
        $baseOrder = $this->independentCourseOrder(Order::PAYMENT_METHOD_COURSE_CODE);
        $enrollment = CourseEnrollment::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'order_id' => $baseOrder->id,
            'access_plan_order_id' => $baseOrder->id,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
        ]);
        $package = $this->paidPackage(300);
        $planOrder = $this->planUpgradeSpend(300, $enrollment);
        $lifecycle = app(OrderLifecycleService::class);

        $review = $lifecycle->registerReversal(
            $package,
            Order::FINANCIAL_CHARGEBACK,
            'Plan funding reversed',
            'gateway:chargeback:plan-only'
        );

        $hold = FinancialEntitlementHold::query()
            ->where('course_order_id', $planOrder->id)
            ->firstOrFail();
        self::assertSame('plan', $hold->entitlement_scope);
        self::assertNotNull($hold->plan_reverted_at);
        self::assertTrue((bool) $enrollment->fresh()->is_active);
        self::assertSame($baseOrder->id, $enrollment->fresh()->order_id);
        self::assertSame($baseOrder->id, $enrollment->fresh()->access_plan_order_id);

        $lifecycle->resolveFinancialReview(
            $review,
            FinancialEntitlementHold::RESOLUTION_WAIVED,
            'finance:resolution:restore-plan',
            null,
            'Plan access restored after review'
        );

        self::assertTrue((bool) $enrollment->fresh()->is_active);
        self::assertSame($baseOrder->id, $enrollment->fresh()->order_id);
        self::assertSame($planOrder->id, $enrollment->fresh()->access_plan_order_id);
    }

    public function test_later_independent_enrollment_and_certificate_are_untouched(): void
    {
        $package = $this->paidPackage(400);
        [, $enrollment, $certificate] = $this->courseSpend(400);
        $laterGrant = $this->independentCourseOrder(Order::PAYMENT_METHOD_COURSE_CODE);
        $enrollment->forceFill(['order_id' => $laterGrant->id, 'is_active' => true])->save();

        app(OrderLifecycleService::class)->registerReversal(
            $package,
            Order::FINANCIAL_CHARGEBACK,
            'Old package reversed after a later grant',
            'gateway:chargeback:later-grant'
        );

        self::assertTrue((bool) $enrollment->fresh()->is_active);
        self::assertSame($laterGrant->id, $enrollment->fresh()->order_id);
        self::assertSame('active', $certificate->fresh()->status);
        self::assertSame(0, FinancialEntitlementHold::query()->where('source_order_id', $package->id)->count());
    }

    public function test_repay_and_waive_resolutions_release_holds_exactly_once(): void
    {
        $repaidPackage = $this->paidPackage(500);
        [, $repaidEnrollment, $repaidCertificate] = $this->courseSpend(300);
        $lifecycle = app(OrderLifecycleService::class);
        $review = $lifecycle->registerReversal(
            $repaidPackage,
            Order::FINANCIAL_CHARGEBACK,
            'Temporary dispute',
            'gateway:chargeback:repay'
        );
        self::assertSame(200, $review->recovered_coins);

        $lifecycle->resolveFinancialReview(
            $review,
            'repaid',
            'finance:resolution:repay',
            null,
            'Capture confirmed valid'
        );
        $lifecycle->resolveFinancialReview(
            $review->fresh(),
            'repaid',
            'finance:resolution:repay',
            null,
            'Capture confirmed valid'
        );
        self::assertSame(200, (int) $this->user->fresh()->wallet_purchased_coins);
        self::assertTrue((bool) $repaidEnrollment->fresh()->is_active);
        self::assertSame('active', $repaidCertificate->fresh()->status);
        self::assertSame(1, DB::table('wallet_transactions')->where('category', 'package_reversal_resolution')->count());

        $this->setWallet(0, 0);
        $waivedPackage = $this->paidPackage(250);
        [, $waivedEnrollment, $waivedCertificate] = $this->courseSpend(250);
        $waivedReview = $lifecycle->registerReversal(
            $waivedPackage,
            Order::FINANCIAL_REFUNDED,
            'Refunded package',
            'gateway:refund:waive'
        );
        $lifecycle->resolveFinancialReview(
            $waivedReview,
            'waived',
            'finance:resolution:waive',
            null,
            'Goodwill access approved'
        );

        self::assertSame(0, (int) $this->user->fresh()->wallet_purchased_coins);
        self::assertTrue((bool) $waivedEnrollment->fresh()->is_active);
        self::assertSame('active', $waivedCertificate->fresh()->status);
        self::assertSame(
            WalletCreditLot::STATUS_WAIVED,
            WalletCreditLot::query()->where('source_order_id', $waivedPackage->id)->value('status')
        );
    }

    public function test_later_manual_certificate_revocation_is_never_undone_by_finance_resolution(): void
    {
        $package = $this->paidPackage(300);
        [, $enrollment, $certificate] = $this->courseSpend(300);
        $review = app(OrderLifecycleService::class)->registerReversal(
            $package,
            Order::FINANCIAL_CHARGEBACK,
            'Disputed capture',
            'gateway:chargeback:manual-revoke'
        );
        $financialRevokedAt = $certificate->fresh()->revoked_at;
        $financialDeactivatedAt = $enrollment->fresh()->updated_at;
        self::assertNotNull($financialRevokedAt);

        $certificate->fresh()->forceFill([
            'status' => 'revoked',
            'revoked_at' => $financialRevokedAt->copy()->addMinute(),
        ])->save();
        DB::table('course_enrollments')
            ->where('id', $enrollment->id)
            ->update(['updated_at' => $financialDeactivatedAt->copy()->addMinute()]);
        app(OrderLifecycleService::class)->resolveFinancialReview(
            $review,
            'waived',
            'finance:resolution:manual-revoke',
            null,
            'Access waived; certificate remains manually revoked'
        );

        self::assertSame('revoked', $certificate->fresh()->status);
        self::assertTrue($certificate->fresh()->revoked_at->equalTo($financialRevokedAt->copy()->addMinute()));
        self::assertFalse((bool) $enrollment->fresh()->is_active);
    }

    public function test_missing_provenance_schema_rolls_back_package_approval_and_coin_mint(): void
    {
        Schema::dropIfExists('financial_entitlement_holds');
        $packageId = $this->packageRecord(500);
        $order = Order::query()->create([
            'user_id' => $this->user->id,
            'package_id' => $packageId,
            'package_coins' => 500,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => 100,
            'final_amount' => 100,
            'status' => Order::STATUS_PENDING,
            'financial_status' => Order::FINANCIAL_PENDING,
        ]);

        try {
            app(OrderLifecycleService::class)->approve($order, null, null, true);
            self::fail('Approval should fail closed when provenance storage is missing.');
        } catch (FinancialProvenanceException) {
            // Expected: the surrounding transaction must roll back every side effect.
        }

        self::assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        self::assertSame(0, (int) $this->user->fresh()->wallet_coins);
        self::assertSame(0, WalletTransaction::query()->count());
        self::assertSame(0, DB::table('package_user')->count());
    }

    public function test_provider_controlled_order_cannot_be_approved_without_provider_evidence(): void
    {
        $packageId = $this->packageRecord(500);
        $order = Order::query()->create([
            'user_id' => $this->user->id,
            'package_id' => $packageId,
            'package_coins' => 500,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => 100,
            'final_amount' => 100,
            'status' => Order::STATUS_PENDING,
            'financial_status' => Order::FINANCIAL_PENDING,
        ]);

        try {
            app(OrderLifecycleService::class)->approve($order, $this->user->id);
            self::fail('A dashboard actor must not approve a provider-controlled order.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('verified provider evidence', $exception->getMessage());
        }

        self::assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        self::assertSame(0, (int) $this->user->fresh()->wallet_coins);
        self::assertSame(0, WalletTransaction::query()->count());
    }

    public function test_backfill_reconstructs_fifo_and_preflight_blocks_unreconciled_history(): void
    {
        $historical = $this->paidPackage(500, false, [
            'financial_status' => Order::FINANCIAL_REVERSED,
            'reversed_at' => now(),
            'reversal_reason' => 'Historical refund',
        ]);

        $exit = Artisan::call('finance:backfill-provenance', ['--apply' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(1, WalletCreditLot::query()->where('source_order_id', $historical->id)->count());
        self::assertSame(500, (int) $this->user->fresh()->wallet_purchased_coins);
        self::assertStringContainsString(
            'Historical reversals needing finance review',
            Artisan::output()
        );

        $command = app(ProductionPreflight::class);
        $method = new \ReflectionMethod($command, 'financialProvenanceFailures');
        $method->setAccessible(true);
        /** @var list<string> $failures */
        $failures = $method->invoke($command);
        self::assertTrue(collect($failures)->contains(
            fn (string $failure): bool => str_contains($failure, 'historical package reversal')
        ));
    }

    private function createProvenanceSchema(): void
    {
        Schema::create('wallet_credit_lots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('source_order_id')->nullable()->unique();
            $table->unsignedBigInteger('credit_transaction_id')->unique();
            $table->unsignedInteger('original_amount');
            $table->unsignedInteger('remaining_amount');
            $table->unsignedInteger('recovered_amount')->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamp('credited_at');
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_debit_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('wallet_transaction_id');
            $table->unsignedBigInteger('credit_lot_id');
            $table->unsignedBigInteger('course_order_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('entitlement_scope', 16)->default('course');
            $table->timestamp('allocated_at');
            $table->timestamps();
            $table->unique(['wallet_transaction_id', 'credit_lot_id']);
        });
        Schema::create('financial_entitlement_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('course_order_id');
            $table->unsignedBigInteger('source_order_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->timestamp('enrollment_deactivated_at')->nullable();
            $table->timestamp('plan_reverted_at')->nullable();
            $table->unsignedBigInteger('certificate_id')->nullable();
            $table->timestamp('certificate_revoked_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('status', 24)->default('active');
            $table->string('entitlement_scope', 16)->default('course');
            $table->string('reason', 255)->nullable();
            $table->string('resolution', 24)->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('held_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['source_order_id', 'course_order_id']);
        });
    }

    /** @param array<string,mixed> $overrides */
    private function paidPackage(int $amount, bool $recordLot = true, array $overrides = []): Order
    {
        $packageId = $this->packageRecord($amount);
        $order = Order::query()->create(array_merge([
            'user_id' => $this->user->id,
            'package_id' => $packageId,
            'package_coins' => $amount,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => 100,
            'discount_amount' => 0,
            'final_amount' => 100,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ], $overrides));
        $credit = app(WalletService::class)->credit(
            (int) $this->user->id,
            $amount,
            'package_purchase',
            'test:package-credit:' . (++$this->operationSequence),
            $order,
            ['package_id' => $packageId],
            WalletTransaction::BUCKET_PAID
        );
        if ($recordLot) {
            app(FinancialProvenanceService::class)->recordPaidPackageCredit($order, $credit);
        }

        return $order;
    }

    private function packageRecord(int $coins): int
    {
        return (int) DB::table('packages')->insertGetId([
            'name_ar' => 'Test package ' . (++$this->packageSequence),
            'name_en' => 'Test package ' . $this->packageSequence,
            'price' => 100,
            'coins' => $coins,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0:Order,1:CourseEnrollment,2:?Certificate} */
    private function courseSpend(
        int $amount,
        string $scope = 'course',
        ?CourseEnrollment $enrollment = null,
        bool $issueCertificate = true
    ): array {
        $category = $scope === 'chat' ? 'course_chat_upgrade' : 'course_purchase';
        $key = 'test:' . $category . ':' . (++$this->operationSequence);
        $course = Course::query()->findOrFail($this->courseId);
        $order = Order::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => $amount,
            'discount_amount' => 0,
            'final_amount' => $amount,
            'total_coins' => $amount,
            'paid_coins' => $amount,
            'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'notes' => $scope === 'chat'
                ? 'Rokn AI/full-access upgrade from grant order #' . (int) $enrollment?->order_id
                : 'Idempotency: ' . $key,
            'approved_at' => now(),
        ]);
        $debit = app(WalletService::class)->debit(
            (int) $this->user->id,
            $amount,
            $category,
            $key,
            $course,
            $scope === 'chat'
                ? ['grant_order_id' => (int) $enrollment?->order_id]
                : [],
            0
        );
        $order->forceFill(['wallet_transaction_id' => $debit->id])->save();
        app(FinancialProvenanceService::class)->allocateCourseDebit($order, $debit);

        $enrollment ??= CourseEnrollment::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
        ]);
        $enrollment->forceFill([
            'order_id' => $order->id,
            'access_plan_order_id' => $order->id,
            'is_active' => true,
        ])->save();
        $certificate = $issueCertificate ? $this->certificate() : null;

        return [$order, $enrollment, $certificate];
    }

    private function planUpgradeSpend(int $amount, CourseEnrollment $enrollment): Order
    {
        $course = Course::query()->findOrFail($this->courseId);
        $parentOrderId = (int) ($enrollment->access_plan_order_id ?: $enrollment->order_id);
        $key = 'test:course-full-track-upgrade:' . (++$this->operationSequence);
        $order = Order::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $course->id,
            'parent_order_id' => $parentOrderId,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => $amount,
            'discount_amount' => 0,
            'final_amount' => $amount,
            'total_coins' => $amount,
            'paid_coins' => $amount,
            'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'notes' => 'Course access-plan upgrade from order #' . $parentOrderId,
            'approved_at' => now(),
        ]);
        $debit = app(WalletService::class)->debit(
            (int) $this->user->id,
            $amount,
            'course_full_track_upgrade',
            $key,
            $course,
            [
                'base_order_id' => (int) $enrollment->order_id,
                'parent_order_id' => $parentOrderId,
            ],
            0
        );
        $order->forceFill(['wallet_transaction_id' => $debit->id])->save();
        app(FinancialProvenanceService::class)->allocateCourseDebit($order, $debit);
        $enrollment->forceFill(['access_plan_order_id' => $order->id])->save();

        return $order;
    }

    private function independentCourseOrder(string $paymentMethod): Order
    {
        return Order::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'payment_method' => $paymentMethod,
            'amount' => 0,
            'final_amount' => 0,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);
    }

    private function certificate(): Certificate
    {
        return Certificate::query()->firstOrCreate(
            ['user_id' => $this->user->id, 'course_id' => $this->courseId],
            [
                'public_id' => sprintf('00000000-0000-4000-8000-%012d', ++$this->operationSequence),
                'image_path' => 'certificates/test.png',
                'status' => 'active',
                'generated_at' => now(),
            ]
        );
    }

    private function setWallet(int $paid, int $reward): void
    {
        $transactionIds = DB::table('wallet_transactions')
            ->where('user_id', $this->user->id)
            ->pluck('id');

        if (Schema::hasTable('wallet_debit_allocations') && $transactionIds->isNotEmpty()) {
            DB::table('wallet_debit_allocations')
                ->whereIn('wallet_transaction_id', $transactionIds)
                ->delete();
        }

        if (Schema::hasTable('wallet_credit_lots')) {
            DB::table('wallet_credit_lots')->where('user_id', $this->user->id)->delete();
        }

        DB::table('wallet_transactions')->where('user_id', $this->user->id)->delete();

        $this->user->newQuery()->whereKey($this->user->id)->update([
            'wallet_purchased_coins' => $paid,
            'wallet_reward_coins' => $reward,
            'wallet_coins' => $paid + $reward,
        ]);
        $this->user->refresh();
    }
}
