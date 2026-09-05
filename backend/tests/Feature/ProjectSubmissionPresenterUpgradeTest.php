<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateProjectFeedback;
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
use App\Services\ProjectSubmissionPresenter;
use App\Services\ProjectSubmissionOrchestrator;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectSubmissionPresenterUpgradeTest extends TestCase
{
    use RefreshDatabase;

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
    private function submissionFixture(bool $upgradedToEnhanced): array
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
        $project = Project::factory()->create();
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
