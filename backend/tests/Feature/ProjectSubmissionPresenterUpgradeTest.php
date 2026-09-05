<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateProjectFeedback;
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
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CourseAccessPlanService;
use App\Services\AiConversationContextService;
use App\Services\ProjectFeedbackThreadService;
use App\Services\ProjectSubmissionPresenter;
use App\Services\ProjectSubmissionOrchestrator;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectSubmissionPresenterUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_only_project_submission_is_admitted_as_learner_work(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $fixture['project']->forceFill(['submission_text_enabled' => true])->save();
        $fixture['submission']->forceFill([
            'review_status' => ProjectSubmission::STATUS_NEEDS_RESUBMISSION,
        ])->save();
        $code = '<html><body><input type="email" required></body></html>';

        $result = app(ProjectSubmissionOrchestrator::class)->submit(
            $fixture['user'], $fixture['project'], $code, [], (string) Str::uuid(), []
        );

        self::assertSame('submitted', $result['state']);
        self::assertSame($code, $result['submission']->submission_text);
        self::assertSame(ProjectSubmission::EFFORT_VALID, $result['submission']->effort_status);
        Http::assertNothingSent();
    }

    public static function projectMarkupBudgetInputs(): array
    {
        return ['learner code' => [false], 'authored requirements code' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('projectMarkupBudgetInputs')]
    public function test_project_report_admission_counts_html_source_against_remaining_tokens(
        bool $markupInRequirements
    ): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $code = '<input data-example="'.str_repeat('code', 350).'">Learner notes about the form';
        $fixture = $this->submissionFixture(
            upgradedToEnhanced: false,
            requirements: $markupInRequirements ? $code : null
        );
        $fixture['project']->forceFill(['submission_text_enabled' => true])->save();
        AiEntitlementUsage::query()->create([
            'enrollment_id' => $fixture['enrollment']->id,
            'access_plan_id' => $fixture['enrollment']->access_plan_id,
            'feature' => AiEntitlementUsage::FEATURE_PROJECT_FEEDBACK,
            'used_requests' => 1,
            'used_tokens' => 3500,
        ]);
        $result = app(ProjectSubmissionOrchestrator::class)->submit(
            $fixture['user'], $fixture['project'],
            $markupInRequirements ? 'Learner notes about the form' : $code,
            [], (string) Str::uuid(), []
        );

        self::assertSame('invalid', $result['state']);
        self::assertSame('submission_files', $result['field']);
        self::assertSame(1, ProjectSubmission::query()->count());
        Http::assertNothingSent();
    }

    public function test_initial_report_provider_receives_literal_learner_html_submission(): void
    {
        $this->fakeProjectProvider();
        $requirements = 'Implement <input type="email" required> inside <form method="post">.';
        $fixture = $this->submissionFixture(upgradedToEnhanced: false, requirements: $requirements);
        $code = '<html><body><input type="text" required></body></html>';
        $fixture['submission']->forceFill(['submission_text' => $code."\x00"])->save();

        app()->call([new GenerateProjectFeedback($fixture['submission']->id), 'handle']);

        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0];
        self::assertStringContainsString(
            "BEGIN PROJECT REQUIREMENTS\n{$requirements}\nEND PROJECT REQUIREMENTS",
            $request['messages'][0]['content']
        );
        self::assertSame(
            "BEGIN LEARNER SUBMISSION\n{$code}\nEND LEARNER SUBMISSION",
            $request['messages'][1]['content'][0]['text']
        );
        self::assertSame('ready', data_get($fixture['submission']->fresh()->submission_metadata, 'ai_feedback.status'));
    }

    public function test_followup_provider_keeps_submission_and_completed_html_exchanges(): void
    {
        Bus::fake();
        $this->fakeProjectProvider();
        $requirements = 'Implement <input type="email" required> inside <form method="post">.';
        $fixture = $this->submissionFixture(upgradedToEnhanced: true, requirements: $requirements);
        $submissionCode = '<html><body><input required></body></html>';
        $fixture['submission']->forceFill(['submission_text' => $submissionCode])->save();
        $thread = $fixture['submission']->feedbackThread;
        $prior = [
            'user' => '<input type="email" required>',
            'assistant' => '<label>Email <input type="email" required></label>',
        ];
        foreach ($prior as $role => $body) {
            ProjectFeedbackMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'thread_id' => $thread->id,
                'role' => $role,
                'client_request_id' => (string) Str::uuid(),
                'status' => ProjectFeedbackMessage::COMPLETED,
                'body' => $body."\x00",
                'completed_at' => now(),
            ]);
        }
        $current = app(ProjectFeedbackThreadService::class)->queueReply(
            $fixture['user'], $thread, 'Explain the input attributes.', (string) Str::uuid()
        );

        app()->call([new GenerateProjectFeedbackReply($current->id), 'handle']);

        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0];
        $messages = $request['messages'];
        self::assertStringContainsString(
            "BEGIN PROJECT REQUIREMENTS\n{$requirements}\nEND PROJECT REQUIREMENTS",
            $messages[0]['content']
        );
        self::assertStringContainsString($submissionCode, $messages[0]['content']);
        self::assertContains(['role' => 'user', 'content' => $prior['user']], $messages);
        self::assertContains(['role' => 'assistant', 'content' => $prior['assistant']], $messages);
        self::assertSame(ProjectFeedbackMessage::COMPLETED, $current->fresh()->status);
    }

    public function test_older_project_memory_preserves_html_and_excludes_failed_messages(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $fixture['submission']->feedbackThread;
        $code = '<input type="email" required>';
        $completed = ProjectFeedbackMessage::query()->create([
            'public_id' => (string) Str::uuid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'client_request_id' => (string) Str::uuid(),
            'status' => ProjectFeedbackMessage::COMPLETED,
            'body' => $code."\x00",
            'completed_at' => now(),
        ]);
        $failed = ProjectFeedbackMessage::query()->create([
            'public_id' => (string) Str::uuid(),
            'thread_id' => $thread->id,
            'role' => 'assistant',
            'client_request_id' => (string) Str::uuid(),
            'status' => ProjectFeedbackMessage::FAILED,
            'body' => 'Failed response must not enter memory',
        ]);

        $memory = app(AiConversationContextService::class)->projectThread(
            $thread, $failed->id + 1, 'report:'.$fixture['submission']->public_id, 1000, 'email'
        );

        self::assertSame('الطالب: '.$code, $memory);
        self::assertStringNotContainsString((string) $failed->body, $memory);
        Http::assertNothingSent();
    }

    private function fakeProjectProvider(): void
    {
        config([
            'openrouter.api_key' => 'test-key',
            'openrouter.endpoint' => 'https://openrouter.test/project',
            'openrouter.default_model' => 'test/model',
            'openrouter.project_model' => 'test/model',
            'openrouter.allowed_models' => ['test/model'],
            'openrouter.fallback_models' => [],
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://openrouter.test/project' => Http::response([
            'id' => 'generation-project-html',
            'choices' => [['message' => ['content' => 'The input is required.']]],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 20,
                'total_tokens' => 140,
                'cost' => 0.01,
            ],
        ])]);
    }

    public function test_initial_project_report_preserves_technical_explanations_and_html_code(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $report = "SQLSTATE identifies database errors; inspect the stack trace for an uncaught exception.\n"
            ."A provider error can interrupt tool calls.\n```html\n<html><body>Example</body></html>\n```";
        $thread = app(ProjectFeedbackThreadService::class)->storeInitialReport(
            $fixture['submission'],
            $fixture['enrollment'],
            (int) $fixture['enrollment']->course_id,
            app(CourseAccessPlanService::class)->termsForEnrollment($fixture['enrollment']),
            $report
        );

        self::assertSame('ready', $thread->status);
        self::assertCount(1, $thread->messages);
        self::assertSame(ProjectFeedbackMessage::COMPLETED, $thread->messages->first()->status);
        self::assertSame($report, $thread->messages->first()->body);
        $payload = app(ProjectSubmissionPresenter::class)->present($fixture['submission']->fresh(), true);
        self::assertSame($report, data_get($payload, 'feedback_thread.messages.0.text'));
        Http::assertNothingSent();
    }

    public function test_project_followup_preserves_technical_question_and_idempotent_replay(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $fixture['submission']->feedbackThread;
        $question = "Why do tool calls produce a provider error and SQLSTATE in the stack trace?\n"
            ."Is <html><body>Example</body></html> valid HTML?";
        $requestId = (string) Str::uuid();
        $threads = app(ProjectFeedbackThreadService::class);

        $message = $threads->queueReply($fixture['user'], $thread, $question, $requestId);
        $replay = $threads->queueReply($fixture['user'], $thread, $question, $requestId);

        self::assertSame($message->id, $replay->id);
        self::assertSame(ProjectFeedbackMessage::QUEUED, $message->status);
        self::assertSame($question, $message->body);
        self::assertSame(1, $thread->messages()->where('role', 'user')->count());
        $payload = $threads->payload($thread->fresh());
        self::assertSame($question, data_get($payload, 'messages.1.text'));
        self::assertSame(9, $payload['remaining_messages']);
        Http::assertNothingSent();
    }

    public function test_report_submission_exposes_current_enhanced_reply_capability_after_paid_upgrade(): void
    {
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);

        $this->assertPresentationContract(
            $fixture['submission'],
            expectedFeedbackLevel: CourseAccessPlan::FEEDBACK_ENHANCED,
            expectedCanReply: true
        );
    }

    public function test_report_submission_without_upgrade_stays_report_only(): void
    {
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);

        $this->assertPresentationContract(
            $fixture['submission'],
            expectedFeedbackLevel: CourseAccessPlan::FEEDBACK_REPORT,
            expectedCanReply: false
        );
    }

    public function test_lost_submit_response_replays_before_the_report_budget_is_rechecked(): void
    {
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        AiEntitlementUsage::query()->create([
            'enrollment_id' => $fixture['enrollment']->id,
            'access_plan_id' => $fixture['enrollment']->access_plan_id,
            'feature' => AiEntitlementUsage::FEATURE_PROJECT_FEEDBACK,
            'used_requests' => 1,
            'used_tokens' => 4000,
        ]);

        $result = app(ProjectSubmissionOrchestrator::class)->submit(
            $fixture['user'],
            $fixture['project'],
            $fixture['text'],
            [],
            $fixture['idempotency_key'],
            []
        );

        self::assertSame('submitted', $result['state']);
        self::assertSame($fixture['submission']->id, $result['submission']->id);
        self::assertSame(1, ProjectSubmission::query()->count());

        $this->expectException(\UnexpectedValueException::class);
        app(ProjectSubmissionOrchestrator::class)->submit(
            $fixture['user'],
            $fixture['project'],
            'محاولة أخرى بالمفتاح نفسه',
            [],
            $fixture['idempotency_key'],
            []
        );
    }

    public function test_replay_after_entitlement_revocation_finishes_without_provider_spend_or_stuck_report(): void
    {
        Bus::fake();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $fixture['submission']->feedbackThread->messages()->delete();
        $fixture['submission']->feedbackThread()->delete();
        $metadata = (array) $fixture['submission']->submission_metadata;
        unset($metadata['ai_feedback']);
        $fixture['submission']->forceFill([
            'submission_metadata' => $metadata,
            'review_status' => ProjectSubmission::STATUS_PENDING,
            'review_source' => null,
            'score' => null,
            'feedback' => null,
            'auto_pass_at' => now()->subSecond(),
            'reviewed_at' => null,
        ])->save();
        $fixture['enrollment']->forceFill(['is_active' => false])->save();

        $result = app(ProjectSubmissionOrchestrator::class)->submit(
            $fixture['user'],
            $fixture['project'],
            $fixture['text'],
            [],
            $fixture['idempotency_key'],
            []
        );
        $submission = $result['submission']->fresh();
        $payload = app(ProjectSubmissionPresenter::class)->present($submission);

        self::assertSame(ProjectSubmission::STATUS_PASSED, $submission->review_status);
        self::assertSame('unavailable', data_get($submission->submission_metadata, 'ai_feedback.status'));
        self::assertSame('report_not_included', data_get($submission->submission_metadata, 'ai_feedback.reason'));
        self::assertSame('failed', $payload['report_status']);
        self::assertFalse($payload['can_retry_report']);
        self::assertNull($submission->submission_text);
        Bus::assertNotDispatched(GenerateProjectFeedback::class);
    }

    private function assertPresentationContract(
        ProjectSubmission $submission,
        string $expectedFeedbackLevel,
        bool $expectedCanReply
    ): void {
        $presenter = app(ProjectSubmissionPresenter::class);
        self::assertNotNull(ProjectSubmissionEvaluationSnapshot::fromSubmission($submission->fresh()));

        foreach ([false, true] as $includeTranscript) {
            $payload = $presenter->present($submission->fresh(), $includeTranscript);

            self::assertSame($expectedFeedbackLevel, $payload['feedback_level']);
            self::assertSame($expectedCanReply, $payload['can_reply']);
            self::assertSame($expectedCanReply, $payload['reply_enabled']);
            self::assertSame(
                $expectedFeedbackLevel,
                data_get($payload, 'feedback_thread.feedback_level')
            );
            self::assertSame(
                $expectedCanReply,
                data_get($payload, 'feedback_thread.can_reply')
            );
            self::assertCount(
                $includeTranscript ? 1 : 0,
                data_get($payload, 'feedback_thread.messages', [])
            );
        }
    }

    /** @return array{submission:ProjectSubmission,user:User,project:Project,enrollment:CourseEnrollment,text:string,idempotency_key:string} */
    private function submissionFixture(bool $upgradedToEnhanced, ?string $requirements = null): array
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Project learner',
            'email' => 'project-'.Str::uuid().'@rokn.test',
            'password' => bcrypt('test-password'),
            'role' => 'client',
            'active' => true,
        ])->save();
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1])->save();
        $project = Project::factory()->create($requirements === null ? [] : [
            'requirements_text_ar' => $requirements,
            'requirements_text_en' => $requirements,
        ]);
        $module = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'الوحدة الأولى',
            'order' => 1,
        ]);
        $section = CourseSection::factory()->project()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
        ]);
        $plans = app(CourseAccessPlanService::class);
        $reportPlan = CourseAccessPlan::query()->create(
            $this->planAttributes($course, CourseAccessPlan::FEEDBACK_REPORT)
        );
        $enhancedPlan = CourseAccessPlan::query()->create(
            $this->planAttributes($course, CourseAccessPlan::FEEDBACK_ENHANCED)
        );
        $reportTerms = $plans->snapshot($reportPlan);
        $enhancedTerms = $plans->snapshot($enhancedPlan);

        $courseOrder = $this->paidOrder($user, $course, $reportPlan, $reportTerms);
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $courseOrder->id,
            'access_plan_id' => $reportPlan->id,
            'access_plan_snapshot' => $reportTerms,
            'access_plan_order_id' => null,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
        ])->save();
        $submissionText = 'محاولة مكتملة';
        $idempotencyKey = (string) Str::uuid();
        $submission = ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'project_id' => $project->id,
            'idempotency_key' => $idempotencyKey,
            'submission_text' => $submissionText,
            'submission_metadata' => [
                'ai_feedback' => ['status' => 'completed'],
                'request_fingerprint' => hash('sha256', json_encode([
                    'text' => $submissionText,
                    'files' => [],
                ], JSON_THROW_ON_ERROR)),
            ],
            'evaluation_snapshot' => ProjectSubmissionEvaluationSnapshot::capture(
                $project,
                $section,
                $enrollment,
                $reportTerms
            ),
            'effort_status' => ProjectSubmission::EFFORT_VALID,
            'review_status' => ProjectSubmission::STATUS_PASSED,
            'review_source' => 'ai',
            'score' => 85,
            'feedback' => 'أتممت المشروع',
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);
        $thread = ProjectFeedbackThread::query()->create([
            'public_id' => (string) Str::uuid(),
            'submission_id' => $submission->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'project_id' => $project->id,
            'enrollment_id' => $enrollment->id,
            'access_plan_id' => $reportPlan->id,
            'feedback_level' => CourseAccessPlan::FEEDBACK_REPORT,
            'can_reply' => false,
            'status' => 'ready',
        ]);
        ProjectFeedbackMessage::query()->create([
            'public_id' => (string) Str::uuid(),
            'thread_id' => $thread->id,
            'role' => 'assistant',
            'client_request_id' => 'report:'.$submission->public_id,
            'status' => ProjectFeedbackMessage::COMPLETED,
            'body' => 'تقرير المشروع',
            'completed_at' => now(),
        ]);
        if ($upgradedToEnhanced) {
            $planOrder = $this->paidOrder(
                $user,
                $course,
                $enhancedPlan,
                $enhancedTerms,
                $courseOrder
            );
            $enrollment->forceFill([
                'access_plan_id' => $enhancedPlan->id,
                'access_plan_snapshot' => $enhancedTerms,
                'access_plan_order_id' => $planOrder->id,
            ])->save();
        }

        return [
            'submission' => $submission,
            'user' => $user,
            'project' => $project,
            'enrollment' => $enrollment,
            'text' => $submissionText,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /** @return array<string,mixed> */
    private function planAttributes(Course $course, string $feedbackLevel): array
    {
        $enhanced = $feedbackLevel === CourseAccessPlan::FEEDBACK_ENHANCED;

        return [
            'course_id' => $course->id,
            'code' => $enhanced ? CourseAccessPlan::MENTOR : CourseAccessPlan::GUIDED,
            'name_ar' => $enhanced ? 'التعلّم مع متابعة' : 'التعلّم مع تقرير',
            'price_coins' => $enhanced ? 600 : 400,
            'minimum_paid_coins' => 100,
            'chat_enabled' => false,
            'chat_message_limit' => 0,
            'chat_token_budget' => 0,
            'chat_attachments_enabled' => false,
            'chat_attachment_max_files' => 0,
            'ai_budget_usd' => '0.000000',
            'request_reserve_usd' => '0.000000',
            'project_feedback_token_budget' => 4000,
            'project_feedback_budget_usd' => '0.200000',
            'project_feedback_reserve_usd' => '0.020000',
            'project_followup_message_limit' => $enhanced ? 10 : 0,
            'project_followup_token_budget' => $enhanced ? 4000 : 0,
            'project_followup_budget_usd' => $enhanced ? '0.200000' : '0.000000',
            'project_followup_reserve_usd' => $enhanced ? '0.020000' : '0.000000',
            'project_followup_attachments_enabled' => $enhanced,
            'project_followup_attachment_max_files' => $enhanced ? 3 : 0,
            'max_output_tokens' => 320,
            'project_feedback_level' => $feedbackLevel,
            'project_output_enabled' => $enhanced,
            'certificate_enabled' => true,
            'is_active' => true,
            'sort_order' => $enhanced ? 30 : 20,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function paidOrder(
        User $user,
        Course $course,
        CourseAccessPlan $plan,
        array $snapshot,
        ?Order $parent = null
    ): Order {
        $order = Order::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'access_plan_id' => $plan->id,
            'access_plan_snapshot' => $snapshot,
            'parent_order_id' => $parent?->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 100,
            'final_amount' => 100,
            'total_coins' => 100,
            'paid_coins' => 100,
            'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);
        $walletTransaction = WalletTransaction::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'direction' => WalletTransaction::DIRECTION_DEBIT,
            'category' => $parent ? 'course_full_track_upgrade' : 'course_purchase',
            'bucket' => WalletTransaction::BUCKET_PAID,
            'amount' => 100,
            'paid_amount' => 100,
            'reward_amount' => 0,
            'balance_after' => 0,
            'paid_balance_after' => 0,
            'reward_balance_after' => 0,
            'source_type' => Course::class,
            'source_id' => $course->id,
            'idempotency_key' => 'presenter-upgrade:'.$order->id,
            'metadata' => ['order_id' => $order->id],
            'occurred_at' => now(),
        ]);
        $order->forceFill(['wallet_transaction_id' => $walletTransaction->id])->save();

        return $order;
    }
}
