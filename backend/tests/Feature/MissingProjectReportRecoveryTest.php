<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateProjectFeedback;
use App\Models\AiUsageEvent;
use App\Models\AiInputAttachment;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AiEntitlementBudgetService;
use App\Services\CourseAccessPlanService;
use App\Services\PaidAiCallExecutionService;
use App\Services\ProjectSubmissionPresenter;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MissingProjectReportRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_missing_marker_is_recovered_without_selecting_pass_only_failed_or_ready_reports(): void
    {
        [$missing] = $this->fixture();
        [$passOnly] = $this->fixture(false);
        [$failed] = $this->fixture();
        $failed->forceFill(['submission_metadata' => ['ai_feedback' => ['status' => 'unavailable', 'reason' => 'provider_outcome_unknown']],
            'updated_at' => now()->subMinutes(5)])->save();
        [$ready, $enrollment] = $this->fixture();
        $ready->forceFill(['submission_metadata' => ['ai_feedback' => ['status' => 'ready']],
            'updated_at' => now()->subMinutes(5)])->save();
        ProjectFeedbackThread::query()->create(['public_id' => (string) Str::uuid(), 'submission_id' => $ready->id,
            'user_id' => $ready->user_id, 'course_id' => $enrollment->course_id, 'project_id' => $ready->project_id,
            'enrollment_id' => $enrollment->id, 'access_plan_id' => $enrollment->access_plan_id,
            'feedback_level' => 'report', 'can_reply' => false, 'status' => 'ready']);
        [$invalid] = $this->fixture();
        $snapshot = $invalid->evaluation_snapshot;
        $snapshot['project']['requirements_text'] = 'unverified changed requirements';
        $invalid->forceFill(['evaluation_snapshot' => $snapshot, 'updated_at' => now()->subMinutes(5)])->save();

        $this->artisan('ai:recover-stalled-feedback')->assertExitCode(0);

        Bus::assertDispatchedTimes(GenerateProjectFeedback::class, 1);
        Bus::assertDispatched(GenerateProjectFeedback::class, fn ($job): bool => $job->submissionId === $missing->id);
        self::assertSame('queued', data_get($missing->fresh()->submission_metadata, 'ai_feedback.status'));
        self::assertSame($missing->public_id, data_get($missing->fresh()->submission_metadata, 'ai_feedback.request_id'));
        self::assertNull(data_get($passOnly->fresh()->submission_metadata, 'ai_feedback.status'));
        self::assertNull(data_get($invalid->fresh()->submission_metadata, 'ai_feedback.status'));
        self::assertSame('unavailable', data_get($failed->fresh()->submission_metadata, 'ai_feedback.status'));
        self::assertSame('passed', $missing->fresh()->review_status);
        $this->artisan('ai:recover-stalled-feedback')->assertExitCode(0);
        Bus::assertDispatchedTimes(GenerateProjectFeedback::class, 1);
        Http::assertNothingSent();
    }

    public function test_missing_payload_is_a_failed_report_not_a_ready_copy_of_the_progression_note(): void
    {
        [$submission] = $this->fixture();
        $submission->forceFill(['submission_text' => null])->save();
        app()->call([new GenerateProjectFeedback($submission->id), 'handle']);
        $result = $submission->fresh();
        self::assertSame('unavailable', data_get($result->submission_metadata, 'ai_feedback.status'));
        self::assertSame('report_input_missing', data_get($result->submission_metadata, 'ai_feedback.reason'));
        self::assertSame('passed', $result->review_status);
        self::assertSame('قبول للاستكمال وليس تقريرًا', $result->feedback);
        self::assertNull($result->feedbackThread);
        $payload = app(ProjectSubmissionPresenter::class)->present($result);
        self::assertSame('failed', $payload['report_status']);
        self::assertNull($payload['poll_after_seconds']);
        self::assertSame(0, AiUsageEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_invalid_missing_marker_does_not_starve_a_later_report_with_a_dispatch_limit_of_one(): void
    {
        [$invalid] = $this->fixture();
        $snapshot = $invalid->evaluation_snapshot;
        $snapshot['project']['requirements_text'] = 'tampered requirements';
        $invalid->forceFill(['evaluation_snapshot' => $snapshot, 'updated_at' => now()->subMinutes(5)])->save();
        $invalid->forceFill(['updated_at' => now()->subMinutes(5)])->save();
        [$valid] = $this->fixture();
        [$later] = $this->fixture();

        $this->artisan('ai:recover-stalled-feedback', ['--limit' => 1])->assertExitCode(0);

        Bus::assertDispatchedTimes(GenerateProjectFeedback::class, 1);
        Bus::assertDispatched(GenerateProjectFeedback::class, fn ($job): bool => $job->submissionId === $valid->id);
        self::assertNull(data_get($invalid->fresh()->submission_metadata, 'ai_feedback.status'));
        self::assertNull(data_get($later->fresh()->submission_metadata, 'ai_feedback.status'));
        Http::assertNothingSent();
    }

    public function test_jpeg_only_submission_sends_image_without_an_empty_text_block_and_stores_the_report(): void
    {
        [$submission, $enrollment] = $this->fixture();
        $submission->forceFill(['submission_text' => null])->save();
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('report-input.jpg', $bytes);
        AiInputAttachment::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $submission->user_id, 'course_id' => $enrollment->course_id,
            'client_upload_id' => (string) Str::uuid(), 'purpose' => 'project_submission',
            'owner_type' => 'project_submission', 'owner_id' => $submission->id,
            'storage_disk' => 'local', 'storage_path' => 'report-input.jpg',
            'original_file_name' => 'work.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'status' => 'ready']);
        config(['openrouter.api_key' => 'test-key', 'openrouter.endpoint' => 'https://openrouter.test/project',
            'openrouter.project_model' => 'anthropic/claude-sonnet-5',
            'openrouter.allowed_models' => ['anthropic/claude-sonnet-5'], 'openrouter.fallback_models' => []]);
        $report = 'التكوين واضح ويمكن تحسين تباين الشعار';
        Http::fake(['https://openrouter.test/project' => function ($request) use ($report) {
            foreach ($request['messages'] as $message) {
                if (!is_array($message['content'])) {
                    continue;
                }
                foreach ($message['content'] as $part) {
                    if ($part['type'] === 'text' && $part['text'] === '') {
                        return Http::response(['error' => ['message' => 'text content blocks must be non-empty']], 400);
                    }
                }
            }
            return Http::response(['id' => 'jpeg-report', 'choices' => [['message' => ['content' => $report]]],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70, 'cost' => .001]]);
        }]);

        app()->call([new GenerateProjectFeedback($submission->id), 'handle']);

        self::assertSame('ready', $submission->fresh()->feedbackThread?->status);
        self::assertSame($report, $submission->fresh()->feedbackThread?->messages()->first()?->body);
        self::assertSame('passed', $submission->fresh()->review_status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['messages'][1]['content'] === [[
            'type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.base64_encode($bytes)],
        ]]);
    }

    public static function knownReports(): array
    {
        return ['landed' => [false, false], 'settled' => [true, false],
            'landed with unreadable stored attachment' => [false, true],
            'settled with unreadable stored attachment' => [true, true]];
    }

    public function test_unreadable_ready_attachment_ends_report_without_changing_progress_or_calling_provider(): void
    {
        [$submission, $enrollment] = $this->fixture();
        $submission->forceFill(['submission_text' => null])->save();
        AiInputAttachment::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $submission->user_id, 'course_id' => $enrollment->course_id,
            'client_upload_id' => (string) Str::uuid(), 'purpose' => 'project_submission',
            'owner_type' => 'project_submission', 'owner_id' => $submission->id,
            'storage_disk' => 'local', 'storage_path' => 'unreadable-report.jpg',
            'original_file_name' => 'work.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 149394,
            'sha256' => str_repeat('a', 64), 'status' => 'ready']);

        app()->call([new GenerateProjectFeedback($submission->id), 'handle']);

        $result = $submission->fresh();
        self::assertSame('unavailable', data_get($result->submission_metadata, 'ai_feedback.status'));
        self::assertSame('attachment_unavailable', data_get($result->submission_metadata, 'ai_feedback.reason'));
        self::assertSame('passed', $result->review_status);
        self::assertSame('قبول للاستكمال وليس تقريرًا', $result->feedback);
        self::assertNull($result->feedbackThread);
        $payload = app(ProjectSubmissionPresenter::class)->present($result);
        self::assertSame('failed', $payload['report_status']);
        self::assertNull($payload['poll_after_seconds']);
        self::assertSame(0, AiUsageEvent::query()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('knownReports')]
    public function test_known_paid_report_is_recovered_even_if_input_payload_is_no_longer_present(bool $settled, bool $missingStorage): void
    {
        [$submission, $enrollment] = $this->fixture();
        $submission->forceFill(['submission_text' => null])->save();
        if ($missingStorage) {
            AiInputAttachment::query()->create(['public_id' => (string) Str::uuid(),
                'user_id' => $submission->user_id, 'course_id' => $enrollment->course_id,
                'client_upload_id' => (string) Str::uuid(), 'purpose' => 'project_submission',
                'owner_type' => 'project_submission', 'owner_id' => $submission->id,
                'storage_disk' => 'local', 'storage_path' => 'missing-report-input.png',
                'original_file_name' => 'work.png', 'mime_type' => 'image/png', 'size_bytes' => 150000,
                'sha256' => str_repeat('a', 64), 'status' => 'ready']);
            Storage::disk('local')->assertMissing('missing-report-input.png');
        }
        $budget = app(AiEntitlementBudgetService::class);
        $calls = app(PaidAiCallExecutionService::class);
        $event = $budget->reserve($enrollment, 'project_feedback', 1000, 'test/model', $submission->public_id);
        $execution = (string) Str::uuid();
        $calls->beginForActiveUser($event, $execution, $submission->user_id);
        $result = ['message' => 'هذا تقرير فعلي محفوظ عن المحاولة', 'usage' => ['total_tokens' => 100, 'cost' => .01],
            'provider_request_id' => 'report-known'];
        $calls->landSuccessfulResultForActiveUser($event, $execution, $submission->user_id, $result);
        if ($settled) $budget->settle($event, $result);

        app()->call([new GenerateProjectFeedback($submission->id), 'handle']);

        self::assertSame('ready', $submission->fresh()->feedbackThread?->status);
        self::assertSame($result['message'], $submission->fresh()->feedbackThread?->messages()->first()?->body);
        self::assertSame('completed', $event->fresh()->status);
        self::assertSame(1, (int) data_get($event->fresh()->metadata, 'provider_call_attempt'));
        self::assertSame('قبول للاستكمال وليس تقريرًا', $submission->fresh()->feedback);
        Http::assertNothingSent();
    }

    private function fixture(bool $report = true): array
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
        $plan = CourseAccessPlan::query()->create(['course_id' => $course->id, 'code' => $report ? 'guided' : 'basic',
            'name_ar' => 'تعلم', 'price_coins' => 100, 'minimum_paid_coins' => $report ? 100 : 0,
            'project_feedback_level' => $report ? 'report' : 'pass_only',
            'project_feedback_token_budget' => $report ? 4000 : 0,
            'project_feedback_budget_usd' => $report ? '.200000' : '0',
            'project_feedback_reserve_usd' => $report ? '.020000' : '0']);
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
            'source_id' => $course->id, 'idempotency_key' => 'missing-report-test:'.$order->id,
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
            'feedback' => 'قبول للاستكمال وليس تقريرًا', 'submitted_at' => now()->subMinutes(10),
            'reviewed_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)]);
        $submission->forceFill(['updated_at' => now()->subMinutes(5)])->save();
        return [$submission, $enrollment];
    }
}
