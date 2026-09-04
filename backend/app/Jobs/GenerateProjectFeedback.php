<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\AiPlanLimitReachedException;
use App\Exceptions\AiProviderUnavailableException;
use App\Models\AiInputAttachment;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\AiEntitlementBudgetService;
use App\Services\AiFailurePolicy;
use App\Services\AiInputAttachmentService;
use App\Services\AiPromptPolicy;
use App\Services\AiStreamCheckpointService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseChatAccessService;
use App\Services\OpenRouterService;
use App\Services\PaidAiCallExecutionService;
use App\Services\ProjectFeedbackThreadService;
use App\Services\ProjectSubmissionFileRetentionService;
use App\Support\ProjectReportRetryPolicy;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use App\Support\UnicodeText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class GenerateProjectFeedback implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    // OpenRouter may use the full 50-second transport cap. Preserve enough
    // time to land and settle a valid report after the final response byte.
    public int $timeout = 80;
    public int $uniqueFor = 600;
    public bool $failOnTimeout = true;
    public string $executionId;

    public function __construct(public int $submissionId)
    {
        $this->executionId = (string) Str::uuid();
        $this->onQueue((string) config('queue.channels.ai_feedback', 'ai-feedback'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [20, 90];
    }

    public function uniqueId(): string
    {
        return 'project-feedback:' . $this->submissionId;
    }

    public function handle(
        CourseChatAccessService $access,
        CourseAccessPlanService $plans,
        AiEntitlementBudgetService $budget,
        OpenRouterService $openRouter,
        ProjectFeedbackThreadService $threads,
        AiInputAttachmentService $attachments,
        PaidAiCallExecutionService $paidCalls,
        AiStreamCheckpointService $streamCheckpoints,
        AiPromptPolicy $promptPolicy,
        AiFailurePolicy $failurePolicy,
        ProjectSubmissionFileRetentionService $fileRetention
    ): void {
        $submission = ProjectSubmission::with('project')->find($this->submissionId);
        if (!$submission || $submission->review_status !== ProjectSubmission::STATUS_PASSED) return;
        if (!User::query()->whereKey($submission->user_id)->where('active', true)->exists()) {
            // Do not leave a revoked learner's report permanently queued for
            // the recovery command to dispatch every two minutes.
            $this->markUnavailable($submission->id, 'account_unavailable');
            $fileRetention->purgeIfEligible($submission->fresh(), true);
            return;
        }

        $evaluationSnapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        if (!$evaluationSnapshot) {
            $this->markUnavailable($submission->id, 'project_context_missing');
            $fileRetention->purgeIfEligible($submission->fresh(), true);
            return;
        }
        $courseId = (int) $evaluationSnapshot['course_id'];
        $course = Course::query()->find($courseId);
        if (!$course || $courseId <= 0) {
            $this->markUnavailable($submission->id, 'project_context_missing');
            $fileRetention->purgeIfEligible($submission->fresh(), true);
            return;
        }

        $enrollment = $access->activeCapturedEnrollmentFor(
            (int) $submission->user_id,
            $courseId,
            (int) data_get($evaluationSnapshot, 'access.enrollment_id')
        );
        $currentTerms = $enrollment ? $plans->termsForEnrollment($enrollment) : null;
        $currentContract = $plans->publicPayloadFromTerms($currentTerms ?? []);
        $evaluationTerms = data_get($evaluationSnapshot, 'access.terms');
        $evaluationTerms = is_array($evaluationTerms) ? $evaluationTerms : null;
        $contract = $plans->publicPayloadFromTerms($evaluationTerms ?? []);
        if (
            !$enrollment
            || !$currentTerms
            || !$evaluationTerms
            || !$access->enrollmentAllowsVariableCostFeatures($enrollment)
            || !(bool) $currentContract['project_report_enabled']
            || !(bool) $contract['project_report_enabled']
        ) {
            $this->markUnavailable($submission->id, 'report_not_included');
            $fileRetention->purgeIfEligible($submission->fresh(), true);
            return;
        }
        $projectPolicy = (array) $evaluationSnapshot['project'];
        $snapshotFingerprint = (string) $evaluationSnapshot['fingerprint'];

        $metadata = is_array($submission->submission_metadata) ? $submission->submission_metadata : [];
        $requestId = (string) data_get(
            $metadata,
            'ai_feedback.request_id',
            $submission->public_id
        );
        // A worker may die after marking the submission as processing. Let the
        // queued retry continue; ShouldBeUnique still prevents concurrent runs.
        if (data_get($metadata, 'ai_feedback.status') === 'ready') {
            // The review summary and the paid report are two different records.
            // Older workers stored the report in ProjectSubmission::feedback,
            // overwriting the moderator's decision note. Recover new reports
            // from the settled provider event (or the thread) and reserve the
            // submission field for the append-only review decision summary.
            $event = AiUsageEvent::query()
                ->where('request_id', $requestId)
                ->where('feature', 'project_feedback')
                ->first();
            $report = trim((string) data_get($event?->metadata, 'accepted_response', ''));
            if ($report === '') {
                $report = trim((string) $submission->feedbackThread?->messages()
                    ->where('role', 'assistant')
                    ->where('client_request_id', 'report:' . $submission->public_id)
                    ->where('status', 'completed')
                    ->value('body'));
            }
            if ($report !== '') {
                $threads->storeInitialReport($submission, $enrollment, $courseId, $evaluationTerms, $report);
                $paidCalls->markPresented($event);
                $fileRetention->purgeIfEligible($submission->fresh());
            }
            return;
        }
        $text = UnicodeText::limit(
            UnicodeText::clean(strip_tags((string) $submission->submission_text)),
            8000
        );
        $ownedAttachments = $attachments->forOwner(
            AiInputAttachment::OWNER_PROJECT_SUBMISSION,
            (int) $submission->id
        );
        if ($text === '' && $ownedAttachments->isEmpty()) {
            $metadata['ai_feedback'] = ['status' => 'not_applicable', 'reason' => 'no_text_input'];
            $submission->forceFill(['submission_metadata' => $metadata])->save();
            $threads->storeInitialReport(
                $submission,
                $enrollment,
                $courseId,
                $evaluationTerms,
                trim((string) ($submission->feedback ?: 'تم اعتماد المحاولة وفتح المحتوى التالي'))
            );
            $fileRetention->purgeIfEligible($submission->fresh());
            return;
        }

        $claimed = DB::transaction(function () use ($submission): bool {
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($locked->review_status !== ProjectSubmission::STATUS_PASSED) return false;
            $meta = is_array($locked->submission_metadata) ? $locked->submission_metadata : [];
            $status = (string) data_get($meta, 'ai_feedback.status', '');
            if ($status === 'ready') return false;

            $owner = (string) data_get($meta, 'ai_feedback.execution_id', '');
            $leaseExpiresAt = (string) data_get($meta, 'ai_feedback.lease_expires_at', '');
            $leaseIsLive = $leaseExpiresAt !== ''
                && strtotime($leaseExpiresAt) !== false
                && strtotime($leaseExpiresAt) > time();
            if ($status === 'processing' && $owner !== $this->executionId && $leaseIsLive) {
                return false;
            }

            $meta['ai_feedback'] = [
                'status' => 'processing',
                'execution_id' => $this->executionId,
                'request_id' => data_get(
                    $meta,
                    'ai_feedback.request_id',
                    $locked->public_id
                ),
                'retry_count' => (int) data_get($meta, 'ai_feedback.retry_count', 0),
                'attempt' => $this->attempts(),
                'started_at' => now()->toIso8601String(),
                'lease_expires_at' => now()->addSeconds($this->timeout + 30)->toIso8601String(),
            ];
            $locked->forceFill(['submission_metadata' => $meta])->save();

            return true;
        }, 3);
        if (!$claimed) return;

        $model = '';
        $maxTokens = max(80, min(
            (int) config('openrouter.max_tokens', 800),
            (int) (($evaluationTerms['max_output_tokens'] ?? null) ?: 320)
        ));
        $requirements = UnicodeText::limit(
            UnicodeText::clean(strip_tags((string) ($projectPolicy['requirements_text'] ?? ''))),
            6000
        );
        $courseTitle = UnicodeText::limit(UnicodeText::clean((string) (
            data_get($evaluationSnapshot, 'course.title_ar')
            ?: data_get($evaluationSnapshot, 'course.title_en')
            ?: $course->name_ar
            ?: $course->name_en
        )), 240);
        $projectTitle = UnicodeText::limit(UnicodeText::clean((string) (
            ($projectPolicy['title_ar'] ?? null)
            ?: ($projectPolicy['title_en'] ?? null)
            ?: ($projectPolicy['title'] ?? null)
        )), 240);
        $promptVersion = $promptPolicy->version('project-report', [
            'snapshot' => $snapshotFingerprint,
            'requirements' => $requirements,
            'feedback_level' => (string) $contract['project_feedback_level'],
            'course_title' => $courseTitle,
            'project_title' => $projectTitle,
        ]);
        $messages = [[
            'role' => 'system',
            'content' => $promptPolicy->projectReport(
                $requirements,
                $courseTitle,
                $projectTitle
            ),
        ], [
            'role' => 'user',
            'content' => array_merge([[
                'type' => 'text',
                'text' => $promptPolicy->learnerSubmission($text),
            ]], $attachments->providerParts($ownedAttachments)),
        ]];

        $reservation = null;
        $providerResultKnown = false;
        $reportMessage = null;
        try {
            $model = $openRouter->configuredModel('project_model');
            $estimated = $maxTokens
                + (int) ceil((strlen($requirements) + strlen($text)) / 4)
                + $attachments->estimatedInputTokens($ownedAttachments);
            $reservation = $budget->reserve(
                $enrollment,
                'project_feedback',
                $estimated,
                $model,
                $requestId
            );
            if (!$reservation) {
                throw new AiPlanLimitReachedException('Project feedback is not metered for this enrollment.');
            }
            if ($reservation->status === 'completed') {
                $replay = trim((string) data_get($reservation->metadata, 'accepted_response', ''));
                if ($replay === '') {
                    throw new \RuntimeException('Completed project report has no replay response.');
                }
                $savedAnnotations = data_get($reservation->metadata, 'provider_file_annotations', []);
                if ($ownedAttachments->isNotEmpty() && is_array($savedAnnotations) && $savedAnnotations !== []) {
                    $attachments->markProcessed($ownedAttachments, $savedAnnotations);
                }
                $result = ['message' => $replay];
            } else {
                if ($reservation->status !== 'reserved') {
                    throw new \RuntimeException('Project report request cannot be resumed.');
                }
                $landed = $paidCalls->landedResult($reservation->fresh());
                if ($landed !== null) {
                    $settlement = $budget->settleForActiveUser(
                        $reservation,
                        $landed,
                        (int) $submission->user_id
                    );
                    if (!AiEntitlementBudgetService::settlementAllowsDelivery($settlement)) return;
                    $result = $landed;
                    if ($ownedAttachments->isNotEmpty()) {
                        $attachments->markProcessed(
                            $ownedAttachments,
                            is_array($landed['file_annotations'] ?? null)
                                ? $landed['file_annotations']
                                : []
                        );
                    }
                } else {
                    $reportMessage = $threads->beginInitialReport(
                        $submission,
                        $enrollment,
                        $courseId,
                        $evaluationTerms
                    );
                    $callState = $paidCalls->beginForActiveUser(
                        $reservation,
                        $this->executionId,
                        (int) $submission->user_id
                    );
                    if ($callState === PaidAiCallExecutionService::INACTIVE) {
                        $budget->release($reservation, 'account_deleted_before_provider');
                        return;
                    }
                    if ($callState !== PaidAiCallExecutionService::START) {
                        if ($callState === PaidAiCallExecutionService::LIVE) return;
                        $fresh = $reservation->fresh();
                        if (
                            $callState === PaidAiCallExecutionService::STALE_STARTED
                            && $paidCalls->providerWasStarted($fresh)
                        ) {
                            $paidCalls->settleUnknown($budget, $fresh, [
                                'project_id' => (int) $submission->project_id,
                                'submission_id' => (string) $submission->public_id,
                                'prompt_version' => $promptVersion,
                            ]);
                        }
                        $this->markUnavailable($submission->id, 'provider_outcome_unknown');
                        return;
                    }

                    $result = $openRouter->chat(
                        $model,
                        $messages,
                        (float) config('openrouter.temperature', .35),
                        $maxTokens,
                        (string) $reservation->request_id,
                        function (array $providerResult) use (
                            $paidCalls,
                            $reservation,
                            $submission,
                            $promptVersion,
                            $contract,
                            $courseId
                        ): void {
                            $providerResult['request_context'] = [
                                'course_id' => (int) $courseId,
                                'project_id' => (int) $submission->project_id,
                                'submission_id' => (string) $submission->public_id,
                                'prompt_version' => $promptVersion,
                                'feedback_level' => (string) $contract['project_feedback_level'],
                            ];
                            $paidCalls->landSuccessfulResultForActiveUser(
                                $reservation,
                                $this->executionId,
                                (int) $submission->user_id,
                                $providerResult
                            );
                        },
                        function (string $partial) use (
                            $streamCheckpoints,
                            $reportMessage
                        ): void {
                            $streamCheckpoints->projectMessage($reportMessage, $partial);
                        }
                    );
                    $result['request_context'] = [
                        'course_id' => (int) $courseId,
                        'project_id' => (int) $submission->project_id,
                        'submission_id' => (string) $submission->public_id,
                        'prompt_version' => $promptVersion,
                        'feedback_level' => (string) $contract['project_feedback_level'],
                    ];
                    $providerResultKnown = true;
                    $landingState = $paidCalls->landSuccessfulResultForActiveUser(
                        $reservation,
                        $this->executionId,
                        (int) $submission->user_id,
                        $result
                    );
                    if ($landingState !== PaidAiCallExecutionService::LANDED) return;
                    $result = $paidCalls->landedResult($reservation->fresh()) ?? $result;
                    $settlement = $budget->settleForActiveUser(
                        $reservation,
                        $result,
                        (int) $submission->user_id
                    );
                    if (!AiEntitlementBudgetService::settlementAllowsDelivery($settlement)) return;
                    if ($ownedAttachments->isNotEmpty()) {
                        $attachments->markProcessed(
                            $ownedAttachments,
                            is_array($result['file_annotations'] ?? null)
                                ? $result['file_annotations']
                                : []
                        );
                    }
                }
            }
            DB::transaction(function () use ($submission, $contract, $result, $requestId): void {
                if (!User::query()->whereKey($submission->user_id)->where('active', true)
                    ->lockForUpdate()->exists()) return;
                $fresh = ProjectSubmission::query()->lockForUpdate()->find($submission->id);
                if (!$fresh) return;
                $meta = is_array($fresh->submission_metadata) ? $fresh->submission_metadata : [];
                if (
                    data_get($meta, 'ai_feedback.status') === 'ready'
                    || data_get($meta, 'ai_feedback.execution_id') !== $this->executionId
                ) {
                    return;
                }
                $meta['ai_feedback'] = [
                    'status' => 'ready',
                    'level' => $contract['project_feedback_level'],
                    'request_id' => $requestId,
                    'generated_at' => now()->toIso8601String(),
                ];
                $fresh->forceFill(['submission_metadata' => $meta])->save();
            }, 3);
            $submission->refresh();
            $threads->storeInitialReport(
                $submission,
                $enrollment,
                $courseId,
                $evaluationTerms,
                trim((string) $result['message'])
            );
            $paidCalls->markPresented($reservation?->fresh());
            $fileRetention->purgeIfEligible($submission->fresh());
        } catch (AiPlanLimitReachedException $exception) {
            $this->markUnavailable($submission->id, 'plan_budget_reached');
            $fileRetention->purgeIfEligible($submission->fresh(), true);
        } catch (AiProviderUnavailableException $exception) {
            if ($ownedAttachments->isNotEmpty() && $exception->fileAnnotations !== []) {
                $attachments->markProcessed($ownedAttachments, $exception->fileAnnotations);
            }
            if ($exception->retrySafe && $reservation) {
                $paidCalls->markRetrySafe($reservation, $this->executionId);
            }
            if ($exception->retrySafe && $this->attempts() < $this->tries) {
                $this->markRetryable($submission->id);
                throw $exception;
            }
            if ($exception->outcomeUnknown && $reservation) {
                $paidCalls->settleUnknown($budget, $reservation, [
                    'project_id' => (int) $submission->project_id,
                    'submission_id' => (string) $submission->public_id,
                    'prompt_version' => $promptVersion,
                ]);
            } else {
                $budget->release($reservation, 'provider_unavailable');
            }
            $failureCode = $exception->outcomeUnknown
                ? 'provider_outcome_unknown'
                : $failurePolicy->providerCode($exception);
            $this->markUnavailable(
                $submission->id,
                $failureCode
            );
            // Keep the project input while the public contract still offers
            // a safe report retry. The bounded retention sweep removes it if
            // the learner never retries; terminal failures can be retired now.
            $freshSubmission = $submission->fresh();
            $retryCount = (int) data_get(
                $freshSubmission->submission_metadata,
                'ai_feedback.retry_count',
                0
            );
            $fileRetention->purgeIfEligible(
                $freshSubmission,
                !ProjectReportRetryPolicy::allows($failureCode, $retryCount, null)
            );
        } catch (\Throwable $exception) {
            $settled = $reservation?->fresh();
            $accepted = trim((string) data_get(
                $settled?->metadata,
                'accepted_response',
                ''
            ));
            if ($settled?->status === 'completed' && $accepted !== '') {
                $savedAnnotations = data_get(
                    $settled->metadata,
                    'provider_file_annotations',
                    []
                );
                if (
                    $ownedAttachments->isNotEmpty()
                    && is_array($savedAnnotations)
                    && $savedAnnotations !== []
                ) {
                    $attachments->markProcessed($ownedAttachments, $savedAnnotations);
                }
                // The paid result is durable. Preserve a recoverable state so
                // this job or the recovery command can finish the report and
                // thread writes without another provider request.
                $this->markRetryable($submission->id);
                throw $exception;
            }
            if ($providerResultKnown || $paidCalls->landedResult($settled) !== null) {
                $this->markRetryable($submission->id);
                throw $exception;
            }
            if ($paidCalls->providerWasStarted($reservation?->fresh())) {
                $paidCalls->settleUnknown($budget, $reservation, [
                    'project_id' => (int) $submission->project_id,
                    'submission_id' => (string) $submission->public_id,
                    'prompt_version' => $promptVersion,
                ]);
            } else {
                $budget->release($reservation, 'project_feedback_failed');
            }
            $this->markUnavailable($submission->id, 'provider_unavailable');
            throw $exception;
        }
    }

    private function markRetryable(int $submissionId): void
    {
        DB::transaction(function () use ($submissionId): void {
            $submission = ProjectSubmission::query()->lockForUpdate()->find($submissionId);
            if (!$submission) return;
            $metadata = is_array($submission->submission_metadata)
                ? $submission->submission_metadata
                : [];
            if (
                data_get($metadata, 'ai_feedback.status') === 'ready'
                || data_get($metadata, 'ai_feedback.execution_id') !== $this->executionId
            ) {
                return;
            }
            $metadata['ai_feedback'] = [
                'status' => 'queued',
                'execution_id' => $this->executionId,
                'request_id' => data_get(
                    $metadata,
                    'ai_feedback.request_id',
                    $submission->public_id
                ),
                'retry_count' => (int) data_get($metadata, 'ai_feedback.retry_count', 0),
                'attempt' => $this->attempts(),
                'retry_after' => now()->addSeconds(
                    $this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]
                )->toIso8601String(),
            ];
            $submission->forceFill(['submission_metadata' => $metadata])->save();
        }, 3);
    }

    public function failed(Throwable $exception): void
    {
        $submission = ProjectSubmission::query()->find($this->submissionId);
        if (data_get($submission?->submission_metadata, 'ai_feedback.status') === 'unavailable') {
            return;
        }
        $event = $submission
            ? AiUsageEvent::query()
                ->where('request_id', data_get(
                    $submission->submission_metadata,
                    'ai_feedback.request_id',
                    $submission->public_id
                ))
                ->where('feature', 'project_feedback')
                ->first()
            : null;
        if (
            $event?->status === 'completed'
            && trim((string) data_get($event->metadata, 'accepted_response', '')) !== ''
        ) {
            $savedAnnotations = data_get(
                $event->metadata,
                'provider_file_annotations',
                []
            );
            if ($submission && is_array($savedAnnotations) && $savedAnnotations !== []) {
                $attachmentService = app(AiInputAttachmentService::class);
                $ownedAttachments = $attachmentService->forOwner(
                    AiInputAttachment::OWNER_PROJECT_SUBMISSION,
                    (int) $submission->id
                );
                if ($ownedAttachments->isNotEmpty()) {
                    $attachmentService->markProcessed($ownedAttachments, $savedAnnotations);
                }
            }
            // Keep the processing lease recoverable. The scheduled recovery
            // will replay the settled answer without another provider call.
            return;
        }
        if ($event?->status === 'reserved') {
            $calls = app(PaidAiCallExecutionService::class);
            if ($calls->landedResult($event) !== null) {
                return;
            }
            if ($calls->providerWasStarted($event)) {
                $calls->settleUnknown(app(AiEntitlementBudgetService::class), $event, [
                    'submission_id' => (string) $submission?->public_id,
                ]);
            } else {
                app(AiEntitlementBudgetService::class)->release($event, 'project_feedback_worker_failed');
            }
        }
        $this->markUnavailable($this->submissionId, 'worker_failed');
        if ($submission) {
            app(ProjectSubmissionFileRetentionService::class)
                ->purgeIfEligible($submission->fresh(), false);
        }
    }

    private function markUnavailable(int $submissionId, string $reason): void
    {
        $changed = DB::transaction(function () use ($submissionId, $reason): bool {
            $submission = ProjectSubmission::query()->lockForUpdate()->find($submissionId);
            if (!$submission) return false;
            $metadata = is_array($submission->submission_metadata) ? $submission->submission_metadata : [];
            if (data_get($metadata, 'ai_feedback.status') === 'ready') return false;
            $owner = (string) data_get($metadata, 'ai_feedback.execution_id', '');
            if ($owner !== '' && $owner !== $this->executionId) return false;

            $metadata['ai_feedback'] = [
                'status' => 'unavailable',
                'reason' => $reason,
                'request_id' => data_get(
                    $metadata,
                    'ai_feedback.request_id',
                    $submission->public_id
                ),
                'retry_count' => (int) data_get($metadata, 'ai_feedback.retry_count', 0),
                'failed_at' => now()->toIso8601String(),
            ];
            $submission->forceFill(['submission_metadata' => $metadata])->save();

            return true;
        }, 3);

        if ($changed) {
            app(ProjectFeedbackThreadService::class)
                ->failInitialReport($submissionId, $reason);
        }
    }
}
