<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Exceptions\CourseCodeUnavailable;
use App\Jobs\SendUserPushNotification;
use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\CourseEnrollment;
use App\Models\CourseGrantClaim;
use App\Models\Order;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\CourseCodeRedemptionService;
use App\Services\StudentNotificationService;
use App\Support\CourseCodeRejection;
use App\Support\PrivacyFingerprint;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class CourseCodeRedemptionOwnershipTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        // ApiTestCase seeds an unrelated inbox item; this suite counts receipts
        // produced by redemption, starting with an empty test inbox.
        StudentNotification::query()->delete();
        $this->code()->update(['is_grant' => true, 'max_uses' => 10]);
        $this->actingAs($this->user, 'api');
    }

    public function test_one_transaction_creates_a_zero_value_receipt_without_charging_the_wallet(): void
    {
        $balance = (int) $this->user->fresh()->wallet_coins;
        $walletEntries = DB::table('wallet_transactions')->count();
        $response = $this->redeem()->assertOk()
            ->assertJsonPath('data.access_type', 'scholarship')
            ->assertJsonPath('data.chat_available', false)
            ->assertJsonPath('data.projects_available', false)
            ->assertJsonPath('data.certificate_available', false)
            ->assertJsonPath('data.learning_access', true);
        self::assertSame($this->courseId, $response->json('data.course.id'));
        $order = Order::query()->sole();
        $bill = Bill::query()->sole();
        self::assertSame(Order::PAYMENT_METHOD_COURSE_CODE, $order->payment_method);
        self::assertSame(Order::FINANCIAL_SETTLED, $order->financial_status);
        self::assertSame(0.0, (float) $order->final_amount);
        self::assertNull($order->coupon_code);
        self::assertSame('Course code grant #' . $this->code()->id, $order->notes);
        self::assertSame('Course code grant #' . $this->code()->id, $bill->notes);
        self::assertSame(0.0, (float) $bill->total_amount);
        self::assertSame(Bill::PAYMENT_STATUS_PAID, $bill->payment_status);
        self::assertSame((int) $order->id, (int) $bill->order_id);
        self::assertSame((int) $order->id, (int) CourseEnrollment::query()->sole()->order_id);
        self::assertSame(1, CourseGrantClaim::query()->count());
        self::assertSame(1, DB::table('course_code_usages')->count());
        self::assertSame(1, (int) $this->code()->used_count);
        self::assertSame($balance, (int) $this->user->fresh()->wallet_coins);
        self::assertSame($walletEntries, DB::table('wallet_transactions')->count());
        self::assertSame('course-enrolled:order:' . $order->id, StudentNotification::query()->sole()->delivery_key);
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
    }

    public function test_successful_replay_preserves_the_receipt_and_does_not_consume_another_use(): void
    {
        $this->redeem()->assertOk();
        $order = Order::query()->sole()->getAttributes();
        $bill = Bill::query()->sole()->getAttributes();
        // Quota/date changes after success do not make the learner pay again.
        $this->code()->update(['max_uses' => 1, 'is_active' => false, 'expiry_date' => now()->subDay()]);
        $this->redeem()->assertOk()->assertJsonPath('data.already_enrolled', true);
        self::assertSame($order, Order::query()->sole()->getAttributes());
        self::assertSame($bill, Bill::query()->sole()->getAttributes());
        self::assertSame(1, (int) $this->code()->used_count);
        self::assertSame(1, CourseGrantClaim::query()->count());
        self::assertSame(1, DB::table('course_code_usages')->count());
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
    }

    public function test_existing_effective_paid_access_is_not_downgraded_or_consumed_as_a_grant(): void
    {
        $order = $this->paidOrder();
        $enrollment = CourseEnrollment::query()->create([
            'user_id' => $this->user->id, 'course_id' => $this->courseId, 'order_id' => $order->id,
            'is_active' => true, 'enrolled_at' => now(), 'access_granted_at' => now(),
        ]);
        $before = $enrollment->fresh()->getAttributes();
        $this->redeem()->assertOk()->assertJsonPath('data.already_enrolled', true);
        self::assertSame($before, $enrollment->fresh()->getAttributes());
        self::assertSame(1, Order::query()->count());
        self::assertSame(0, CourseGrantClaim::query()->count());
        self::assertSame(0, DB::table('course_code_usages')->count());
        self::assertSame(0, (int) $this->code()->used_count);
        Bus::assertNothingDispatched();
    }

    public function test_new_grant_replaces_revoked_enrollment_without_erasing_completion(): void
    {
        $order = $this->paidOrder(Order::FINANCIAL_CHARGEBACK);
        $enrollment = CourseEnrollment::query()->create([
            'user_id' => $this->user->id, 'course_id' => $this->courseId, 'order_id' => $order->id,
            'is_active' => false, 'enrolled_at' => now()->subMonth(),
            'completed_curriculum_revision' => 2, 'curriculum_completed_at' => now()->subDay(),
        ]);
        $this->redeem()->assertOk()
            ->assertJsonPath('data.learning_access', true)
            ->assertJsonPath('data.access_type', 'scholarship')
            ->assertJsonPath('data.chat_available', false);
        $enrollment->refresh();
        self::assertNotSame((int) $order->id, (int) $enrollment->order_id);
        self::assertNull($enrollment->access_plan_order_id);
        self::assertNull($enrollment->access_plan_snapshot);
        self::assertSame(2, (int) $enrollment->completed_curriculum_revision);
        self::assertSame(Order::FINANCIAL_CHARGEBACK, $order->fresh()->financial_status);
        self::assertSame(1, CourseEnrollment::query()->count());
    }

    #[DataProvider('denials')]
    public function test_preview_and_redemption_share_the_rejection_without_writes(string $case, CourseCodeRejection $reason): void
    {
        $code = $this->code();
        match ($case) {
            'disabled' => $code->update(['is_active' => false]),
            'expired' => $code->update(['expiry_date' => now()->subSecond()]),
            'future' => $code->update(['start_date' => now()->addDay()]),
            'exhausted' => $code->update(['max_uses' => 0]),
            'email' => $code->update(['allowed_email_domains' => ['college.edu']]),
            'hidden' => Course::query()->whereKey($this->courseId)->update(['is_catalog_visible' => false]),
        };
        $this->postJson('/api/v1/course-codes/check', ['code' => 'TESTCODE'])
            ->assertOk()->assertJsonPath('data.can_use', false)
            ->assertJsonPath('data.error_message', $reason->message());
        $this->redeem()->assertStatus($case === 'hidden' ? 409 : 400)
            ->assertJsonPath('message', $reason->message());
        self::assertSame(0, DB::table('course_code_usages')->count());
        self::assertSame(0, CourseGrantClaim::query()->count());
        self::assertSame(0, Order::query()->count());
        Bus::assertNothingDispatched();
    }

    public static function denials(): iterable
    {
        yield 'disabled' => ['disabled', CourseCodeRejection::DISABLED];
        yield 'expired' => ['expired', CourseCodeRejection::EXPIRED];
        yield 'not started' => ['future', CourseCodeRejection::NOT_STARTED];
        yield 'quota exhausted' => ['exhausted', CourseCodeRejection::EXHAUSTED];
        yield 'institution email' => ['email', CourseCodeRejection::EMAIL_NOT_ELIGIBLE];
        yield 'course retired' => ['hidden', CourseCodeRejection::COURSE_UNAVAILABLE];
    }

    public function test_preview_is_read_only_and_its_decision_is_rechecked_at_redemption(): void
    {
        $this->postJson('/api/v1/course-codes/check', ['code' => 'TESTCODE'])
            ->assertOk()->assertJsonPath('data.can_use', true);
        self::assertSame(0, Order::query()->count());
        self::assertSame(0, DB::table('course_code_usages')->count());
        $this->code()->update(['max_uses' => 0]);
        $this->redeem()->assertStatus(400)->assertJsonPath('message', CourseCodeRejection::EXHAUSTED->message());
        self::assertSame(0, CourseGrantClaim::query()->count());
    }

    public function test_writer_checks_fresh_learner_email_not_the_callers_stale_user_object(): void
    {
        $this->user->forceFill(['email' => 'student@college.edu', 'email_verified_at' => now()])->save();
        $this->code()->update(['allowed_email_domains' => [' @COLLEGE.EDU ']]);
        User::query()->whereKey($this->user->id)->update(['email_verified_at' => null]);
        try {
            app(CourseCodeRedemptionService::class)->redeem($this->user, 'TESTCODE');
            self::fail('A stale verified email must not admit a new grant.');
        } catch (CourseCodeUnavailable $exception) {
            self::assertSame(CourseCodeRejection::EMAIL_NOT_ELIGIBLE, $exception->reason);
        }
        self::assertSame(0, DB::table('course_code_usages')->count());
    }

    public function test_verified_domain_normalization_still_admits_the_institutional_grant(): void
    {
        $this->user->forceFill(['email' => 'student@COLLEGE.EDU', 'email_verified_at' => now()])->save();
        $this->code()->update(['allowed_email_domains' => [' @college.edu ']]);
        $this->redeem()->assertOk()->assertJsonPath('data.access_type', 'scholarship');
        self::assertSame(CourseGrantClaim::emailHash('student@college.edu'), CourseGrantClaim::query()->sole()->normalized_email_hash);
    }

    public function test_current_course_target_is_checked_inside_the_writer(): void
    {
        $other = Course::query()->create(['name_ar' => 'كورس آخر', 'name_en' => 'Other course', 'active' => true]);
        $this->code()->update(['course_id' => $other->id]);
        try {
            app(CourseCodeRedemptionService::class)->redeem($this->user, 'TESTCODE', $this->courseId);
            self::fail('A stale preview cannot redirect a grant to a different course.');
        } catch (CourseCodeUnavailable $exception) {
            self::assertSame(CourseCodeRejection::COURSE_MISMATCH, $exception->reason);
            self::assertSame((int) $other->id, $exception->courseCode->targetCourseId());
        }
        self::assertSame(0, Order::query()->count());
        self::assertSame(0, CourseGrantClaim::query()->count());
    }

    public function test_receipt_failure_rolls_back_the_whole_grant_and_retry_succeeds_once(): void
    {
        $failOnce = true;
        Event::listen('eloquent.creating: ' . Bill::class, static function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('bill unavailable');
            }
        });
        $this->redeem()->assertStatus(500);
        $this->assertNoGrantWrites();
        $this->redeem()->assertOk();
        self::assertSame(1, Order::query()->count());
        self::assertSame(1, Bill::query()->count());
        self::assertSame(1, DB::table('course_code_usages')->count());
    }

    public function test_unrelated_unique_financial_error_is_not_mislabeled_as_an_existing_grant(): void
    {
        Schema::table('bills', fn (Blueprint $table) => $table->unique('bill_number'));
        $number = Bill::numberForOrder((int) Order::query()->max('id') + 1);
        Bill::query()->create(['bill_number' => $number, 'user_id' => $this->user->id]);
        $this->redeem()->assertStatus(500)->assertJsonMissing(['code' => 'grant_already_claimed']);
        self::assertSame(0, Order::query()->count());
        self::assertSame(0, CourseGrantClaim::query()->count());
        self::assertSame(0, DB::table('course_code_usages')->count());
        self::assertSame(0, (int) $this->code()->used_count);
        self::assertSame(1, Bill::query()->count());
    }

    public function test_notification_storage_failure_cannot_leave_a_partly_consumed_grant(): void
    {
        $this->mock(StudentNotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyUser')->once()->andThrow(new \RuntimeException('inbox unavailable'));
        });
        $this->redeem()->assertStatus(500);
        $this->assertNoGrantWrites();
    }

    public function test_broker_outage_does_not_undo_a_committed_grant(): void
    {
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('broker unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $this->redeem()->assertOk();
        $this->redeem()->assertOk()->assertJsonPath('data.already_enrolled', true);
        self::assertSame(1, Order::query()->count());
        self::assertSame(1, CourseGrantClaim::query()->count());
        self::assertSame(1, StudentNotification::query()->count());
        self::assertNull(StudentNotification::query()->sole()->push_attempted_at);
    }

    public function test_enclosing_rollback_removes_grant_rows_and_does_not_send_a_push(): void
    {
        DB::beginTransaction();
        try {
            $result = app(CourseCodeRedemptionService::class)->redeem($this->user, 'TESTCODE', $this->courseId);
            self::assertFalse($result->alreadyEnrolled);
            self::assertSame(1, Order::query()->count());
            Bus::assertNothingDispatched();
        } finally {
            DB::rollBack();
        }
        $this->assertNoGrantWrites();
    }

    public function test_existing_zero_order_and_deleted_bill_are_reused_without_a_second_receipt(): void
    {
        $order = Order::query()->create([
            'user_id' => $this->user->id, 'course_id' => $this->courseId, 'course_code_id' => $this->code()->id,
            'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE, 'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_PENDING, 'amount' => 0, 'final_amount' => 0,
        ]);
        $bill = Bill::query()->create([
            'order_id' => $order->id, 'user_id' => $this->user->id,
            'bill_number' => 'historical-zero-receipt', 'payment_status' => Bill::PAYMENT_STATUS_PENDING,
        ]);
        $bill->delete();
        $this->redeem()->assertOk();
        self::assertSame(1, Order::query()->count());
        self::assertSame(1, Bill::withTrashed()->count());
        self::assertNull($bill->fresh()->deleted_at);
        self::assertSame('historical-zero-receipt', $bill->fresh()->bill_number);
        self::assertSame(Bill::PAYMENT_STATUS_PAID, $bill->fresh()->payment_status);
        self::assertSame(Order::FINANCIAL_SETTLED, $order->fresh()->financial_status);
        self::assertSame((int) $order->id, (int) CourseEnrollment::query()->sole()->order_id);
    }

    public function test_reversed_code_order_is_not_repaired_into_a_valid_grant(): void
    {
        $order = Order::query()->create([
            'user_id' => $this->user->id, 'course_id' => $this->courseId, 'course_code_id' => $this->code()->id,
            'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE, 'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_REFUNDED, 'amount' => 0, 'final_amount' => 0,
        ]);
        $this->redeem()->assertStatus(500);
        self::assertSame(Order::FINANCIAL_REFUNDED, $order->fresh()->financial_status);
        self::assertSame(0, DB::table('course_code_usages')->count());
        self::assertSame(0, CourseGrantClaim::query()->count());
        self::assertSame(0, CourseEnrollment::query()->count());
    }

    public function test_a_used_code_cannot_resurrect_withdrawn_access(): void
    {
        $this->redeem()->assertOk();
        CourseEnrollment::query()->update(['is_active' => false]);
        $this->redeem()->assertStatus(409)->assertJsonPath('code', 'grant_already_claimed');
        self::assertFalse((bool) CourseEnrollment::query()->sole()->is_active);
        self::assertSame(1, (int) $this->code()->used_count);
    }

    public function test_a_non_grant_code_keeps_its_existing_full_access_contract(): void
    {
        $this->code()->update(['is_grant' => false]);
        $this->redeem()->assertOk()->assertJsonPath('data.access_type', 'course_code')
            ->assertJsonPath('data.learning_access', true);
        self::assertSame(0, CourseGrantClaim::query()->count());
        self::assertSame(1, DB::table('course_code_usages')->count());
    }

    public function test_missing_and_retired_codes_are_rejected_without_financial_writes(): void
    {
        foreach (['check', 'redeem'] as $action) {
            $this->postJson('/api/v1/course-codes/' . $action, ['code' => 'MISSING'])
                ->assertNotFound()->assertJsonPath('success', false);
        }
        $this->code()->update(['type' => 'multiple_lessons']);
        foreach (['check', 'redeem'] as $action) {
            $this->postJson('/api/v1/course-codes/' . $action, ['code' => 'TESTCODE'])
                ->assertStatus(410)->assertJsonPath('code', 'legacy_partial_code_retired');
        }
        $this->assertNoGrantWrites();
    }

    public function test_usage_history_does_not_restore_withdrawn_learning_access(): void
    {
        $this->redeem()->assertOk();
        CourseEnrollment::query()->update(['is_active' => false]);
        $this->getJson('/api/v1/course-codes/my-codes')->assertOk()
            ->assertJsonPath('data.0.code', 'TESTCODE')
            ->assertJsonPath('data.0.learning_access', false)
            ->assertJsonPath('data.0.chat_available', false)
            ->assertJsonPath('data.0.certificate_available', false);
        self::assertFalse((bool) CourseEnrollment::query()->sole()->is_active);
        self::assertSame(1, (int) $this->code()->used_count);
    }

    public function test_only_fingerprints_of_request_context_are_persisted(): void
    {
        app(CourseCodeRedemptionService::class)->redeem(
            user: $this->user, rawCode: 'TESTCODE', expectedCourseId: $this->courseId,
            requestIp: '192.0.2.11', userAgent: 'Rokn test phone'
        );
        $usage = DB::table('course_code_usages')->sole();
        self::assertSame(PrivacyFingerprint::make('192.0.2.11'), $usage->ip_address);
        self::assertSame(PrivacyFingerprint::make('Rokn test phone'), $usage->user_agent);
        self::assertStringNotContainsString('TESTCODE', Order::query()->sole()->notes);
    }

    private function code(): CourseCode
    {
        return CourseCode::query()->where('code', 'TESTCODE')->firstOrFail();
    }

    private function redeem(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/course-codes/redeem', ['code' => 'TESTCODE', 'course_id' => $this->courseId]);
    }

    private function paidOrder(string $financialStatus = Order::FINANCIAL_SETTLED): Order
    {
        return Order::query()->create([
            'user_id' => $this->user->id, 'course_id' => $this->courseId,
            'payment_method' => Order::PAYMENT_METHOD_WALLET, 'status' => Order::STATUS_APPROVED,
            'financial_status' => $financialStatus, 'amount' => 250, 'final_amount' => 250,
        ]);
    }

    private function assertNoGrantWrites(): void
    {
        self::assertSame(0, Order::query()->count());
        self::assertSame(0, Bill::withTrashed()->count());
        self::assertSame(0, CourseEnrollment::query()->count());
        self::assertSame(0, CourseGrantClaim::query()->count());
        self::assertSame(0, DB::table('course_code_usages')->count());
        self::assertSame(0, StudentNotification::query()->count());
        self::assertSame(0, (int) $this->code()->used_count);
        Bus::assertNothingDispatched();
    }
}
