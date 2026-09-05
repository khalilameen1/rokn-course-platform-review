<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseCode;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CourseAccessPlanService;
use App\Services\CourseChatAccessService;
use App\Services\FinancialProvenanceService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CourseEntitlementReadConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Course $course;
    private CourseAccessPlan $guidedPlan;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->learner();
        $this->course = $this->publishedCourse();
        $this->guidedPlan = $this->guidedPlan($this->course);
    }

    public static function accessCases(): array
    {
        return [
            'paid' => ['paid', null, true, true, true],
            'plan held' => ['paid', 'plan', true, false, true],
            'course held' => ['paid', 'course', false, false, false],
            'scholarship' => ['scholarship', null, true, false, false],
            'free' => ['free', null, true, false, true],
            'expired' => ['expired', null, false, false, false],
        ];
    }

    #[DataProvider('accessCases')]
    public function test_all_course_readers_resolve_the_same_entitlement(
        string $kind,
        ?string $hold,
        bool $learning,
        bool $chat,
        bool $certificate
    ): void {
        $enrollment = $this->enrollment($kind);
        if ($hold) {
            DB::table('financial_entitlement_holds')->insert([
                'public_id' => (string) Str::uuid(),
                'user_id' => $this->user->id,
                'course_id' => $this->course->id,
                'enrollment_id' => $enrollment->id,
                'course_order_id' => $hold === 'plan'
                    ? $enrollment->access_plan_order_id
                    : $enrollment->order_id,
                'source_order_id' => $hold === 'plan'
                    ? $enrollment->access_plan_order_id
                    : $enrollment->order_id,
                'entitlement_scope' => $hold,
                'status' => 'active',
                'reason' => 'test source payment review',
                'held_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $access = app(CourseChatAccessService::class);
        $single = $access->resolveEntitlement($this->user->id, $this->course->id);
        $batch = $access->entitlementsFor($this->user->id, [$this->course->id, 999999]);

        self::assertSame($single['entitlement'], $batch[$this->course->id]);
        self::assertSame($learning, $single['entitlement']['has_learning_access']);
        self::assertSame($chat, $single['entitlement']['chat_available']);
        self::assertSame($certificate, $single['entitlement']['certificate_available']);
        self::assertSame($learning ? $enrollment->id : null, $single['enrollment']?->id);
        self::assertSame($learning, $access->hasLearningAccess($this->user->id, $this->course->id));
        self::assertSame($chat, $access->hasChatAccess($this->user->id, $this->course->id));
        self::assertSame(
            $chat ? $enrollment->id : null,
            $access->activeChatEnrollmentFor($this->user->id, $this->course->id)?->id
        );
        self::assertSame(
            $learning ? $enrollment->id : null,
            $access->activeProjectEnrollmentFor($this->user->id, $this->course->id)?->id
        );
        self::assertFalse($batch[999999]['has_learning_access']);
    }

    public function test_one_course_does_not_repeat_financial_reads_for_each_access_question(): void
    {
        $this->enrollment('paid');
        $access = app(CourseChatAccessService::class);

        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $access->entitlementsFor($this->user->id, [$this->course->id]);
            $batchReads = count(DB::getQueryLog());
            DB::flushQueryLog();
            $access->resolveEntitlement($this->user->id, $this->course->id);
            $detailReads = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        self::assertLessThanOrEqual($batchReads + 1, $detailReads);
    }

    public function test_batch_keeps_non_contiguous_course_ids_and_represents_no_enrollment(): void
    {
        $enrollment = $this->enrollment('paid');
        $this->publishedCourse();
        $otherCourse = $this->publishedCourse();

        self::assertGreaterThan($this->course->id + 1, $otherCourse->id);

        $access = app(CourseChatAccessService::class);
        $batch = $access->entitlementsFor($this->user->id, [
            $this->course->id,
            $otherCourse->id,
        ]);
        $missing = $access->resolveEntitlement($this->user->id, $otherCourse->id);

        self::assertSame(
            [$this->course->id, $otherCourse->id],
            array_keys($batch)
        );
        self::assertTrue($batch[$this->course->id]['has_learning_access']);
        self::assertSame($enrollment->id, $access->resolveEntitlement(
            $this->user->id,
            $this->course->id
        )['enrollment']?->id);
        self::assertFalse($batch[$otherCourse->id]['has_learning_access']);
        self::assertNull($missing['enrollment']);
        self::assertSame($missing['entitlement'], $batch[$otherCourse->id]);
    }

    public function test_paid_upgrade_turns_a_scholarship_into_the_paid_guided_entitlement(): void
    {
        $enrollment = $this->scholarshipEnrollment();
        $upgradeOrder = $this->paidPlanOrder(
            'course_full_track_upgrade',
            $enrollment->order,
            200
        );
        $enrollment->forceFill([
            'access_plan_order_id' => $upgradeOrder->id,
            'access_plan_id' => $this->guidedPlan->id,
            'access_plan_snapshot' => $upgradeOrder->access_plan_snapshot,
        ])->save();

        $access = app(CourseChatAccessService::class);
        $single = $access->resolveEntitlement($this->user->id, $this->course->id);
        $batch = $access->entitlementsFor($this->user->id, [$this->course->id]);

        self::assertSame($single['entitlement'], $batch[$this->course->id]);
        self::assertSame('paid', $single['entitlement']['access_type']);
        self::assertTrue($single['entitlement']['has_learning_access']);
        self::assertTrue($single['entitlement']['chat_available']);
        self::assertTrue($single['entitlement']['certificate_available']);
    }

    public function test_captured_enrollment_survives_course_draft_state_for_committed_async_work(): void
    {
        $enrollment = $this->enrollment('paid');
        $this->course->forceFill(['is_coming_soon' => true])->save();

        $access = app(CourseChatAccessService::class);
        self::assertFalse($access->hasLearningAccess($this->user->id, $this->course->id));
        self::assertFalse($access->hasCertificateAccess($this->user->id, $this->course->id));
        self::assertTrue($access->enrollmentHasCertificateAccess($enrollment));
        self::assertSame(
            $enrollment->id,
            $access->activeCapturedEnrollmentFor(
                $this->user->id,
                $this->course->id,
                $enrollment->id
            )?->id
        );
    }

    public function test_invalid_paid_plan_snapshot_keeps_learning_but_never_paid_features(): void
    {
        $enrollment = $this->enrollment('paid');
        DB::table('course_enrollments')->where('id', $enrollment->id)->update([
            'access_plan_snapshot' => json_encode([
                'version' => 999,
                'plan_id' => $this->guidedPlan->id,
                'code' => CourseAccessPlan::GUIDED,
            ], JSON_THROW_ON_ERROR),
        ]);

        $access = app(CourseChatAccessService::class);
        $single = $access->resolveEntitlement($this->user->id, $this->course->id);
        $batch = $access->entitlementsFor($this->user->id, [$this->course->id]);

        self::assertSame($single['entitlement'], $batch[$this->course->id]);
        self::assertTrue($single['entitlement']['has_learning_access']);
        self::assertSame('paid', $single['entitlement']['access_type']);
        self::assertFalse($single['entitlement']['chat_available']);
        self::assertFalse($single['entitlement']['certificate_available']);
    }

    private function enrollment(string $kind): CourseEnrollment
    {
        if ($kind === 'scholarship') {
            return $this->scholarshipEnrollment();
        }
        if ($kind === 'free') {
            return $this->createEnrollment([
                'tenant_id' => 1,
                'user_id' => $this->user->id,
                'course_id' => $this->course->id,
                'is_active' => true,
                'enrolled_at' => now(),
                'access_granted_at' => now(),
            ]);
        }

        $order = $this->paidPlanOrder('course_purchase', null, 200);
        return $this->createEnrollment([
            'tenant_id' => 1,
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'order_id' => $order->id,
            'access_plan_order_id' => $order->id,
            'access_plan_id' => $this->guidedPlan->id,
            'access_plan_snapshot' => $order->access_plan_snapshot,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
            'expires_at' => $kind === 'expired' ? now()->subMinute() : null,
        ]);
    }

    private function scholarshipEnrollment(): CourseEnrollment
    {
        $code = new CourseCode();
        $code->forceFill([
            'tenant_id' => 1,
            'code' => 'GRANT-'.$this->key(),
            'name' => 'Institutional grant',
            'type' => 'course',
            'course_id' => $this->course->id,
            'max_uses' => 1,
            'used_count' => 1,
            'is_active' => true,
            'is_grant' => true,
        ])->save();
        $order = Order::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'course_code_id' => $code->id,
            'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE,
            'amount' => 0,
            'discount_amount' => 0,
            'final_amount' => 0,
            'total_coins' => 0,
            'paid_coins' => 0,
            'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);

        return $this->createEnrollment([
            'tenant_id' => 1,
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'order_id' => $order->id,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
        ]);
    }

    private function paidPlanOrder(string $category, ?Order $parent, int $amount): Order
    {
        $this->fundPaidWallet($amount);
        $snapshot = app(CourseAccessPlanService::class)->snapshot($this->guidedPlan);
        $order = Order::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'parent_order_id' => $parent?->id,
            'access_plan_id' => $this->guidedPlan->id,
            'access_plan_snapshot' => $snapshot,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => $amount,
            'discount_amount' => 0,
            'final_amount' => $amount,
            'total_coins' => $amount,
            'paid_coins' => $amount,
            'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
            'notes' => $category === 'course_purchase'
                ? 'Wallet course purchase'
                : 'Course access-plan upgrade from order #'.(int) $parent?->id,
        ]);
        $debit = app(WalletService::class)->debit(
            (int) $this->user->id,
            $amount,
            $category,
            'entitlement-'.$category.'-'.$this->key(),
            $this->course,
            [],
            0
        );
        $order->forceFill(['wallet_transaction_id' => $debit->id])->save();
        app(FinancialProvenanceService::class)->allocateCourseDebit($order, $debit);

        return $order->fresh();
    }

    private function fundPaidWallet(int $amount): void
    {
        $package = Package::query()->create([
            'name_ar' => 'باقة الاختبار',
            'name_en' => 'Test package',
            'price' => $amount,
            'coins' => $amount,
        ]);
        $order = Order::query()->create([
            'user_id' => $this->user->id,
            'package_id' => $package->id,
            'package_coins' => $amount,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => $amount,
            'discount_amount' => 0,
            'final_amount' => $amount,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);
        $credit = app(WalletService::class)->credit(
            (int) $this->user->id,
            $amount,
            'package_purchase',
            'entitlement-package-'.$this->key(),
            $order,
            ['package_id' => $package->id],
            WalletTransaction::BUCKET_PAID
        );
        app(FinancialProvenanceService::class)->recordPaidPackageCredit($order, $credit);
    }

    private function learner(): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Entitlement learner',
            'email' => 'entitlement-'.Str::uuid().'@rokn.test',
            'password' => bcrypt('test-password'),
            'role' => 'client',
            'active' => true,
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
        ])->save();

        return $user;
    }

    private function publishedCourse(): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس اتساق الإتاحة',
            'name_en' => 'Entitlement consistency course',
            'description_ar' => 'كورس للاختبار',
            'description_en' => 'Test course',
            'price' => 200,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'authoring_version' => 1,
            'last_published_authoring_version' => 1,
            'published_at' => now(),
        ])->save();
        $module = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'الوحدة الأولى',
            'order' => 1,
        ]);
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'المقطع الأول',
            'duration_minutes' => 1,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'title_ar' => 'المقطع الأول',
            'title_en' => 'First reel',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 1,
        ]);

        return $course;
    }

    private function guidedPlan(Course $course): CourseAccessPlan
    {
        return $course->accessPlans()->create([
            'code' => CourseAccessPlan::GUIDED,
            'name_ar' => 'مع تقارير',
            'name_en' => 'Guided',
            'price_coins' => 200,
            'minimum_paid_coins' => 100,
            'chat_enabled' => true,
            'chat_message_limit' => 25,
            'chat_token_budget' => 12000,
            'ai_budget_usd' => 0.45,
            'request_reserve_usd' => 0.015,
            'project_feedback_token_budget' => 6000,
            'project_feedback_budget_usd' => 0.20,
            'project_feedback_reserve_usd' => 0.04,
            'max_output_tokens' => 320,
            'project_feedback_level' => CourseAccessPlan::FEEDBACK_REPORT,
            'project_output_enabled' => false,
            'certificate_enabled' => true,
            'is_active' => true,
            'sort_order' => 20,
        ]);
    }

    private function createEnrollment(array $attributes): CourseEnrollment
    {
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill($attributes)->save();

        return $enrollment;
    }

    private function key(): string
    {
        return str_pad((string) ++$this->sequence, 4, '0', STR_PAD_LEFT);
    }
}
