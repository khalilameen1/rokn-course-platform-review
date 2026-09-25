<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\CourseCodeUnavailable;
use App\Jobs\SendUserPushNotification;
use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\CourseEnrollment;
use App\Models\CourseGrantClaim;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\FinancialEntitlementHold;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CourseCodeRedemptionService;
use App\Services\CourseEntitlementService;
use App\Support\CourseCodeRejection;
use App\Support\FinancialProvenanceSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Production migrations and real commits, with the retired-column SQLite bridge below. */
final class CourseCodeRedemptionSchemaTest extends TestCase
{
    private Course $course;
    private CourseCode $code;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        \Tests\Support\ProductionCourseCodeSchema::applySqliteBridge();
        Bus::fake();
        Http::preventStrayRequests();
        $this->student = $this->student('original');
        $this->course = Course::factory()->make();
        $this->course->forceFill([
            'tenant_id' => 1, 'is_coming_soon' => false, 'is_catalog_visible' => true,
        ])->save();
        $lesson = Lesson::query()->create([
            'list_id' => $this->course->id, 'title_ar' => 'المقطع', 'duration_minutes' => 1,
        ]);
        $module = CourseModule::query()->create([
            'course_id' => $this->course->id, 'title_ar' => 'المحتوى', 'order' => 1,
        ]);
        CourseSection::query()->create([
            'course_id' => $this->course->id, 'module_id' => $module->id, 'title_ar' => 'المقطع',
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id, 'order' => 1,
        ]);
        $this->code = CourseCode::query()->create([
            'code' => 'SCHEMA-GRANT', 'name' => 'Grant', 'type' => 'course',
            'course_id' => $this->course->id, 'is_grant' => true,
            'is_active' => true, 'used_count' => 0, 'max_uses' => 1,
        ]);
    }

    public function test_real_schema_creates_one_zero_receipt_and_replays_without_another_claim(): void
    {
        $service = app(CourseCodeRedemptionService::class);
        self::assertFalse($service->redeem($this->student, $this->code->code)->alreadyEnrolled);
        self::assertTrue($service->redeem($this->student, $this->code->code)->alreadyEnrolled);
        $order = Order::query()->sole();
        $bill = Bill::query()->sole();
        $claim = CourseGrantClaim::query()->sole();
        self::assertSame((int) $order->id, (int) $bill->order_id);
        self::assertSame((int) $order->id, (int) CourseEnrollment::query()->sole()->order_id);
        self::assertSame((int) $claim->course_code_usage_id, (int) DB::table('course_code_usages')->sole()->id);
        self::assertSame(0.0, (float) $bill->total_amount);
        self::assertSame(0, WalletTransaction::query()->count());
        self::assertSame(1, (int) $this->code->fresh()->used_count);
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
    }

    public function test_new_grant_replaces_held_order_without_resolving_its_financial_debt(): void
    {
        self::assertTrue(FinancialProvenanceSchema::available());
        $paid = Order::query()->create([
            'user_id' => $this->student->id, 'course_id' => $this->course->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_CHARGEBACK,
            'amount' => 250, 'final_amount' => 250,
        ]);
        $enrollment = CourseEnrollment::query()->create([
            'user_id' => $this->student->id, 'course_id' => $this->course->id,
            'order_id' => $paid->id, 'is_active' => true, 'enrolled_at' => now()->subMonth(),
            'completed_curriculum_revision' => 2, 'curriculum_completed_at' => now()->subDay(),
            'completed_with_projects' => true,
        ]);
        $hold = FinancialEntitlementHold::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $this->student->id,
            'course_id' => $this->course->id, 'course_order_id' => $paid->id,
            'source_order_id' => $paid->id, 'enrollment_id' => $enrollment->id,
            'status' => FinancialEntitlementHold::STATUS_ACTIVE,
            'entitlement_scope' => 'course', 'held_at' => now(),
        ]);
        $before = $enrollment->fresh()->only([
            'enrolled_at', 'completed_curriculum_revision', 'curriculum_completed_at', 'completed_with_projects',
        ]);
        $access = app(CourseEntitlementService::class);
        self::assertFalse($access->hasLearningAccess($this->student->id, $this->course->id));
        self::assertFalse(app(CourseCodeRedemptionService::class)
            ->redeem($this->student, $this->code->code)->alreadyEnrolled);
        $rights = $access->entitlementFor($this->student->id, $this->course->id);
        self::assertTrue($rights['has_learning_access']);
        self::assertSame('scholarship', $rights['access_type']);
        self::assertFalse($rights['chat_available']);
        self::assertFalse($rights['projects_available']);
        self::assertFalse($rights['certificate_available']);
        self::assertEquals($before, $enrollment->fresh()->only(array_keys($before)));
        self::assertNotSame((int) $paid->id, (int) $enrollment->fresh()->order_id);
        self::assertSame(FinancialEntitlementHold::STATUS_ACTIVE, $hold->fresh()->status);
        self::assertSame(Order::FINANCIAL_CHARGEBACK, $paid->fresh()->financial_status);
        self::assertSame(0, WalletTransaction::query()->count());
    }

    public function test_real_bill_unique_constraint_failure_rolls_back_all_grant_writes(): void
    {
        $existingOrder = Order::query()->create([
            'user_id' => $this->student->id, 'course_id' => $this->course->id,
            'status' => Order::STATUS_PENDING, 'payment_method' => Order::PAYMENT_METHOD_WALLET,
            'amount' => 250, 'final_amount' => 250,
        ]);
        Bill::query()->create([
            'order_id' => $existingOrder->id, 'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'bill_number' => Bill::numberForOrder((int) $existingOrder->id + 1),
        ]);
        try {
            app(CourseCodeRedemptionService::class)->redeem($this->student, $this->code->code);
            self::fail('A colliding financial receipt must not commit access.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, Order::query()->count());
            self::assertSame(1, Bill::query()->count());
            self::assertSame(0, CourseGrantClaim::query()->count());
            self::assertSame(0, CourseEnrollment::query()->count());
            self::assertSame(0, DB::table('course_code_usages')->count());
            self::assertSame(0, (int) $this->code->fresh()->used_count);
            Bus::assertNothingDispatched();
        }
    }

    public function test_durable_email_claim_survives_account_email_change_and_support_reassignment(): void
    {
        $service = app(CourseCodeRedemptionService::class);
        $this->code->update(['max_uses' => 10]);
        $originalEmail = $this->student->email;
        $service->redeem($this->student, $this->code->code);
        $this->student->update(['email' => 'changed-grant@example.test']);
        CourseGrantClaim::query()->sole()->update(['status' => CourseGrantClaim::STATUS_REASSIGNED]);
        $other = $this->student('other');
        $other->update(['email' => $originalEmail]);
        try {
            $service->redeem($other, $this->code->code);
            self::fail('Changing the account email cannot recycle the original grant identity.');
        } catch (CourseCodeUnavailable $exception) {
            self::assertSame(CourseCodeRejection::GRANT_ALREADY_CLAIMED, $exception->reason);
            self::assertSame(1, CourseGrantClaim::query()->count());
            self::assertSame(1, Order::query()->count());
            self::assertSame(1, (int) $this->code->fresh()->used_count);
        }
    }

    public function test_another_learner_cannot_consume_the_last_place_twice(): void
    {
        $service = app(CourseCodeRedemptionService::class);
        $service->redeem($this->student, $this->code->code);
        try {
            $service->redeem($this->student('second'), $this->code->code);
            self::fail('The last available use has already been consumed.');
        } catch (CourseCodeUnavailable $exception) {
            self::assertSame(CourseCodeRejection::EXHAUSTED, $exception->reason);
            self::assertSame(1, CourseEnrollment::query()->count());
            self::assertSame(1, CourseGrantClaim::query()->count());
        }
    }

    private function student(string $suffix): User
    {
        return User::query()->forceCreate([
            'name' => 'Grant ' . $suffix, 'email' => $suffix . '-grant@example.test',
            'role' => 'client', 'active' => true, 'email_verified_at' => now(),
        ]);
    }
}
