<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EvaluateProjectSubmission;
use App\Jobs\GenerateProjectFeedback;
use App\Models\AiUsageEvent;
use App\Models\InternalSignal;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\AiEntitlementBudgetService;
use App\Services\AiInputAttachmentService;
use App\Services\CourseEntitlementService;
use App\Services\FinancialProvenanceService;
use App\Services\OpenRouterService;
use App\Services\PaidAiCallExecutionService;
use App\Services\ProjectSubmissionEffortGuard;
use App\Services\ProjectSubmissionEvaluationScheduler;
use App\Services\ProjectSubmissionFileRetentionService;
use App\Services\ProjectSubmissionInputService;
use App\Services\ProjectSubmissionReviewService;
use App\Services\ProjectSubmissionService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProjectSubmissionOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Exercise real commit/rollback dispatch, without an outer test transaction.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Bus::fake();
        Http::fake(static fn () => throw new \LogicException('Scheduling/review must not call a provider.'));
        $this->forbidResolving([
            ProjectSubmissionService::class,
            ProjectSubmissionInputService::class,
            ProjectSubmissionEffortGuard::class,
            AiInputAttachmentService::class,
            AiEntitlementBudgetService::class,
            FinancialProvenanceService::class,
            PaidAiCallExecutionService::class,
            OpenRouterService::class,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
        } finally {
            $this->travelBack();
            parent::tearDown();
        }
    }

    public function test_scheduling_creates_one_durable_request_without_granting_progress_or_loading_review_services(): void
    {
        $submission = $this->submission(['submission_metadata' => ['request_fingerprint' => 'original']]);
        $scheduler = $this->scheduler();
        $queued = $scheduler->dispatchIfDue($submission);
        $requestId = data_get($queued->submission_metadata, 'evaluation.request_id');

        self::assertTrue(Str::isUuid($requestId));
        self::assertSame('original', data_get($queued->submission_metadata, 'request_fingerprint'));
        self::assertSame('queued', data_get($queued->submission_metadata, 'evaluation.status'));
        self::assertFalse(data_get($queued->submission_metadata, 'evaluation.retry_safe'));
        self::assertTrue($queued->auto_pass_at->isFuture());
        self::assertSame('pending', $queued->review_status);
        self::assertNull($queued->review_source);
        self::assertNull($queued->score);
        self::assertSame(0, InternalSignal::query()->count());
        self::assertSame(0, AiUsageEvent::query()->count());

        // A stale model, not just a freshly loaded row, must respect the committed deadline.
        $replay = $scheduler->dispatchIfDue($submission);
        self::assertSame($requestId, data_get($replay->submission_metadata, 'evaluation.request_id'));
        Bus::assertDispatchedTimes(EvaluateProjectSubmission::class, 1);
        Bus::assertNotDispatched(GenerateProjectFeedback::class);
    }

    public static function terminalStates(): array
    {
        return [
            'passed' => ['passed', 'queued'],
            'rejected' => ['needs_resubmission', 'queued'],
            'ready evaluation' => ['pending', 'ready'],
            'unavailable evaluation' => ['pending', 'unavailable'],
        ];
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_states_are_not_reopened(string $review, string $evaluation): void
    {
        $submission = $this->submission([
            'review_status' => $review,
            'submission_metadata' => ['evaluation' => ['status' => $evaluation, 'request_id' => 'original']],
        ]);
        $before = $submission->getAttributes();
        $this->scheduler()->dispatchIfDue($submission);
        self::assertSame($before, $submission->fresh()->getAttributes());
        Bus::assertNothingDispatched();
    }

    public function test_scheduler_rechecks_persisted_review_state_instead_of_reopening_a_stale_pending_model(): void
    {
        $submission = $this->submission();
        ProjectSubmission::query()->whereKey($submission->id)->update(['review_status' => 'passed']);
        $result = $this->scheduler()->dispatchIfDue($submission);
        self::assertSame('passed', $result->review_status);
        Bus::assertNothingDispatched();
    }

    public function test_future_deadline_keeps_the_current_execution_and_metadata_unchanged(): void
    {
        $submission = $this->submission([
            'auto_pass_at' => now()->addMinute(),
            'submission_metadata' => ['evaluation' => [
                'status' => 'processing', 'request_id' => 'original', 'execution_id' => 'worker',
            ]],
        ]);
        $before = $submission->getAttributes();
        $this->scheduler()->dispatchIfDue($submission);
        self::assertSame($before, $submission->fresh()->getAttributes());
        Bus::assertNothingDispatched();
    }

    public static function unavailableLearners(): array
    {
        return ['inactive' => [false], 'deleted' => [true]];
    }

    #[DataProvider('unavailableLearners')]
    public function test_unavailable_learner_does_not_restart_evaluation(bool $deleted): void
    {
        $submission = $this->submission();
        $user = $submission->user;
        $deleted ? $user->delete() : $user->forceFill(['active' => false])->save();
        $before = $submission->getAttributes();
        $this->scheduler()->dispatchIfDue($submission);
        self::assertSame($before, $submission->fresh()->getAttributes());
        Bus::assertNothingDispatched();
    }

    public function test_recovery_is_bounded_and_selects_only_due_nonterminal_evaluations(): void
    {
        $this->submission(['review_status' => 'passed']);
        $this->submission(['submission_metadata' => ['evaluation' => ['status' => 'unavailable']]]);
        $future = $this->submission(['auto_pass_at' => now()->addHour()]);
        $first = $this->submission();
        $second = $this->submission(['submission_metadata' => ['evaluation' => [
            'status' => 'processing', 'request_id' => 'keep-request', 'retry_count' => 2,
        ]]]);
        $third = $this->submission();

        self::assertSame(2, $this->scheduler()->recoverDue(2));
        Bus::assertDispatchedTimes(EvaluateProjectSubmission::class, 2);
        Bus::assertDispatched(EvaluateProjectSubmission::class, fn ($job) => $job->submissionId === $first->id);
        Bus::assertDispatched(EvaluateProjectSubmission::class, fn ($job) => $job->submissionId === $second->id);
        self::assertSame('keep-request', data_get($second->fresh()->submission_metadata, 'evaluation.request_id'));
        self::assertSame(2, data_get($second->fresh()->submission_metadata, 'evaluation.retry_count'));
        self::assertTrue($third->fresh()->auto_pass_at->isPast());
        self::assertTrue($future->fresh()->auto_pass_at->isFuture());
        self::assertSame(5, ProjectSubmission::query()->where('review_status', 'pending')->count());
    }

    public function test_queue_outage_leaves_a_recoverable_marker_and_releases_the_failed_dispatch_lock(): void
    {
        $submission = $this->submission();
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()
            ->andThrow(new \RuntimeException('test broker unavailable'));
        $scheduler = $this->scheduler();
        $queued = $scheduler->dispatchIfDue($submission);
        $requestId = data_get($queued->submission_metadata, 'evaluation.request_id');
        self::assertSame('queued', data_get($queued->submission_metadata, 'evaluation.status'));
        self::assertSame('pending', $queued->review_status);

        Bus::fake();
        $this->travel(101)->seconds();
        self::assertSame(1, $scheduler->recoverDue());
        self::assertSame($requestId, data_get($submission->fresh()->submission_metadata, 'evaluation.request_id'));
        Bus::assertDispatchedTimes(EvaluateProjectSubmission::class, 1);
        Bus::assertNotDispatched(GenerateProjectFeedback::class);
    }

    public function test_rolled_back_schedule_cannot_dispatch_an_uncommitted_evaluation(): void
    {
        $submission = $this->submission();
        $before = $submission->getAttributes();
        DB::beginTransaction();
        try {
            $this->scheduler()->dispatchIfDue($submission);
            Bus::assertNothingDispatched();
        } finally {
            DB::rollBack();
        }
        self::assertSame($before, $submission->fresh()->getAttributes());
        Bus::assertNothingDispatched();
    }

    public function test_review_replay_cannot_change_the_decision_or_duplicate_reward_intent(): void
    {
        $submission = $this->submission();
        $reviews = app(ProjectSubmissionReviewService::class);
        $reviewed = $reviews->applyEvaluationOutcome($submission, 'request-1', true, 'محاولة مناسبة');
        self::assertSame('passed', $reviewed->review_status);
        self::assertNull($reviewed->score);
        self::assertFalse(data_get($reviewed->submission_metadata, 'skill_verified'));
        self::assertTrue(data_get($reviewed->submission_metadata, 'progression_credit'));

        $replay = $reviews->applyEvaluationOutcome($submission, 'request-1', false, 'رد متأخر');
        self::assertSame('passed', $replay->review_status);
        self::assertSame('محاولة مناسبة', $replay->feedback);
        self::assertSame(1, InternalSignal::query()->where('type', 'project.passed.first_reward')->count());
        self::assertSame(1, InternalSignal::query()->where('type', 'project.review.notification')->count());
        self::assertSame(0, AiUsageEvent::query()->count());
        Bus::assertNotDispatched(GenerateProjectFeedback::class);
        Bus::assertNotDispatched(EvaluateProjectSubmission::class);
    }

    public function test_wrong_request_cannot_apply_a_review(): void
    {
        $submission = $this->submission();
        $before = $submission->getAttributes();
        app(ProjectSubmissionReviewService::class)->applyEvaluationOutcome($submission, 'stale-request', true, 'ignored');
        self::assertSame($before, $submission->fresh()->getAttributes());
        self::assertSame(0, InternalSignal::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_staff_decision_needs_no_upload_or_paid_provider_dependencies(): void
    {
        $submission = $this->submission();
        $reviewer = $this->user(['role' => 'admin']);
        $result = app(ProjectSubmissionReviewService::class)->reviewByStaff(
            $submission, $reviewer, false, 'ارفع تنفيذك للمشروع'
        );
        self::assertSame('needs_resubmission', $result->review_status);
        self::assertSame('admin_manual', $result->review_source);
        self::assertSame($reviewer->id, $result->reviewed_by);
        self::assertSame('ارفع تنفيذك للمشروع', $result->feedback);
        self::assertSame(0, InternalSignal::query()->where('type', 'project.passed.first_reward')->count());
        self::assertSame(1, InternalSignal::query()->where('type', 'project.review.notification')->count());
        self::assertSame(0, AiUsageEvent::query()->count());
        Bus::assertNotDispatched(EvaluateProjectSubmission::class);
    }

    private function scheduler(): ProjectSubmissionEvaluationScheduler
    {
        $this->forbidResolving([
            ProjectSubmissionReviewService::class,
            ProjectSubmissionFileRetentionService::class,
            CourseEntitlementService::class,
        ]);
        return app(ProjectSubmissionEvaluationScheduler::class);
    }

    private function forbidResolving(array $services): void
    {
        foreach ($services as $service) {
            $this->app->bind($service, static function () use ($service): never {
                throw new \LogicException('Unexpected ownership dependency: ' . $service);
            });
        }
    }

    private function submission(array $attributes = []): ProjectSubmission
    {
        return ProjectSubmission::query()->create(array_replace([
            'public_id' => (string) Str::uuid(),
            'user_id' => $this->user()->id,
            'project_id' => Project::factory()->create()->id,
            'idempotency_key' => (string) Str::uuid(),
            'submission_text' => 'تنفيذ واضح للمشروع',
            'effort_status' => 'valid',
            'review_status' => 'pending',
            'submitted_at' => now(),
            'auto_pass_at' => now()->subSecond(),
            'submission_metadata' => ['evaluation' => ['status' => 'queued', 'request_id' => 'request-1']],
        ], $attributes))->fresh();
    }

    private function user(array $attributes = []): User
    {
        $user = new User();
        $user->forceFill(array_replace([
            'name' => 'Boundary learner',
            'email' => Str::uuid() . '@test.rokn',
            'password' => bcrypt('test'),
            'role' => 'client',
            'active' => true,
        ], $attributes))->save();
        return $user;
    }
}
