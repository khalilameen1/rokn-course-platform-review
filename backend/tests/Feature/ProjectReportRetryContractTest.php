<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateProjectFeedback;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AiConsentService;
use App\Services\AiEntitlementBudgetService;
use App\Services\CourseAccessPlanService;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectReportRetryContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_retry_route_is_throttled_and_the_response_advertises_only_safe_retries(): void
    {
        [$submission] = $this->failedReport();
        $url = "/api/v1/project-submissions/{$submission->public_id}/report/retry";
        $this->actingAs($submission->user, 'api');

        $this->getJson("/api/v1/project-submissions/{$submission->public_id}")
            ->assertOk()
            ->assertJsonPath('data.can_retry_report', true)
            ->assertJsonPath('data.report_retry_endpoint', $url);

        $this->postJson($url)->assertStatus(202)
            ->assertJsonPath('data.can_retry_report', false)
            ->assertJsonPath('data.report_retry_endpoint', null);
        Bus::assertDispatchedTimes(GenerateProjectFeedback::class, 1);

        // A queued report is not advertised or accepted as a second retry.
        $this->postJson($url)->assertStatus(409)
            ->assertJsonPath('code', 'project_report_retry_not_terminal')
            ->assertJsonPath('data.can_retry_report', false);
        $this->postJson($url)->assertStatus(409);
        // The fourth attempt within a minute is throttled before the service.
        $this->postJson($url)->assertStatus(429);
        Bus::assertDispatchedTimes(GenerateProjectFeedback::class, 1);

        [$other] = $this->failedReport();
        $this->actingAs($other->user, 'api');
        $this->postJson($url)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_worker_uses_the_persisted_retry_request_identity(): void
    {
        [$submission, $enrollment] = $this->failedReport();
        // The first paid attempt failed safely, so the retry needs a new identity.
        $budget = app(AiEntitlementBudgetService::class);
        $failed = $budget->reserve($enrollment, 'project_feedback', 1000, 'test/model', $submission->public_id);
        $budget->release($failed, 'provider_unavailable');

        $this->actingAs($submission->user, 'api')
            ->postJson("/api/v1/project-submissions/{$submission->public_id}/report/retry")
            ->assertStatus(202);
        $requestId = (string) data_get($submission->fresh()->submission_metadata, 'ai_feedback.request_id');
        self::assertTrue(Str::isUuid($requestId));
        self::assertNotSame($submission->public_id, $requestId);

        config(['openrouter.api_key' => 'test-key', 'openrouter.endpoint' => 'https://openrouter.test/project',
            'openrouter.project_model' => 'anthropic/claude-sonnet-5',
            'openrouter.allowed_models' => ['anthropic/claude-sonnet-5'], 'openrouter.fallback_models' => []]);
        Http::fake(['https://openrouter.test/project' => fn (Request $request) => Http::response([
            'id' => 'retried-report', 'choices' => [['message' => ['content' => 'تقرير بعد إعادة المحاولة']]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70, 'cost' => .001],
        ])]);

        app()->call([new GenerateProjectFeedback($submission->id), 'handle']);

        $retried = AiUsageEvent::query()->where('request_id', $requestId)->sole();
        self::assertSame('completed', $retried->status);
        self::assertSame('failed', $failed->fresh()->status);
        self::assertSame(2, AiUsageEvent::query()->count());
        self::assertSame('ready', $submission->fresh()->feedbackThread?->status);
        self::assertSame('تقرير بعد إعادة المحاولة', $submission->fresh()->feedbackThread?->messages()->first()?->body);
        self::assertSame($requestId, data_get($submission->fresh()->submission_metadata, 'ai_feedback.request_id'));
        Http::assertSentCount(1);
    }

    /** @return array{0:ProjectSubmission,1:CourseEnrollment} */
    private function failedReport(): array
    {
        $user = new User;
        $user->forceFill(['name' => 'Retry learner', 'email' => Str::uuid().'@test.rokn',
            'password' => bcrypt('test'), 'active' => true, 'role' => 'client'])->save();
        app(AiConsentService::class)->record($user, true);
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
            'source_id' => $course->id, 'idempotency_key' => 'report-retry-test:'.$order->id,
            'metadata' => ['order_id' => $order->id], 'occurred_at' => now()]);
        $order->forceFill(['wallet_transaction_id' => $transaction->id])->save();
        $enrollment = new CourseEnrollment;
        $enrollment->forceFill(['tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
            'order_id' => $order->id, 'access_plan_id' => $plan->id, 'access_plan_snapshot' => $terms,
            'is_active' => true, 'enrolled_at' => now()])->save();
        $submission = ProjectSubmission::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $user->id, 'project_id' => $project->id, 'idempotency_key' => (string) Str::uuid(),
            'submission_text' => 'محاولة مرتبطة بالشعار',
            'submission_metadata' => ['ai_feedback' => [
                'status' => 'unavailable', 'reason' => 'provider_unavailable', 'retry_count' => 0,
            ]],
            'evaluation_snapshot' => ProjectSubmissionEvaluationSnapshot::capture($project, $section, $enrollment, $terms),
            'effort_status' => 'valid', 'review_status' => 'passed', 'review_source' => 'graceful_fallback',
            'feedback' => 'قبول للاستكمال وليس تقريرًا', 'submitted_at' => now()->subMinutes(10),
            'reviewed_at' => now()->subMinutes(5)]);

        return [$submission->fresh(), $enrollment];
    }
}
