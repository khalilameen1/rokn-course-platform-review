<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EvaluateProjectSubmission;
use App\Jobs\GenerateProjectFeedback;
use App\Models\AiEntitlementUsage;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Models\User;
use App\Services\AiEntitlementBudgetService;
use App\Services\CourseAccessPlanService;
use App\Services\PaidAiCallExecutionService;
use App\Services\ProjectSubmissionEvaluationService;
use App\Services\ProjectSubmissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProjectSubmissionEvaluationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tracked uploads commit the cleanup ledger before writing bytes.
        // Like AdminCourseImageReplacementTransactionTest, use no outer test transaction.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Bus::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['projects.submission_disk' => 'local', 'openrouter.api_key' => 'test-key',
            'openrouter.endpoint' => 'https://openrouter.test/review', 'openrouter.default_model' => 'test/model',
            'openrouter.project_model' => 'test/model', 'openrouter.allowed_models' => ['test/model'],
            'openrouter.fallback_models' => []]);
    }

    public function test_nonblank_image_is_reviewed_against_the_assignment_and_unrelated_decision_does_not_pass(): void
    {
        $this->fakeDecision('needs_changes', 'الصورة مش محاولة للشعار المطلوب ارفع صورة الشعار اللي نفذته');
        [$submission] = $this->submit(image: true);
        self::assertSame('pending', $submission->review_status);
        $job = new EvaluateProjectSubmission($submission->id);
        app()->call([$job, 'handle']);
        $result = $submission->fresh();
        self::assertSame('needs_resubmission', $result->review_status);
        self::assertSame('relevance_review', $result->review_source);
        self::assertNull($result->score);
        self::assertFalse(data_get($result->submission_metadata, 'progression_credit'));
        self::assertStringContainsString('الشعار', $result->feedback);
        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0];
        self::assertStringContainsString('صمم شعار شجرة', $request['messages'][1]['content'][0]['text']);
        self::assertStringStartsWith('data:image/png;base64,', $request['messages'][1]['content'][1]['image_url']['url']);
        self::assertArrayNotHasKey('tools', $request->data());
        Bus::assertNotDispatched(GenerateProjectFeedback::class);
    }

    public function test_relevant_pass_only_attempt_is_platform_funded_and_never_consumes_report_quota(): void
    {
        $this->fakeDecision('relevant_effort', 'محاولتك مرتبطة بالمطلوب وتقدر تكمل');
        [$submission] = $this->submit(image: true);
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        $result = $submission->fresh();
        self::assertSame('passed', $result->review_status);
        self::assertNull($result->score);
        self::assertFalse(data_get($result->submission_metadata, 'skill_verified'));
        self::assertTrue(data_get($result->submission_metadata, 'progression_credit'));
        self::assertSame(0, AiEntitlementUsage::query()->count());
        $event = AiUsageEvent::query()->sole();
        self::assertSame('project_review', $event->feature);
        self::assertSame('completed', $event->status);
        self::assertSame('platform', data_get($event->metadata, 'funding_source'));
        self::assertSame('0.010000', $event->cost_usd);
        self::assertNull($result->feedbackThread);
        Bus::assertNotDispatched(GenerateProjectFeedback::class);
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        Http::assertSentCount(1);
    }

    public function test_recovery_deadline_never_auto_accepts_an_unreviewed_submission(): void
    {
        [$submission] = $this->submit();
        $submission->forceFill(['auto_pass_at' => now()->subSecond()])->save();
        $result = app(ProjectSubmissionService::class)->finalizeIfDue($submission);
        self::assertSame('pending', $result->review_status);
        self::assertNull($result->review_source);
        self::assertNull($result->score);
        self::assertSame('queued', data_get($result->submission_metadata, 'evaluation.status'));
        Http::assertNothingSent();
    }

    public static function unusableResponses(): array
    {
        return ['malformed' => ['not a decision'], 'unreadable by model' => [json_encode([
            'decision' => 'unavailable', 'reason' => 'مش قادر أشوف الملف بوضوح',
        ])]];
    }

    #[DataProvider('unusableResponses')]
    public function test_unusable_provider_answer_is_not_a_student_rejection_or_a_fake_retry(string $message): void
    {
        Http::fake(['*' => Http::response($this->providerResult($message))]);
        [$submission] = $this->submit();
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        $result = $submission->fresh();
        self::assertSame('pending', $result->review_status);
        self::assertSame('unavailable', data_get($result->submission_metadata, 'evaluation.status'));
        self::assertFalse(app(ProjectSubmissionEvaluationService::class)->canRetry($result));
        self::assertNull($result->reviewed_at);
        self::assertSame('completed', AiUsageEvent::query()->sole()->status);
    }

    public function test_unknown_provider_timeout_stops_review_without_repeating_a_paid_call(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        [$submission] = $this->submit();
        $job = new EvaluateProjectSubmission($submission->id);
        app()->call([$job, 'handle']);
        $result = $submission->fresh();
        self::assertSame('pending', $result->review_status);
        self::assertSame('provider_outcome_unknown', data_get($result->submission_metadata, 'evaluation.reason'));
        self::assertFalse(app(ProjectSubmissionEvaluationService::class)->canRetry($result));
        self::assertSame('completed', AiUsageEvent::query()->sole()->status);
        self::assertSame('0.050000', AiUsageEvent::query()->sole()->cost_usd);
        app()->call([$job, 'handle']);
        self::assertSame(1, (int) data_get(AiUsageEvent::query()->sole()->metadata, 'provider_call_attempt'));
        self::assertSame(0, AiEntitlementUsage::query()->count());
    }

    public static function storedOutcomes(): array { return ['landed' => [false], 'settled before presentation' => [true]]; }

    #[DataProvider('storedOutcomes')]
    public function test_known_paid_result_is_restored_without_another_provider_call(bool $settled): void
    {
        [$submission, , $enrollment] = $this->submit();
        $budget = app(AiEntitlementBudgetService::class);
        $calls = app(PaidAiCallExecutionService::class);
        $event = $budget->reserveProjectReview($enrollment, 1000, 'test/model',
            data_get($submission->submission_metadata, 'evaluation.request_id'));
        $execution = (string) Str::uuid();
        $calls->beginForActiveUser($event, $execution, $submission->user_id);
        $result = ['message' => json_encode(['decision' => 'relevant_effort', 'reason' => 'محاولة مناسبة']),
            'usage' => ['total_tokens' => 50, 'cost' => .01], 'provider_request_id' => 'landed-review'];
        $calls->landSuccessfulResultForActiveUser($event, $execution, $submission->user_id, $result);
        if ($settled) $budget->settle($event, $result);
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        self::assertSame('passed', $submission->fresh()->review_status);
        self::assertSame('completed', $event->fresh()->status);
        self::assertSame(1, (int) data_get($event->fresh()->metadata, 'provider_call_attempt'));
        Http::assertNothingSent();
    }

    public function test_provider_known_rejection_can_be_retried_on_the_same_request_without_another_submission(): void
    {
        Http::fake(['*' => Http::sequence()->push(['error' => ['message' => 'busy']], 429)
            ->push($this->providerResult(json_encode(['decision' => 'relevant_effort', 'reason' => 'محاولة مناسبة'])))]);
        [$submission, $user] = $this->submit();
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        $evaluations = app(ProjectSubmissionEvaluationService::class);
        self::assertTrue($evaluations->canRetry($submission->fresh()));
        $requestId = data_get($submission->submission_metadata, 'evaluation.request_id');
        $retry = $evaluations->retryEvaluation($submission->fresh(), $user);
        app()->call([new EvaluateProjectSubmission($retry->id), 'handle']);
        self::assertSame('passed', $retry->fresh()->review_status);
        self::assertSame($requestId, AiUsageEvent::query()->sole()->request_id);
        self::assertSame(1, ProjectSubmission::query()->count());
        self::assertSame(2, (int) data_get(AiUsageEvent::query()->sole()->metadata, 'provider_call_attempt'));
    }

    public function test_full_admitted_learner_text_reaches_the_reviewer_including_the_end(): void
    {
        $this->fakeDecision('relevant_effort', 'محاولة مناسبة');
        $text = str_repeat('تفاصيل التنفيذ ', 700).'<svg>نهاية الدليل</svg>';
        [$submission] = $this->submit(text: $text);
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        Http::assertSentCount(1);
        self::assertStringContainsString($text, Http::recorded()->first()[0]['messages'][1]['content'][0]['text']);
    }

    public function test_daily_limit_does_not_spend_a_retry_or_offer_one_before_the_day_resets(): void
    {
        config(['projects.evaluation_daily_attempt_limit' => 1]);
        [$submission, $user, $enrollment] = $this->submit();
        app(AiEntitlementBudgetService::class)->reserveProjectReview($enrollment, 1000, 'test/model', (string) Str::uuid());
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        $evaluations = app(ProjectSubmissionEvaluationService::class);
        self::assertSame('review_daily_limit', data_get($submission->fresh()->submission_metadata, 'evaluation.reason'));
        self::assertFalse($evaluations->canRetry($submission->fresh()));
        $this->travel(1)->days();
        self::assertTrue($evaluations->canRetry($submission->fresh()));
        $retry = $evaluations->retryEvaluation($submission->fresh(), $user);
        self::assertSame(0, data_get($retry->submission_metadata, 'evaluation.retry_count'));
        Http::assertNothingSent();
    }

    public function test_worker_death_settles_unknown_and_old_worker_cannot_fail_a_new_claim(): void
    {
        [$submission, , $enrollment] = $this->submit();
        $job = new EvaluateProjectSubmission($submission->id);
        $event = app(AiEntitlementBudgetService::class)->reserveProjectReview($enrollment, 1000, 'test/model',
            data_get($submission->submission_metadata, 'evaluation.request_id'));
        app(PaidAiCallExecutionService::class)->beginForActiveUser($event, $job->executionId, $submission->user_id);
        $metadata = $submission->submission_metadata;
        $metadata['evaluation']['status'] = 'processing';
        $metadata['evaluation']['worker_execution_id'] = $job->executionId;
        $submission->forceFill(['submission_metadata' => $metadata])->save();
        app(ProjectSubmissionEvaluationService::class)->fail($submission->id, 'old-worker');
        self::assertSame('reserved', $event->fresh()->status);
        $job->failed(new \RuntimeException('worker timeout'));
        self::assertSame('completed', $event->fresh()->status);
        self::assertSame('pending', $submission->fresh()->review_status);
        self::assertFalse(app(ProjectSubmissionEvaluationService::class)->canRetry($submission->fresh()));
        Http::assertNothingSent();
    }

    public function test_existing_accepted_submission_is_not_reopened_by_the_new_gate(): void
    {
        [$submission] = $this->submit();
        $submission->forceFill(['review_status' => 'passed', 'review_source' => 'graceful_fallback',
            'feedback' => 'قبول سابق', 'reviewed_at' => now(), 'auto_pass_at' => now()->subDay()])->save();
        $result = app(ProjectSubmissionService::class)->finalizeIfDue($submission);
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        self::assertSame('passed', $result->review_status);
        self::assertSame('graceful_fallback', $result->review_source);
        self::assertSame('قبول سابق', $submission->fresh()->feedback);
        self::assertSame(0, AiUsageEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_paid_report_is_queued_only_after_a_relevant_decision(): void
    {
        $this->fakeDecision('relevant_effort', 'محاولة مناسبة');
        [$submission] = $this->submit(report: true);
        Bus::assertNotDispatched(GenerateProjectFeedback::class);
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        self::assertSame('passed', $submission->fresh()->review_status);
        self::assertSame('queued', data_get($submission->fresh()->submission_metadata, 'ai_feedback.status'));
        Bus::assertDispatched(GenerateProjectFeedback::class);
        self::assertSame('project_review', AiUsageEvent::query()->sole()->feature);
        self::assertSame(0, AiEntitlementUsage::query()->count());
        Http::assertSentCount(1);
    }

    public function test_missing_shared_storage_image_is_unavailable_without_a_paid_call_or_student_rejection(): void
    {
        config(['filesystems.disks.shared-review' => ['driver' => 'local', 'root' => storage_path('framework/testing/shared-review')]]);
        Storage::fake('shared-review');
        config(['projects.submission_disk' => 'shared-review']);
        [$submission] = $this->submit(image: true);
        Storage::disk('shared-review')->delete($submission->submission_file);
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        self::assertSame('pending', $submission->fresh()->review_status);
        self::assertSame('review_input_unavailable', data_get($submission->fresh()->submission_metadata, 'evaluation.reason'));
        self::assertTrue(app(ProjectSubmissionEvaluationService::class)->canRetry($submission->fresh()));
        self::assertSame(0, AiUsageEvent::query()->count());
        Http::assertNothingSent();
    }

    public static function completeTextFormats(): array { return ['text' => [false], 'Word' => [true]]; }

    #[DataProvider('completeTextFormats')]
    public function test_reviewer_receives_the_end_of_text_files_beyond_the_report_excerpt_limit(bool $word): void
    {
        $this->fakeDecision('relevant_effort', 'محاولة مناسبة');
        $text = str_repeat('خطوات تنفيذ الشعار باستخدام الأشكال ', 1000).'FINAL RELEVANT EVIDENCE <svg>tree</svg>';
        $file = $word ? $this->wordFile($text) : UploadedFile::fake()->createWithContent('work.txt', $text);
        [$submission] = $this->submit(file: $file);
        app()->call([new EvaluateProjectSubmission($submission->id), 'handle']);
        self::assertSame('passed', $submission->fresh()->review_status);
        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0];
        self::assertStringContainsString($text, $request['messages'][1]['content'][1]['text']);
        self::assertGreaterThan(15000, AiUsageEvent::query()->sole()->reserved_tokens);
    }

    private function fakeDecision(string $decision, string $reason): void
    {
        Http::fake(['*' => Http::response($this->providerResult(json_encode(compact('decision', 'reason'))))]);
    }

    private function providerResult(string $message): array
    {
        return ['id' => 'generation-review', 'choices' => [['message' => ['content' => $message]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130, 'cost' => .01]];
    }

    private function submit(bool $image = false, ?string $text = null, bool $report = false, ?UploadedFile $file = null): array
    {
        $user = new User();
        $user->forceFill(['name' => 'Review learner', 'email' => Str::uuid().'@test.rokn',
            'password' => bcrypt('test'), 'active' => true, 'role' => 'client'])->save();
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1, 'is_coming_soon' => false])->save();
        $project = Project::factory()->create(['requirements_text_ar' => 'صمم شعار شجرة باستخدام الأشكال',
            'requirements_text_en' => 'صمم شعار شجرة باستخدام الأشكال']);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        CourseSection::factory()->project()->create(['course_id' => $course->id, 'module_id' => $module->id,
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id]);
        $plan = CourseAccessPlan::query()->create(['course_id' => $course->id, 'code' => $report ? 'guided' : 'basic',
            'name_ar' => 'تعلم', 'price_coins' => 100, 'minimum_paid_coins' => $report ? 100 : 0,
            'project_feedback_level' => $report ? 'report' : 'pass_only',
            'project_feedback_token_budget' => $report ? 4000 : 0,
            'project_feedback_budget_usd' => $report ? '.200000' : '0',
            'project_feedback_reserve_usd' => $report ? '.020000' : '0']);
        $terms = app(CourseAccessPlanService::class)->snapshot($plan->fresh());
        $order = null;
        if ($report) {
            $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id,
                'access_plan_id' => $plan->id, 'access_plan_snapshot' => $terms,
                'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'amount' => 100,
                'final_amount' => 100, 'total_coins' => 100, 'paid_coins' => 100, 'reward_coins' => 0,
                'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now()]);
            $transaction = WalletTransaction::query()->create(['public_id' => (string) Str::uuid(),
                'user_id' => $user->id, 'direction' => 'debit', 'category' => 'course_purchase', 'bucket' => 'paid',
                'amount' => 100, 'paid_amount' => 100, 'reward_amount' => 0, 'balance_after' => 0,
                'paid_balance_after' => 0, 'reward_balance_after' => 0, 'source_type' => Course::class,
                'source_id' => $course->id, 'idempotency_key' => 'review-test:'.$order->id,
                'metadata' => ['order_id' => $order->id], 'occurred_at' => now()]);
            $order->forceFill(['wallet_transaction_id' => $transaction->id])->save();
        }
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill(['tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
            'order_id' => $order?->id, 'access_plan_id' => $plan->id, 'access_plan_snapshot' => $terms,
            'is_active' => true, 'enrolled_at' => now()])->save();
        $files = $file ? [$file] : [];
        if ($image) {
            $canvas = imagecreatetruecolor(180, 180);
            imagefilledrectangle($canvas, 0, 0, 89, 179, imagecolorallocate($canvas, 240, 100, 20));
            imagefilledrectangle($canvas, 90, 0, 179, 179, imagecolorallocate($canvas, 20, 100, 240));
            ob_start(); imagepng($canvas); $bytes = ob_get_clean(); imagedestroy($canvas);
            $files[] = UploadedFile::fake()->createWithContent('attempt.png', $bytes);
        }
        $submission = app(ProjectSubmissionService::class)->submit($user, $project,
            ($image || $file) ? null : ($text ?? 'محاولة توضح خطوات تنفيذ الشعار باستخدام الأشكال'), $files, (string) Str::uuid());
        return [$submission, $user, $enrollment];
    }

    private function wordFile(string $text): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'review-test-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships><Relationship Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
            .htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p></w:body></w:document>');
        $zip->setCompressionName('word/document.xml', \ZipArchive::CM_STORE);
        $zip->close();
        try { return UploadedFile::fake()->createWithContent('work.docx', file_get_contents($path)); }
        finally { unlink($path); }
    }
}
