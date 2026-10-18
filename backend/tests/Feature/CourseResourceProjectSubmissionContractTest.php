<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CourseAccessPlanService;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CourseResourceProjectSubmissionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_map_exposes_the_canonical_submission_summary_without_transcript_loading(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
        [$user, $course, $project, $submission] = $this->reportedSubmission();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($user, 'api')
            ->getJson("/api/v1/courses/{$course->id}/details")
            ->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $section = collect($response->json('data.modules.0.sections'))
            ->firstWhere('content_id', $project->id);
        self::assertIsArray($section);
        $summary = data_get($section, 'content.latest_submission');
        self::assertIsArray($summary);
        self::assertSame($submission->public_id, $summary['id']);
        foreach ([
            'submission_status', 'can_submit', 'can_continue', 'feedback_level', 'report_enabled',
            'report_status', 'reply_enabled', 'can_reply', 'feedback', 'poll_after_seconds',
        ] as $field) {
            self::assertArrayHasKey($field, $summary, $field);
        }
        self::assertTrue($summary['can_continue']);
        self::assertFalse($summary['can_submit']);
        self::assertSame('أتممت المشروع', $summary['feedback']);
        // Only the canonical fields are published; no legacy aliases.
        foreach (['passed', 'can_resubmit', 'needs_resubmission', 'provisional', 'authoritative', 'is_reviewing'] as $alias) {
            self::assertArrayNotHasKey($alias, $summary, $alias);
        }
        // The course map carries the thread summary, never the transcript.
        self::assertSame([], data_get($summary, 'feedback_thread.messages', []));
        self::assertStringNotContainsString('تقرير المشروع الكامل', (string) $response->getContent());
        self::assertMatchesRegularExpression('/from\s+"project_feedback_threads"/i', $queries);
        self::assertDoesNotMatchRegularExpression('/from\s+"project_feedback_messages"/i', $queries);
    }

    // An existing report uses the current reply capability after a plan upgrade:
    // ProjectSubmissionPresenterUpgradeTest::test_report_submission_exposes_current_enhanced_reply_capability_after_paid_upgrade
    // and ::test_report_submission_without_upgrade_stays_report_only (both assert feedback_level,
    // can_reply and reply_enabled with and without the transcript).

    /** @return array{0:User,1:Course,2:Project,3:ProjectSubmission} */
    private function reportedSubmission(): array
    {
        $user = new User;
        $user->forceFill(['name' => 'Map learner', 'email' => Str::uuid().'@test.rokn',
            'password' => bcrypt('test'), 'active' => true, 'role' => 'client'])->save();
        $course = new Course;
        $course->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس الخريطة', 'price' => 400,
            'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1, 'published_at' => now(),
        ])->save();
        $project = Project::factory()->create(['requirements_text_ar' => 'صمم شعار شجرة', 'requirements_text_en' => 'Draw a tree']);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $section = CourseSection::factory()->project()->create(['course_id' => $course->id, 'module_id' => $module->id,
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 1]);
        $plan = CourseAccessPlan::query()->create(['course_id' => $course->id, 'code' => 'guided',
            'name_ar' => 'تعلم', 'price_coins' => 400, 'minimum_paid_coins' => 100, 'is_active' => true,
            'project_feedback_level' => 'report', 'project_feedback_token_budget' => 4000,
            'project_feedback_budget_usd' => '.200000', 'project_feedback_reserve_usd' => '.020000']);
        $terms = app(CourseAccessPlanService::class)->snapshot($plan->fresh());
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id,
            'access_plan_id' => $plan->id, 'access_plan_snapshot' => $terms,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'amount' => 400,
            'final_amount' => 400, 'total_coins' => 400, 'paid_coins' => 400, 'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now()]);
        $transaction = WalletTransaction::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $user->id, 'direction' => 'debit', 'category' => 'course_purchase', 'bucket' => 'paid',
            'amount' => 400, 'paid_amount' => 400, 'reward_amount' => 0, 'balance_after' => 0,
            'paid_balance_after' => 0, 'reward_balance_after' => 0, 'source_type' => Course::class,
            'source_id' => $course->id, 'idempotency_key' => 'course-map-test:'.$order->id,
            'metadata' => ['order_id' => $order->id], 'occurred_at' => now()]);
        $order->forceFill(['wallet_transaction_id' => $transaction->id])->save();
        $enrollment = new CourseEnrollment;
        $enrollment->forceFill(['tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
            'order_id' => $order->id, 'access_plan_id' => $plan->id, 'access_plan_snapshot' => $terms,
            'is_active' => true, 'enrolled_at' => now(), 'access_granted_at' => now()])->save();
        $submission = ProjectSubmission::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $user->id, 'project_id' => $project->id, 'idempotency_key' => (string) Str::uuid(),
            'submission_text' => 'محاولة مكتملة', 'submission_metadata' => ['ai_feedback' => ['status' => 'completed']],
            'evaluation_snapshot' => ProjectSubmissionEvaluationSnapshot::capture($project, $section, $enrollment, $terms),
            'effort_status' => ProjectSubmission::EFFORT_VALID, 'review_status' => ProjectSubmission::STATUS_PASSED,
            'review_source' => 'ai', 'score' => 85, 'feedback' => 'أتممت المشروع',
            'submitted_at' => now(), 'reviewed_at' => now()]);
        $thread = ProjectFeedbackThread::query()->create(['public_id' => (string) Str::uuid(),
            'submission_id' => $submission->id, 'user_id' => $user->id, 'course_id' => $course->id,
            'project_id' => $project->id, 'enrollment_id' => $enrollment->id, 'access_plan_id' => $plan->id,
            'feedback_level' => CourseAccessPlan::FEEDBACK_REPORT, 'can_reply' => false, 'status' => 'ready']);
        ProjectFeedbackMessage::query()->create(['public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
            'role' => 'assistant', 'client_request_id' => 'report:'.$submission->public_id,
            'status' => ProjectFeedbackMessage::COMPLETED, 'body' => 'تقرير المشروع الكامل', 'completed_at' => now()]);

        return [$user, $course, $project, $submission];
    }
}
