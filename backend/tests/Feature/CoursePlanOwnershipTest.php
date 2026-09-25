<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CoursePlanAttachmentGrantService;
use App\Services\CoursePlanAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CoursePlanOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_last_tier_rolls_back_the_entire_offer_edit_and_keeps_the_receipt(): void
    {
        [$course, $plan, $order, $enrollment] = $this->purchase();
        $before = $course->accessPlans()->orderBy('id')->get()->toArray();
        $receipt = $enrollment->access_plan_snapshot;
        $orderBefore = $order->fresh()->getAttributes();
        $input = $this->offerInput($course);
        $input['basic']['price_coins'] += 100;
        $input['mentor']['minimum_paid_coins'] = $input['mentor']['price_coins'] + 1;

        try {
            app(CoursePlanAuthoringService::class)->syncAdminPlans($course, $input);
            self::fail('An invalid final tier must reject the whole edit.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('access_plans.mentor.minimum_paid_coins', $exception->errors());
        }

        self::assertSame(900, (int) $course->fresh()->price);
        self::assertSame($before, $course->accessPlans()->orderBy('id')->get()->toArray());
        self::assertSame($receipt, $enrollment->fresh()->access_plan_snapshot);
        self::assertSame($orderBefore, $order->fresh()->getAttributes());
    }

    public function test_authoring_and_policy_changes_do_not_rewrite_a_purchased_receipt(): void
    {
        [$course, $plan, $order, $enrollment] = $this->purchase();
        $receipt = $enrollment->access_plan_snapshot;
        $orderBefore = $order->fresh()->getAttributes();
        $input = $this->offerInput($course);
        $input['mentor']['price_coins'] += 500;
        $input['mentor']['certificate_enabled'] = false;
        app(CoursePlanAuthoringService::class)->syncAdminPlans($course, $input);
        app(CoursePlanAuthoringService::class)->syncGlobalAiPolicy([
            'mentor' => ['chat_enabled' => false, 'project_feedback_level' => 'pass_only'],
        ]);

        self::assertFalse($plan->fresh()->chat_enabled);
        self::assertFalse($plan->fresh()->certificate_enabled);
        self::assertSame($receipt, app(CourseAccessPlanService::class)->termsForEnrollment($enrollment->fresh()));
        self::assertSame($orderBefore, $order->fresh()->getAttributes());
    }

    public function test_explicit_grant_updates_the_enrollment_once_without_rewriting_the_order(): void
    {
        [$course, $plan, $order, $enrollment] = $this->purchase(false);
        $orderBefore = $order->fresh()->getAttributes();
        $receipt = $enrollment->access_plan_snapshot;
        $grants = app(CoursePlanAttachmentGrantService::class);

        self::assertSame(1, $grants->grantAttachmentsToCurrentEnrollments($course, true, true));
        $updated = $enrollment->fresh()->access_plan_snapshot;
        self::assertTrue($updated['chat_attachments_enabled']);
        self::assertTrue($updated['project_followup_attachments_enabled']);
        foreach ($receipt as $key => $value) {
            if (!in_array($key, [
                'chat_attachments_enabled', 'chat_attachment_max_files',
                'project_followup_attachments_enabled', 'project_followup_attachment_max_files',
            ], true)) self::assertSame($value, $updated[$key], $key);
        }
        self::assertSame($orderBefore, $order->fresh()->getAttributes());
        self::assertSame(0, $grants->grantAttachmentsToCurrentEnrollments($course, true, true));
    }

    public function test_granting_one_feature_never_reduces_either_existing_attachment_allowance(): void
    {
        [$course, $plan, , $enrollment] = $this->purchase();
        $receipt = $enrollment->access_plan_snapshot;
        $receipt['chat_attachment_max_files'] = 5;
        $receipt['project_followup_attachment_max_files'] = 5;
        $enrollment->update(['access_plan_snapshot' => $receipt]);
        $plan->update(['chat_attachment_max_files' => 1, 'project_followup_attachment_max_files' => 1]);

        app(CoursePlanAttachmentGrantService::class)->grantAttachmentsToCurrentEnrollments($course, false, true);

        self::assertSame($receipt, $enrollment->fresh()->access_plan_snapshot);
    }

    #[DataProvider('inactiveEnrollments')]
    public function test_grants_skip_inactive_or_expired_enrollments(bool $active, bool $expired): void
    {
        [$course, , , $enrollment] = $this->purchase(false);
        $enrollment->update([
            'is_active' => $active,
            'expires_at' => $expired ? now()->subDay() : null,
        ]);
        $receipt = $enrollment->fresh()->access_plan_snapshot;
        self::assertSame(0, app(CoursePlanAttachmentGrantService::class)
            ->grantAttachmentsToCurrentEnrollments($course, true, true));
        self::assertSame($receipt, $enrollment->fresh()->access_plan_snapshot);
    }

    public static function inactiveEnrollments(): array
    {
        return [[false, false], [true, true]];
    }

    /** @return array{Course, CourseAccessPlan, Order, CourseEnrollment} */
    private function purchase(bool $attachments = true): array
    {
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس اختبار الملكية', 'price' => 900,
            'authoring_version' => 1, 'is_coming_soon' => true, 'is_catalog_visible' => false,
        ]);
        app(CoursePlanAuthoringService::class)->createDefaults($course);
        $plan = $course->accessPlans()->where('code', CourseAccessPlan::MENTOR)->firstOrFail();
        $snapshot = app(CourseAccessPlanService::class)->snapshot($plan);
        if (!$attachments) {
            $snapshot['chat_attachments_enabled'] = false;
            $snapshot['chat_attachment_max_files'] = 0;
            $snapshot['project_followup_attachments_enabled'] = false;
            $snapshot['project_followup_attachment_max_files'] = 0;
        }
        $user = User::query()->forceCreate([
            'name' => 'Plan learner', 'email' => 'plan-owner@example.test',
            'password' => 'unused', 'role' => 'client', 'active' => true,
        ]);
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
        return [$course, $plan, $order, $enrollment];
    }

    private function offerInput(Course $course): array
    {
        return $course->accessPlans()->get()->mapWithKeys(fn (CourseAccessPlan $plan): array => [
            $plan->code => [
                'name_ar' => $plan->name_ar, 'price_coins' => $plan->price_coins,
                'minimum_paid_coins' => $plan->minimum_paid_coins,
                'is_active' => true, 'certificate_enabled' => $plan->certificate_enabled,
            ],
        ])->all();
    }
}
