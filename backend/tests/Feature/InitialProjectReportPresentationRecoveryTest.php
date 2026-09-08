<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateProjectFeedback;
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
use App\Services\ProjectSubmissionPresenter;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InitialProjectReportPresentationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public static function incompletePresentations(): array
    {
        return [
            'missing thread' => [null, null],
            'processing thread with streaming report' => ['processing', 'streaming'],
            'failed thread with failed report' => ['failed', 'failed'],
            'ready thread missing initial report' => ['ready', null],
            'ready thread with streaming report' => ['ready', 'streaming'],
            'ready thread with failed report' => ['ready', 'failed'],
        ];
    }

    #[DataProvider('incompletePresentations')]
    public function test_ready_marker_with_incomplete_presentation_recovers_the_paid_answer_without_provider_http(
        ?string $threadStatus,
        ?string $messageStatus
    ): void {
        [$submission, $enrollment, $terms] = $this->fixture();
        $threads = app(ProjectFeedbackThreadService::class);
        if ($threadStatus !== null) {
            $initial = $threads->beginInitialReport($submission, $enrollment, $enrollment->course_id, $terms);
            $initial->thread->forceFill(['status' => $threadStatus])->save();
            if ($messageStatus === null) {
                $initial->delete();
            } else {
                $initial->forceFill(['status' => $messageStatus, 'body' => 'نص جزئي'])->save();
            }
        }

        $budget = app(AiEntitlementBudgetService::class);
        $calls = app(PaidAiCallExecutionService::class);
        $event = $budget->reserve($enrollment, 'project_feedback', 1000, 'test/model', $submission->public_id);
        $execution = (string) Str::uuid();
        $answer = 'هذا هو التقرير الكامل المحفوظ عن العمل';
        $result = ['message' => $answer, 'usage' => ['total_tokens' => 100, 'cost' => .01],
            'provider_request_id' => 'presentation-recovery'];
        $calls->beginForActiveUser($event, $execution, $submission->user_id);
        $calls->landSuccessfulResultForActiveUser($event, $execution, $submission->user_id, $result);
        $budget->settle($event, $result);
        // Reproduce a worker stopping after its ready marker but before its
        // separate thread/message transaction. Inputs are already unavailable.
        $submission->forceFill([
            'submission_text' => null,
            'submission_metadata' => ['ai_feedback' => [
                'status' => 'ready', 'request_id' => $submission->public_id,
            ]],
            'updated_at' => now()->subMinutes(5),
        ])->save();

        $this->artisan('ai:recover-stalled-feedback')->assertExitCode(0);

        Bus::assertDispatchedTimes(GenerateProjectFeedback::class, 1);
        Bus::assertDispatched(GenerateProjectFeedback::class, fn ($job): bool => $job->submissionId === $submission->id);
        app()->call([new GenerateProjectFeedback($submission->id), 'handle']);
        $fresh = $submission->fresh();
        $report = $fresh->feedbackThread->messages()->where('client_request_id', 'report:'.$submission->public_id)->sole();
        self::assertSame('ready', $fresh->feedbackThread->status);
        self::assertSame('completed', $report->status);
        self::assertSame($answer, $report->body);
        self::assertSame('passed', $fresh->review_status);
        self::assertSame('ready', app(ProjectSubmissionPresenter::class)->present($fresh)['report_status']);
        self::assertSame(1, (int) data_get($event->fresh()->metadata, 'provider_call_attempt'));
        self::assertSame('completed', $event->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_complete_initial_report_is_not_requeued_for_an_unrelated_pending_reply(): void
    {
        [$submission, $enrollment, $terms] = $this->fixture();
        $thread = app(ProjectFeedbackThreadService::class)->storeInitialReport(
            $submission, $enrollment, $enrollment->course_id, $terms, 'تقرير مكتمل'
        );
        ProjectFeedbackMessage::query()->create([
            'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
            'client_request_id' => (string) Str::uuid(), 'role' => 'user',
            'status' => 'queued', 'body' => 'سؤال متابعة',
        ]);
        $submission->forceFill(['submission_metadata' => ['ai_feedback' => ['status' => 'ready']],
            'updated_at' => now()->subMinutes(5)])->save();

        $this->artisan('ai:recover-stalled-feedback')->assertExitCode(0);

        Bus::assertNotDispatched(GenerateProjectFeedback::class);
        self::assertSame('ready', $submission->fresh()->feedbackThread->status);
        Http::assertNothingSent();
    }

    public function test_recent_ready_marker_preserves_the_existing_recovery_delay(): void
    {
        [$submission, $enrollment, $terms] = $this->fixture();
        app(ProjectFeedbackThreadService::class)->beginInitialReport(
            $submission, $enrollment, $enrollment->course_id, $terms
        );
        $submission->forceFill(['submission_metadata' => ['ai_feedback' => ['status' => 'ready']],
            'updated_at' => now()->subSeconds(30)])->save();

        $this->artisan('ai:recover-stalled-feedback')->assertExitCode(0);

        Bus::assertNotDispatched(GenerateProjectFeedback::class);
        self::assertSame('processing', $submission->fresh()->feedbackThread->status);
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
        $plan = CourseAccessPlan::query()->create(['course_id' => $course->id, 'code' => 'guided',
            'name_ar' => 'تعلم', 'price_coins' => 100, 'minimum_paid_coins' => 100,
            'project_feedback_level' => 'report', 'project_feedback_token_budget' => 4000,
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
            'source_id' => $course->id, 'idempotency_key' => 'presentation-recovery:'.$order->id,
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
