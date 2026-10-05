<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateProjectFeedback;
use App\Jobs\GenerateProjectFeedbackReply;
use App\Jobs\EvaluateProjectSubmission;
use App\Http\Controllers\API\ProjectController;
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
use App\Services\AiConsentService;
use App\Services\CourseAccessPlanService;
use App\Services\AiConversationContextService;
use App\Services\ProjectFeedbackThreadService;
use App\Services\ProjectSubmissionPresenter;
use App\Services\ProjectSubmissionFileRetentionService;
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

    public function test_thread_reports_capacity_without_reinterpreting_legacy_entitlement_permission(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $fixture['submission']->feedbackThread;
        $threads = app(ProjectFeedbackThreadService::class);
        $usage = AiEntitlementUsage::query()->create([
            'enrollment_id' => $fixture['enrollment']->id,
            'feature' => AiEntitlementUsage::FEATURE_PROJECT_FOLLOWUP,
            'used_requests' => 1, 'reserved_requests' => 0,
            'used_tokens' => 0, 'reserved_tokens' => 0,
            'used_cost_usd' => '0.000000', 'reserved_cost_usd' => '0.000000',
        ]);
        $defaults = $usage->only([
            'used_requests', 'reserved_requests', 'used_tokens', 'reserved_tokens',
            'used_cost_usd', 'reserved_cost_usd',
        ]);
        self::assertFalse($threads->payload($thread->fresh())['reply_limit_reached']);
        foreach ([
            ['used_requests' => 10],
            ['used_tokens' => 3999],
            ['used_cost_usd' => '0.190000'],
            ['reserved_requests' => 9],
            ['reserved_tokens' => 3999],
            ['reserved_cost_usd' => '0.190000'],
        ] as $exhaustion) {
            $usage->forceFill([...$defaults, ...$exhaustion])->save();
            $payload = $threads->payload($thread->fresh());
            self::assertTrue($payload['can_reply']); // v60 semantics stay intact.
            self::assertTrue($payload['reply_limit_reached']);
            if (!isset($exhaustion['used_requests']) && !isset($exhaustion['reserved_requests'])) {
                self::assertSame(9, $payload['remaining_messages']);
            }
        }
        $usage->forceFill($defaults)->save();
        self::assertFalse($threads->payload($thread->fresh())['reply_limit_reached']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_capacity_exhaustion_is_not_a_report_failure_or_a_revoked_entitlement(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $threads = app(ProjectFeedbackThreadService::class);
        $reportOnly = $this->submissionFixture(upgradedToEnhanced: false);
        self::assertFalse($threads->payload($reportOnly['submission']->feedbackThread)['reply_limit_reached']);
        $enhanced = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $enhanced['submission']->feedbackThread;
        $thread->forceFill(['status' => 'failed'])->save();
        self::assertFalse($threads->payload($thread->fresh())['reply_limit_reached']);
        $thread->forceFill(['status' => 'ready'])->save();
        $enhanced['enrollment']->forceFill(['is_active' => false])->save();
        self::assertFalse($threads->payload($thread->fresh())['reply_limit_reached']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_quota_race_returns_a_stable_rejection_without_queueing_or_changing_usage(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $fixture['submission']->feedbackThread;
        AiEntitlementUsage::query()->create([
            'enrollment_id' => $fixture['enrollment']->id,
            'feature' => AiEntitlementUsage::FEATURE_PROJECT_FOLLOWUP,
            'used_requests' => 10, 'reserved_requests' => 0,
            'used_tokens' => 1000, 'reserved_tokens' => 0,
            'used_cost_usd' => '0.100000', 'reserved_cost_usd' => '0.000000',
        ]);
        $before = ProjectFeedbackMessage::query()->count();
        app(AiConsentService::class)->record($fixture['user'], true);
        $this->actingAs($fixture['user'], 'api')->postJson(
            '/api/v1/project-feedback-threads/'.$thread->public_id.'/messages',
            ['message' => 'سؤالي', 'client_request_id' => (string) Str::uuid()]
        )->assertStatus(422)->assertJsonPath('code', 'project_discussion_limit_reached');
        self::assertSame($before, ProjectFeedbackMessage::query()->count());
        self::assertSame(10, (int) AiEntitlementUsage::query()->sole()->used_requests);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_upgrade_offer_counts_pending_followups_without_double_counting_sent_reservations(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $enrollment = $fixture['enrollment'];
        $thread = $fixture['submission']->feedbackThread;
        $target = CourseAccessPlan::query()->where('course_id', $enrollment->course_id)->where('code', 'mentor')->firstOrFail();
        $usage = AiEntitlementUsage::query()->create([
            'enrollment_id' => $enrollment->id, 'feature' => 'project_followup',
            'used_requests' => 7, 'reserved_requests' => 2,
            'used_tokens' => 1000, 'reserved_tokens' => 0,
            'used_cost_usd' => '.050000', 'reserved_cost_usd' => '.040000',
        ]);
        foreach (['queued', 'sent'] as $status) {
            ProjectFeedbackMessage::query()->create([
                'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
                'role' => 'user', 'status' => $status, 'body' => 'سؤال عن التنفيذ',
                'client_request_id' => (string) Str::uuid(),
            ]);
        }
        $budget = app(\App\Services\AiEntitlementBudgetService::class);
        $offers = app(\App\Services\CoursePlanUpgradeEligibilityService::class);
        $course = Course::query()->findOrFail($enrollment->course_id);
        // 7 used + max(2 reserved, 1 SENT) + 1 QUEUED = 10, not 11.
        self::assertSame(10, $budget->projectFollowupCommittedRequests($enrollment, $usage));
        self::assertSame([], $offers->availablePlans($course, $enrollment, 'project_discussion')->pluck('code')->all());
        $usage->forceFill(['reserved_requests' => 1])->save();
        self::assertSame(9, $budget->projectFollowupCommittedRequests($enrollment, $usage->fresh()));
        self::assertSame(['mentor'], $offers->availablePlans($course, $enrollment, 'project_discussion')->pluck('code')->all());
        // A request-count opening is not an opening in token or cost capacity.
        $usage->forceFill(['used_tokens' => 3900])->save();
        self::assertSame([], $offers->availablePlans($course, $enrollment, 'project_discussion')->pluck('code')->all());
        $usage->forceFill(['used_tokens' => 1000, 'used_cost_usd' => '.190000', 'reserved_cost_usd' => 0])->save();
        self::assertSame([], $offers->availablePlans($course, $enrollment, 'project_discussion')->pluck('code')->all());
        self::assertSame(10, (int) $target->project_followup_message_limit);
        Http::assertNothingSent();
    }

    public function test_reply_admission_obeys_the_same_cross_thread_commitment_boundary(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $enrollment = $fixture['enrollment'];
        $occupiedThread = $fixture['submission']->feedbackThread;
        $usage = AiEntitlementUsage::query()->create([
            'enrollment_id' => $enrollment->id, 'feature' => 'project_followup',
            'used_requests' => 7, 'reserved_requests' => 2,
            'used_tokens' => 1000, 'reserved_tokens' => 0,
            'used_cost_usd' => '.050000', 'reserved_cost_usd' => '.040000',
        ]);
        foreach (['queued', 'sent'] as $status) {
            ProjectFeedbackMessage::query()->create([
                'public_id' => (string) Str::uuid(), 'thread_id' => $occupiedThread->id,
                'role' => 'user', 'status' => $status, 'body' => 'سؤال سابق',
                'client_request_id' => (string) Str::uuid(),
            ]);
        }
        // A second valid report has no in-flight reply. It must be stopped by
        // the enrollment quota, not by report-only access or thread busy state.
        $submission = $fixture['submission']->replicate(['public_id', 'idempotency_key']);
        $submission->forceFill(['public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()])->save();
        $openThread = $occupiedThread->replicate(['public_id', 'submission_id']);
        $openThread->forceFill(['public_id' => (string) Str::uuid(), 'submission_id' => $submission->id])->save();
        $before = ProjectFeedbackMessage::query()->count();
        $threads = app(ProjectFeedbackThreadService::class);
        $requestId = (string) Str::uuid();
        try {
            $threads->queueReply($fixture['user'], $openThread, 'كيف أحسن التنفيذ', $requestId);
            self::fail('Ten effective commitments must prevent an eleventh message.');
        } catch (\App\Exceptions\AiPlanLimitReachedException $exception) {
            self::assertSame('Project discussion request allowance is exhausted.', $exception->getMessage());
        }
        self::assertSame($before, ProjectFeedbackMessage::query()->count());
        $usage->forceFill(['reserved_requests' => 1])->save();
        $accepted = $threads->queueReply($fixture['user'], $openThread, 'كيف أحسن التنفيذ', $requestId);
        self::assertSame(ProjectFeedbackMessage::QUEUED, $accepted->status);
        self::assertSame($before + 1, ProjectFeedbackMessage::query()->count());
        self::assertSame(10, app(\App\Services\AiEntitlementBudgetService::class)
            ->projectFollowupCommittedRequests($enrollment, $usage->fresh()));
        Http::assertNothingSent();
    }

    public function test_review_retry_returns_the_same_submission_without_upload_or_provider_call(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $submission = $fixture['submission'];
        $requestId = (string) Str::uuid();
        $submission->forceFill([
            'review_status' => ProjectSubmission::STATUS_PENDING,
            'reviewed_at' => null,
            'submission_metadata' => ['evaluation' => [
                'status' => 'unavailable', 'request_id' => $requestId,
                'reason' => 'ai_rate_limited', 'retry_safe' => true, 'retry_count' => 0,
            ]],
        ])->save();
        $this->actingAs($fixture['user'], 'api');

        foreach ([1, 2] as $attempt) {
            $response = app(ProjectController::class)->retryEvaluation($submission->fresh());
            $body = $response->getData(true);
            self::assertSame(202, $response->getStatusCode());
            self::assertTrue($body['success']);
            self::assertSame((string) $submission->public_id, $body['data']['id']);
            self::assertSame('evaluating', $body['data']['submission_status']);
        }

        $metadata = $submission->fresh()->submission_metadata;
        self::assertSame($requestId, data_get($metadata, 'evaluation.request_id'));
        self::assertSame(1, data_get($metadata, 'evaluation.retry_count'));
        self::assertSame(1, ProjectSubmission::query()->count());
        Http::assertNothingSent();
    }

    public function test_review_retry_cannot_address_another_learners_submission(): void
    {
        Http::preventStrayRequests();
        $owner = $this->submissionFixture(upgradedToEnhanced: false);
        $other = $this->submissionFixture(upgradedToEnhanced: false);
        $this->actingAs($other['user'], 'api');

        $response = app(ProjectController::class)->retryEvaluation($owner['submission']);

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($response->getData(true)['data']);
        Http::assertNothingSent();
    }

    public function test_completed_review_evidence_survives_temporary_submission_cleanup(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $submission = $fixture['submission'];
        $evaluation = [
            'status' => 'ready', 'decision' => 'relevant_effort',
            'request_id' => (string) Str::uuid(), 'completed_at' => now()->toIso8601String(),
        ];
        $submission->forceFill(['submission_metadata' => [
            'evaluation' => $evaluation,
            'ai_feedback' => ['status' => 'ready'],
        ]])->save();

        self::assertTrue(app(ProjectSubmissionFileRetentionService::class)->purgeIfEligible($submission));

        $submission->refresh();
        self::assertNull($submission->submission_text);
        self::assertSame($evaluation, data_get($submission->submission_metadata, 'evaluation'));
        self::assertSame(ProjectSubmission::STATUS_PASSED, $submission->review_status);
        Http::assertNothingSent();
    }

    public function test_unavailable_review_is_not_presented_as_waiting_rejection_or_paid_report(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $submission = $fixture['submission'];
        $submission->forceFill([
            'review_status' => ProjectSubmission::STATUS_PENDING,
            'reviewed_at' => null,
            'submission_metadata' => [
                'evaluation' => [
                    'status' => 'unavailable',
                    'request_id' => (string) Str::uuid(),
                    'reason' => 'provider_outcome_unknown',
                    'retry_safe' => false,
                    'retry_count' => 0,
                ],
            ],
        ])->save();

        $payload = app(ProjectSubmissionPresenter::class)->present($submission);

        self::assertSame('review_unavailable', $payload['submission_status']);
        self::assertFalse($payload['can_submit']);
        self::assertFalse($payload['can_continue']);
        self::assertFalse($payload['can_retry_review']);
        self::assertNull($payload['review_retry_endpoint']);
        self::assertSame('unknown_outcome', $payload['review_failure_category']);
        self::assertNull($payload['poll_after_seconds']);
        self::assertSame('not_requested', $payload['report_status']);
        self::assertFalse($payload['can_retry_report']);
        Http::assertNothingSent();
    }

    public function test_safe_review_retry_is_advertised_separately_from_paid_report_retry(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $submission = $fixture['submission'];
        $submission->forceFill([
            'review_status' => ProjectSubmission::STATUS_PENDING,
            'reviewed_at' => null,
            'submission_metadata' => [
                'evaluation' => [
                    'status' => 'unavailable',
                    'request_id' => (string) Str::uuid(),
                    'reason' => 'ai_rate_limited',
                    'retry_safe' => true,
                    'retry_count' => 0,
                ],
            ],
        ])->save();

        $payload = app(ProjectSubmissionPresenter::class)->present($submission);

        self::assertSame('review_unavailable', $payload['submission_status']);
        self::assertTrue($payload['can_retry_review']);
        self::assertSame(
            "/api/v1/project-submissions/{$submission->public_id}/review/retry",
            $payload['review_retry_endpoint']
        );
        self::assertFalse($payload['can_retry_report']);
        self::assertNull($payload['poll_after_seconds']);
        Http::assertNothingSent();
    }

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
    public function test_project_submission_preserves_html_even_when_it_exceeds_remaining_report_tokens(
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
        $fixture['submission']->forceFill(['review_status' => ProjectSubmission::STATUS_NEEDS_RESUBMISSION])->save();
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

        self::assertSame('submitted', $result['state']);
        self::assertSame($markupInRequirements ? 'Learner notes about the form' : $code,
            $result['submission']->submission_text);
        self::assertSame($markupInRequirements ? $code : $fixture['project']->requirements_text,
            data_get(ProjectSubmissionEvaluationSnapshot::fromSubmission($result['submission']), 'project.requirements_text'));
        self::assertSame(2, ProjectSubmission::query()->count());
        self::assertSame(3500, AiEntitlementUsage::query()->sole()->used_tokens);
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

    public function test_paid_report_publication_rolls_back_as_one_write_and_replays_without_another_provider_call(): void
    {
        $this->fakeProjectProvider();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $submission = $fixture['submission'];
        $thread = $submission->feedbackThread;
        $thread->forceFill(['status' => 'processing'])->save();
        $report = $thread->messages()->firstOrFail();
        $report->forceFill([
            'status' => ProjectFeedbackMessage::STREAMING,
            'body' => null,
            'completed_at' => null,
        ])->save();
        $interrupt = true;
        ProjectFeedbackMessage::saving(function (ProjectFeedbackMessage $message) use ($report, &$interrupt): void {
            if ($interrupt && $message->id === $report->id && $message->status === ProjectFeedbackMessage::COMPLETED) {
                $interrupt = false;
                throw new \RuntimeException('Report write interrupted');
            }
        });

        try {
            app()->call([new GenerateProjectFeedback($submission->id), 'handle']);
            self::fail('The injected presentation failure must reach the job retry path.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Report write interrupted', $exception->getMessage());
        }

        // A paid answer is durable, but none of its ready presentation may
        // commit until both the initial message and its submission are saved.
        $event = \App\Models\AiUsageEvent::query()->where('feature', 'project_feedback')->sole();
        self::assertSame('completed', $event->status);
        self::assertSame('The input is required.', data_get($event->metadata, 'accepted_response'));
        self::assertSame('queued', data_get($submission->fresh()->submission_metadata, 'ai_feedback.status'));
        self::assertSame('processing', $thread->fresh()->status);
        self::assertSame(ProjectFeedbackMessage::STREAMING, $report->fresh()->status);
        self::assertSame($fixture['text'], $submission->fresh()->submission_text);
        self::assertNull(data_get($event->metadata, 'presentation_completed_at'));
        $usage = AiEntitlementUsage::query()->where('feature', 'project_feedback')->sole();
        $paidUsage = $usage->only(['used_requests', 'used_tokens', 'used_cost_usd']);

        app()->call([new GenerateProjectFeedback($submission->id), 'handle']);
        app()->call([new GenerateProjectFeedback($submission->id), 'handle']);

        self::assertSame('ready', data_get($submission->fresh()->submission_metadata, 'ai_feedback.status'));
        self::assertSame('ready', $thread->fresh()->status);
        self::assertSame(ProjectFeedbackMessage::COMPLETED, $report->fresh()->status);
        self::assertSame('The input is required.', $report->fresh()->body);
        self::assertSame(1, $thread->messages()->count());
        self::assertEquals($paidUsage, $usage->fresh()->only(array_keys($paidUsage)));
        self::assertNotEmpty(data_get($event->fresh()->metadata, 'presentation_completed_at'));
        self::assertNull($submission->fresh()->submission_text);
        Http::assertSentCount(1);
    }

    public function test_initial_report_waits_for_its_current_execution_before_marking_the_paid_answer_presented(): void
    {
        $this->fakeProjectProvider();
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $submission = $fixture['submission'];
        $thread = $submission->feedbackThread;
        $thread->forceFill(['status' => 'processing'])->save();
        $report = $thread->messages()->firstOrFail();
        $report->forceFill([
            'status' => ProjectFeedbackMessage::STREAMING,
            'body' => null,
            'completed_at' => null,
        ])->save();
        $replacement = new GenerateProjectFeedback($submission->id);
        $takenOver = false;
        \App\Models\AiUsageEvent::saved(function ($event) use ($submission, $replacement, &$takenOver): void {
            if ($takenOver || $event->feature !== 'project_feedback' || $event->status !== 'completed') return;
            $takenOver = true;
            $fresh = $submission->fresh();
            $metadata = $fresh->submission_metadata;
            $metadata['ai_feedback']['execution_id'] = $replacement->executionId;
            $metadata['ai_feedback']['lease_expires_at'] = now()->addMinute()->toIso8601String();
            $fresh->forceFill(['submission_metadata' => $metadata])->save();
        });

        app()->call([new GenerateProjectFeedback($submission->id), 'handle']);

        $event = \App\Models\AiUsageEvent::query()->where('feature', 'project_feedback')->sole();
        self::assertTrue($takenOver);
        self::assertSame('processing', data_get($submission->fresh()->submission_metadata, 'ai_feedback.status'));
        self::assertSame(ProjectFeedbackMessage::STREAMING, $report->fresh()->status);
        self::assertNull(data_get($event->metadata, 'presentation_completed_at'));
        self::assertSame('The input is required.', data_get($event->metadata, 'accepted_response'));
        self::assertSame($fixture['text'], $submission->fresh()->submission_text);

        app()->call([$replacement, 'handle']);

        self::assertSame('ready', data_get($submission->fresh()->submission_metadata, 'ai_feedback.status'));
        self::assertSame(ProjectFeedbackMessage::COMPLETED, $report->fresh()->status);
        self::assertSame('The input is required.', $report->fresh()->body);
        self::assertNotEmpty(data_get($event->fresh()->metadata, 'presentation_completed_at'));
        self::assertSame(1, AiEntitlementUsage::query()->where('feature', 'project_feedback')->sole()->used_requests);
        Http::assertSentCount(1);
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

    public function test_followup_history_pins_report_and_latest_exchange_within_the_existing_total_text_budget(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $fixture['submission']->feedbackThread;
        $report = $thread->messages()->firstOrFail();
        $reportBody = str_repeat('تقرير المشروع ', 400);
        $report->forceFill(['body' => $reportBody])->save();
        $annotations = [['type' => 'file', 'file' => ['filename' => 'project.pdf']]];
        foreach (range(1, 5) as $round) {
            foreach (['user', 'assistant'] as $role) {
                ProjectFeedbackMessage::query()->create([
                    'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
                    'client_request_id' => (string) Str::uuid(), 'role' => $role,
                    'status' => ProjectFeedbackMessage::COMPLETED,
                    'body' => ($round === 5 ? 'أحدث تبادل ' : "تبادل {$round} ").str_repeat('شرح ', 700),
                    'provider_annotations' => $role === 'assistant' ? $annotations : null,
                    'completed_at' => now(),
                ]);
            }
        }
        $current = ProjectFeedbackMessage::query()->create([
            'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
            'client_request_id' => (string) Str::uuid(), 'role' => 'user',
            'status' => ProjectFeedbackMessage::QUEUED, 'body' => 'وضح آخر تعديل',
        ]);
        $reader = new \ReflectionMethod(GenerateProjectFeedbackReply::class, 'boundedConversationHistory');
        $history = $reader->invoke(new GenerateProjectFeedbackReply($current->id), $thread, [
            'project_followup_token_budget' => 4000,
        ]);

        self::assertLessThanOrEqual(6000, array_sum(array_map(
            fn (array $item): int => mb_strlen($item['content'], 'UTF-8'), $history
        )));
        self::assertCount(3, $history);
        self::assertSame(mb_substr(trim($reportBody), 0, 3000, 'UTF-8'), $history[0]['content']);
        self::assertSame(['assistant', 'user', 'assistant'], array_column($history, 'role'));
        self::assertStringStartsWith('أحدث تبادل', $history[1]['content']);
        self::assertStringStartsWith('أحدث تبادل', $history[2]['content']);
        self::assertSame($annotations, $history[2]['annotations']);
        self::assertStringNotContainsString($current->body, implode('\n', array_column($history, 'content')));
        Http::assertNothingSent();
    }

    public function test_smallest_followup_history_window_keeps_both_report_and_latest_discussion(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $fixture['submission']->feedbackThread;
        $thread->messages()->firstOrFail()->forceFill(['body' => str_repeat('التقرير ', 700)])->save();
        foreach (['user', 'assistant'] as $role) {
            ProjectFeedbackMessage::query()->create([
                'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
                'client_request_id' => (string) Str::uuid(), 'role' => $role,
                'status' => ProjectFeedbackMessage::COMPLETED,
                'body' => ($role === 'user' ? 'السؤال الحالي ' : 'الرد الحالي ').str_repeat('شرح ', 800),
                'completed_at' => now(),
            ]);
        }
        $reader = new \ReflectionMethod(GenerateProjectFeedbackReply::class, 'boundedConversationHistory');
        $history = $reader->invoke(new GenerateProjectFeedbackReply(0), $thread, [
            'project_followup_token_budget' => 1000,
        ]);
        self::assertSame(['assistant', 'user', 'assistant'], array_column($history, 'role'));
        self::assertSame(4000, array_sum(array_map(
            fn (array $item): int => mb_strlen($item['content'], 'UTF-8'), $history
        )));
        self::assertSame(2000, mb_strlen($history[0]['content'], 'UTF-8'));
        self::assertStringStartsWith('التقرير', $history[0]['content']);
        self::assertStringStartsWith('السؤال الحالي', $history[1]['content']);
        self::assertStringStartsWith('الرد الحالي', $history[2]['content']);
        Http::assertNothingSent();
    }

    public function test_older_excerpt_header_and_recent_pairs_share_the_same_budget_without_a_four_round_minimum(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $fixture['submission']->feedbackThread;
        $thread->messages()->firstOrFail()->forceFill(['body' => 'التقرير الأصلي'])->save();
        foreach (range(1, 8) as $round) {
            foreach (['user', 'assistant'] as $role) {
                ProjectFeedbackMessage::query()->create([
                    'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
                    'client_request_id' => (string) Str::uuid(), 'role' => $role,
                    'status' => ProjectFeedbackMessage::COMPLETED,
                    'body' => "round-{$round} ".str_repeat('x', 1000), 'completed_at' => now(),
                ]);
            }
        }
        $reader = new \ReflectionMethod(GenerateProjectFeedbackReply::class, 'boundedConversationHistory');
        $history = $reader->invoke(new GenerateProjectFeedbackReply(0), $thread, [
            'project_followup_token_budget' => 4000,
        ]);
        self::assertLessThanOrEqual(6000, array_sum(array_map(
            fn (array $item): int => mb_strlen($item['content'], 'UTF-8'), $history
        )));
        self::assertSame('التقرير الأصلي', $history[0]['content']);
        $pairs = array_values(array_filter(array_slice($history, 1), fn (array $item): bool => $item['role'] !== 'system'));
        self::assertSame(['user', 'assistant', 'user', 'assistant'], array_column($pairs, 'role'));
        self::assertStringStartsWith('round-7', $pairs[0]['content']);
        self::assertStringStartsWith('round-8', $pairs[2]['content']);
        self::assertSame(1, count(array_filter($history, fn (array $item): bool => $item['role'] === 'system')));
        Http::assertNothingSent();
    }

    public function test_older_memory_does_not_expand_a_small_or_closed_remaining_window(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $fixture['submission']->feedbackThread;
        $old = ProjectFeedbackMessage::query()->create([
            'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
            'client_request_id' => (string) Str::uuid(), 'role' => 'user',
            'status' => ProjectFeedbackMessage::COMPLETED,
            'body' => str_repeat('تفاصيل المشروع ', 100), 'completed_at' => now(),
        ]);
        $memory = app(AiConversationContextService::class);
        $reportId = 'report:'.$fixture['submission']->public_id;
        self::assertSame('', $memory->projectThread($thread, $old->id + 1, $reportId, 0, 'المشروع'));
        self::assertSame(0, \App\Models\AiConversationContext::query()->count());
        self::assertSame('', $memory->projectThread($thread, $old->id + 1, $reportId, 50, 'المشروع'));
        Http::assertNothingSent();
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

    public function test_interrupted_initial_report_keeps_partial_visible_without_completing_report(): void
    {
        $partial = 'Check <input required>: this explanation is incomplete.';
        $this->fakeProjectProvider($partial);
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $thread = $fixture['submission']->feedbackThread;
        $thread->forceFill(['status' => 'processing'])->save();
        $report = $thread->messages()->firstOrFail();
        $report->forceFill(['status' => ProjectFeedbackMessage::STREAMING, 'body' => null])->save();
        $review = $fixture['submission']->only(['review_status', 'score', 'feedback', 'reviewed_at']);

        app()->call([new GenerateProjectFeedback($fixture['submission']->id), 'handle']);

        Http::assertSentCount(1);
        self::assertSame(ProjectFeedbackMessage::FAILED, $report->fresh()->status);
        self::assertSame($partial, $report->fresh()->body);
        self::assertSame('failed', $thread->fresh()->status);
        self::assertEquals($review, $fixture['submission']->fresh()->only(array_keys($review)));
        $presented = app(ProjectSubmissionPresenter::class)->present($fixture['submission']->fresh());
        self::assertSame('failed', $presented['report_status']);
        self::assertSame('provider_outcome_unknown', $report->fresh()->error_code);
        self::assertSame('failed', $presented['feedback_thread']['messages'][0]['status']);
        self::assertSame($partial, $presented['feedback_thread']['messages'][0]['text']);
        $this->actingAs($fixture['user'], 'api')
            ->getJson('/api/v1/project-feedback-threads/'.$thread->public_id)
            ->assertOk()
            ->assertJsonPath('data.messages.0.status', 'failed')
            ->assertJsonPath('data.messages.0.text', $partial);
        $event = \App\Models\AiUsageEvent::query()->where('feature', 'project_feedback')->firstOrFail();
        self::assertSame('', (string) data_get($event->metadata, 'accepted_response', ''));
        self::assertSame('provider_outcome_unknown', data_get($event->metadata, 'provider_outcome_reason'));
        self::assertFalse(data_get($event->metadata, 'entitlement_delivered'));
        self::assertSame($fixture['text'], $fixture['submission']->fresh()->submission_text);
        self::assertSame(0, app(ProjectSubmissionFileRetentionService::class)->purgeExpiredTerminalFailures(10));
        $this->travel(31)->days();
        self::assertSame(1, app(ProjectSubmissionFileRetentionService::class)->purgeExpiredTerminalFailures(10));
        self::assertNull($fixture['submission']->fresh()->submission_text);
        self::assertEquals($review, $fixture['submission']->fresh()->only(array_keys($review)));
    }

    public function test_rejected_report_keeps_input_for_repair_without_reversing_progress(): void
    {
        config([
            'openrouter.api_key' => 'test-key',
            'openrouter.endpoint' => 'https://openrouter.test/project',
            'openrouter.project_model' => 'test/model',
            'openrouter.allowed_models' => ['test/model'],
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://openrouter.test/project' => Http::response([
            'error' => ['code' => 400, 'message' => 'Invalid content block'],
        ], 400)]);
        $fixture = $this->submissionFixture(upgradedToEnhanced: false);
        $review = $fixture['submission']->only(['review_status', 'score', 'feedback', 'reviewed_at']);

        app()->call([new GenerateProjectFeedback($fixture['submission']->id), 'handle']);

        Http::assertSentCount(1);
        $submission = $fixture['submission']->fresh();
        self::assertSame('ai_request_rejected', data_get($submission->submission_metadata, 'ai_feedback.reason'));
        self::assertSame($fixture['text'], $submission->submission_text);
        self::assertEquals($review, $submission->only(array_keys($review)));
        self::assertSame(0, app(ProjectSubmissionFileRetentionService::class)->purgeExpiredTerminalFailures(10));
        $event = \App\Models\AiUsageEvent::query()->where('feature', 'project_feedback')->firstOrFail();
        self::assertSame('failed', $event->status);
        self::assertSame('provider_unavailable', data_get($event->metadata, 'reason'));
        $usage = AiEntitlementUsage::query()->where('feature', 'project_feedback')->firstOrFail();
        self::assertSame(0, (int) $usage->reserved_requests);
        self::assertSame(0, (int) $usage->used_requests);
    }

    public function test_interrupted_followup_keeps_partial_visible_without_completing_reply(): void
    {
        Bus::fake();
        $partial = 'SQLSTATE is diagnostic context; the explanation is incomplete.';
        $this->fakeProjectProvider($partial);
        $fixture = $this->submissionFixture(upgradedToEnhanced: true);
        $thread = $fixture['submission']->feedbackThread;
        $message = app(ProjectFeedbackThreadService::class)->queueReply(
            $fixture['user'], $thread, 'Explain this error.', (string) Str::uuid()
        );

        app()->call([new GenerateProjectFeedbackReply($message->id), 'handle']);

        Http::assertSentCount(1);
        $reply = $thread->messages()->where('client_request_id', 'reply:'.$message->public_id)->firstOrFail();
        self::assertSame(ProjectFeedbackMessage::FAILED, $message->fresh()->status);
        self::assertSame(ProjectFeedbackMessage::FAILED, $reply->status);
        self::assertSame($partial, $reply->body);
        self::assertSame('provider_outcome_unknown', $reply->error_code);
        $this->actingAs($fixture['user'], 'api')
            ->getJson('/api/v1/project-feedback-threads/'.$thread->public_id)
            ->assertOk()
            ->assertJsonPath('data.messages.2.status', 'failed')
            ->assertJsonPath('data.messages.2.text', $partial);
        $event = \App\Models\AiUsageEvent::query()->where('feature', 'project_followup')->firstOrFail();
        self::assertSame('', (string) data_get($event->metadata, 'accepted_response', ''));
        self::assertSame('provider_outcome_unknown', data_get($event->metadata, 'provider_outcome_reason'));
        self::assertFalse(data_get($event->metadata, 'entitlement_delivered'));
    }

    private function fakeProjectProvider(?string $interruptedPartial = null): void
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
        Http::fake(['https://openrouter.test/project' => $interruptedPartial !== null
            ? Http::response('data: '.json_encode([
                'id' => 'generation-project-interrupted',
                'choices' => [['delta' => ['content' => $interruptedPartial]]],
            ], JSON_THROW_ON_ERROR)."\n\n", 200, ['Content-Type' => 'text/event-stream'])
            : Http::response([
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
        Http::preventStrayRequests();
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
        app()->call([new EvaluateProjectSubmission($result['submission']->id), 'handle']);
        $submission = $result['submission']->fresh();
        $payload = app(ProjectSubmissionPresenter::class)->present($submission);

        self::assertSame(ProjectSubmission::STATUS_PENDING, $submission->review_status);
        self::assertSame('unavailable', data_get($submission->submission_metadata, 'evaluation.status'));
        self::assertSame('review_access_unavailable', data_get($submission->submission_metadata, 'evaluation.reason'));
        self::assertSame('review_unavailable', $payload['submission_status']);
        self::assertSame('not_requested', $payload['report_status']);
        self::assertFalse($payload['can_continue']);
        self::assertFalse($payload['can_retry_report']);
        self::assertNotNull($submission->submission_text);
        Bus::assertNotDispatched(GenerateProjectFeedback::class);
        Http::assertNothingSent();
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
        app(AiConsentService::class)->record($user, true);
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
