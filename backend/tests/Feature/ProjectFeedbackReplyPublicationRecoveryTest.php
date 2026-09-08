<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateProjectFeedbackReply;
use App\Models\AiEntitlementUsage;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AiEntitlementBudgetService;
use App\Services\CourseAccessPlanService;
use App\Services\PaidAiCallExecutionService;
use App\Services\ProjectFeedbackThreadService;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProjectFeedbackReplyPublicationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public static function completionEntrypoints(): array
    {
        return ['worker recovery' => [false], 'failed-job recovery' => [true]];
    }

    #[DataProvider('completionEntrypoints')]
    public function test_concurrent_account_deactivation_preserves_the_settled_answer_until_publication(bool $failedJob): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        [$submission, $enrollment, $terms] = $this->fixture();
        $thread = app(ProjectFeedbackThreadService::class)->storeInitialReport(
            $submission, $enrollment, $enrollment->course_id, $terms, 'التقرير الأول'
        );
        $message = ProjectFeedbackMessage::query()->create([
            'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
            'client_request_id' => (string) Str::uuid(), 'role' => 'user',
            'status' => 'sent', 'body' => 'كيف أحسن الشعار؟', 'reserved_tokens' => 1000,
        ]);
        $reply = ProjectFeedbackMessage::query()->create([
            'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
            'client_request_id' => 'reply:'.$message->public_id, 'role' => 'assistant',
            'status' => 'streaming', 'body' => 'بداية',
        ]);
        $budget = app(AiEntitlementBudgetService::class);
        $calls = app(PaidAiCallExecutionService::class);
        $event = $budget->reserve($enrollment, 'project_followup', 1000, 'test/model', $message->public_id);
        $execution = (string) Str::uuid();
        $answer = 'الإجابة المدفوعة الكاملة عن تحسين الشعار';
        $result = ['message' => $answer, 'usage' => ['total_tokens' => 100, 'cost' => .01, 'cost_reported' => true],
            'provider_request_id' => 'reply-publication'];
        $calls->beginForActiveUser($event, $execution, $submission->user_id);
        $calls->landSuccessfulResultForActiveUser($event, $execution, $submission->user_id, $result);
        $budget->settle($event, $result);
        $usage = AiEntitlementUsage::query()->where('enrollment_id', $enrollment->id)
            ->where('feature', 'project_followup')->sole();
        $settledUsage = $usage->getAttributes();

        // Concurrency fault injection, not a live incident: revoke after the
        // worker's initial access check, immediately before its publication lock.
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $submission): void {
            $sql = str_replace(['"', '`'], '', $query->sql);
            if (!$injected && str_contains($sql, 'select user_id from project_feedback_threads')) {
                $injected = true;
                User::query()->whereKey($submission->user_id)->update(['active' => false]);
            }
        });
        $job = new GenerateProjectFeedbackReply($message->id);
        if ($failedJob) {
            $job->failed(new \RuntimeException('Injected worker interruption'));
        } else {
            app()->call([$job, 'handle']);
        }

        self::assertTrue($injected);
        self::assertSame('sent', $message->fresh()->status);
        self::assertSame('streaming', $reply->fresh()->status);
        self::assertSame($answer, data_get($event->fresh()->metadata, 'accepted_response'));
        self::assertNull(data_get($event->fresh()->metadata, 'presentation_completed_at'));
        self::assertEquals($settledUsage, $usage->fresh()->getAttributes());

        User::query()->whereKey($submission->user_id)->update(['active' => true]);
        app()->call([new GenerateProjectFeedbackReply($message->id), 'handle']);
        app()->call([new GenerateProjectFeedbackReply($message->id), 'handle']);

        self::assertSame('completed', $message->fresh()->status);
        self::assertSame('completed', $reply->fresh()->status);
        self::assertSame($answer, $reply->fresh()->body);
        self::assertNotNull(data_get($event->fresh()->metadata, 'presentation_completed_at'));
        self::assertNull(data_get($event->fresh()->metadata, 'accepted_response'));
        self::assertEquals($settledUsage, $usage->fresh()->getAttributes());
        self::assertSame(1, (int) data_get($event->fresh()->metadata, 'provider_call_attempt'));
        self::assertSame(1, $thread->messages()->where('client_request_id', 'reply:'.$message->public_id)->count());
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        $user = new User();
        $user->forceFill(['name' => 'Report learner', 'email' => Str::uuid().'@test.rokn',
            'password' => bcrypt('test'), 'active' => true, 'role' => 'client'])->save();
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1])->save();
        $project = Project::factory()->create(['requirements_text_ar' => 'صمم شعار شجرة', 'requirements_text_en' => 'Draw a tree']);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $section = CourseSection::factory()->project()->create(['course_id' => $course->id, 'module_id' => $module->id,
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id]);
        $plan = CourseAccessPlan::query()->create(['course_id' => $course->id, 'code' => 'mentor',
            'name_ar' => 'تعلم', 'price_coins' => 100, 'minimum_paid_coins' => 100,
            'project_feedback_level' => 'enhanced', 'project_feedback_token_budget' => 4000,
            'project_followup_message_limit' => 10, 'project_followup_token_budget' => 4000,
            'project_followup_budget_usd' => '.200000', 'project_followup_reserve_usd' => '.020000',
            'project_feedback_budget_usd' => '.200000', 'project_feedback_reserve_usd' => '.020000']);
        $terms = app(CourseAccessPlanService::class)->snapshot($plan->fresh());
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id,
            'access_plan_id' => $plan->id, 'access_plan_snapshot' => $terms,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'amount' => 100,
            'final_amount' => 100, 'total_coins' => 100, 'paid_coins' => 100, 'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now()]);
        $transaction = WalletTransaction::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $user->id, 'direction' => 'debit', 'category' => 'course_purchase', 'bucket' => 'paid',
            'amount' => 100, 'paid_amount' => 100, 'reward_amount' => 0, 'balance_after' => 0,
            'paid_balance_after' => 0, 'reward_balance_after' => 0, 'source_type' => Course::class,
            'source_id' => $course->id, 'idempotency_key' => 'reply-publication:'.$order->id,
            'metadata' => ['order_id' => $order->id], 'occurred_at' => now()]);
        $order->forceFill(['wallet_transaction_id' => $transaction->id])->save();
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill(['tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
            'order_id' => $order->id, 'access_plan_id' => $plan->id, 'access_plan_snapshot' => $terms,
            'is_active' => true, 'enrolled_at' => now()])->save();
        $submission = ProjectSubmission::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $user->id, 'project_id' => $project->id, 'idempotency_key' => (string) Str::uuid(),
            'submission_text' => 'محاولة مرتبطة بالشعار', 'submission_metadata' => [],
            'evaluation_snapshot' => ProjectSubmissionEvaluationSnapshot::capture($project, $section, $enrollment, $terms),
            'effort_status' => 'valid', 'review_status' => 'passed', 'review_source' => 'graceful_fallback',
            'submitted_at' => now()->subMinutes(10), 'reviewed_at' => now()->subMinutes(5)]);
        return [$submission, $enrollment, $terms];
    }
}
