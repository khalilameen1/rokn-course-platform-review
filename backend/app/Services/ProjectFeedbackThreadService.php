<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateProjectFeedbackReply;
use App\Models\AiInputAttachment;
use App\Models\AiEntitlementUsage;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\DurableJobDispatch;
use App\Support\ProjectSubmissionLifecycle;
use App\Support\UnicodeText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProjectFeedbackThreadService
{
    public function __construct(
        private CourseAccessPlanService $accessPlans,
        private AiInputAttachmentService $attachments,
        private CourseChatAccessService $courseAccess
    ) {
    }

    /** @param array<string,mixed> $terms */
    public function beginInitialReport(
        ProjectSubmission $submission,
        CourseEnrollment $enrollment,
        int $courseId,
        array $terms
    ): ProjectFeedbackMessage {
        return DB::transaction(function () use (
            $submission,
            $enrollment,
            $courseId,
            $terms
        ): ProjectFeedbackMessage {
            if (!User::query()->whereKey($submission->user_id)->where('active', true)
                ->lockForUpdate()->exists()) {
                throw new AuthorizationException('Project feedback owner is unavailable.');
            }
            $lockedSubmission = ProjectSubmission::query()
                ->lockForUpdate()->findOrFail($submission->id);
            $contract = $this->accessPlans->publicPayloadFromTerms($terms);
            if (!(bool) $contract['project_report_enabled']) {
                throw new \LogicException('The entitlement does not include project feedback.');
            }
            $thread = ProjectFeedbackThread::query()->firstOrCreate(
                ['submission_id' => $lockedSubmission->id],
                [
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $lockedSubmission->user_id,
                    'course_id' => $courseId,
                    'project_id' => $lockedSubmission->project_id,
                    'enrollment_id' => $enrollment->id,
                    'access_plan_id' => $enrollment->access_plan_id,
                    'feedback_level' => (string) $contract['project_feedback_level'],
                    'can_reply' => (bool) $contract['project_thread_reply_enabled'],
                    'status' => 'processing',
                ]
            );
            if ($thread->status !== 'ready') {
                $thread->forceFill(['status' => 'processing'])->save();
            }
            $report = ProjectFeedbackMessage::query()->firstOrCreate(
                [
                    'thread_id' => $thread->id,
                    'client_request_id' => 'report:' . $lockedSubmission->public_id,
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'role' => 'assistant',
                    'status' => ProjectFeedbackMessage::STREAMING,
                ]
            );
            if ($report->status !== ProjectFeedbackMessage::COMPLETED) {
                $report->forceFill([
                    'status' => ProjectFeedbackMessage::STREAMING,
                    'body' => null,
                    'error_code' => null,
                    'completed_at' => null,
                ])->save();
            }

            return $report;
        }, 3);
    }

    /** @param array<string,mixed> $terms */
    public function storeInitialReport(
        ProjectSubmission $submission,
        CourseEnrollment $enrollment,
        int $courseId,
        array $terms,
        string $report
    ): ProjectFeedbackThread {
        $body = $this->safeBody($report, 12000);
        if ($body === '') {
            throw new \UnexpectedValueException('Project feedback is empty.');
        }

        return DB::transaction(function () use ($submission, $enrollment, $courseId, $terms, $body): ProjectFeedbackThread {
            if (!User::query()->whereKey($submission->user_id)->where('active', true)
                ->lockForUpdate()->exists()) {
                throw new AuthorizationException('Project feedback owner is unavailable.');
            }
            $lockedSubmission = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $contract = $this->accessPlans->publicPayloadFromTerms($terms);
            $level = (string) $contract['project_feedback_level'];
            if (!(bool) $contract['project_report_enabled']) {
                throw new \LogicException('The entitlement does not include project feedback.');
            }
            $canReply = (bool) $contract['project_thread_reply_enabled'];
            $thread = ProjectFeedbackThread::query()->firstOrCreate(
                ['submission_id' => $lockedSubmission->id],
                [
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $lockedSubmission->user_id,
                    'course_id' => $courseId,
                    'project_id' => $lockedSubmission->project_id,
                    'enrollment_id' => $enrollment->id,
                    'access_plan_id' => $enrollment->access_plan_id,
                    'feedback_level' => $level,
                    'can_reply' => $canReply,
                    'status' => 'ready',
                ]
            );

            $thread->forceFill([
                'feedback_level' => $level,
                'can_reply' => $canReply,
                'status' => 'ready',
            ])->save();
            $reportMessage = ProjectFeedbackMessage::query()->firstOrNew(
                [
                    'thread_id' => $thread->id,
                    'client_request_id' => 'report:' . $lockedSubmission->public_id,
                ]
            );
            if (!$reportMessage->exists) {
                $reportMessage->public_id = (string) Str::uuid();
                $reportMessage->role = 'assistant';
            }
            $reportMessage->forceFill([
                'status' => ProjectFeedbackMessage::COMPLETED,
                'body' => $body,
                'error_code' => null,
                'completed_at' => now(),
            ])->save();
            $annotations = $this->attachments->providerAnnotations(
                $this->attachments->forOwner(
                    AiInputAttachment::OWNER_PROJECT_SUBMISSION,
                    (int) $lockedSubmission->id
                )
            );
            if ($annotations !== []) {
                $reportMessage->forceFill(['provider_annotations' => $annotations])->save();
            }

            return $thread->fresh('messages');
        }, 3);
    }

    public function failInitialReport(int $submissionId, string $code): void
    {
        DB::transaction(function () use ($submissionId, $code): void {
            $thread = ProjectFeedbackThread::query()
                ->where('submission_id', $submissionId)
                ->lockForUpdate()
                ->first();
            if (!$thread || $thread->status === 'ready') return;
            $thread->forceFill(['status' => 'failed'])->save();
            ProjectFeedbackMessage::query()
                ->where('thread_id', $thread->id)
                ->where('role', 'assistant')
                ->where('client_request_id', 'like', 'report:%')
                ->where('status', '<>', ProjectFeedbackMessage::COMPLETED)
                ->update([
                    'status' => ProjectFeedbackMessage::FAILED,
                    'body' => null,
                    'error_code' => substr($code, 0, 64),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        }, 3);
    }

    public function queueReply(
        User $user,
        ProjectFeedbackThread $thread,
        string $body,
        string $clientRequestId,
        array $attachmentIds = []
    ): ProjectFeedbackMessage {
        $body = $this->safeBody($body, 2000);
        if ($body === '' && $attachmentIds === []) {
            throw ValidationException::withMessages(['message' => ['اكتب رسالتك أولًا']]);
        }
        if ($body === '') $body = 'راجع المرفق';

        sort($attachmentIds);
        $message = DB::transaction(function () use ($user, $thread, $body, $clientRequestId, $attachmentIds): ProjectFeedbackMessage {
            if (!User::query()->whereKey($user->id)->where('active', true)->lockForUpdate()->exists()) {
                throw new AuthorizationException('Project thread not found.');
            }
            $locked = ProjectFeedbackThread::query()->lockForUpdate()->findOrFail($thread->id);
            if ((int) $locked->user_id !== (int) $user->id) {
                throw new AuthorizationException('Project thread not found.');
            }
            $enrollment = CourseEnrollment::query()->lockForUpdate()->find($locked->enrollment_id);
            $contract = $enrollment
                ? $this->replyContractFor($locked, $enrollment)
                : null;
            if (!$contract) {
                throw new AuthorizationException('Replies are not included in this course plan.');
            }
            $maxAttachments = min(5, max(0, (int) ($contract['project_attachment_max_files'] ?? 0)));
            if ($attachmentIds !== [] && (
                !(bool) ($contract['project_attachments_enabled'] ?? false)
                || count($attachmentIds) > $maxAttachments
            )) {
                throw new AuthorizationException('Attachments are not included in this course plan.');
            }

            $existing = ProjectFeedbackMessage::query()
                ->where('thread_id', $locked->id)
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing) {
                $requestedFingerprint = hash('sha256', json_encode($attachmentIds, JSON_THROW_ON_ERROR));
                if ($existing->role !== 'user'
                    || !hash_equals(hash('sha256', (string) $existing->body), hash('sha256', $body))
                    || !hash_equals(
                        (string) ($existing->attachment_request_fingerprint ?: hash('sha256', '[]')),
                        $requestedFingerprint
                    )) {
                    throw new \UnexpectedValueException('Project message request identity conflict.');
                }
                return $existing;
            }

            $usage = AiEntitlementUsage::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('feature', AiEntitlementUsage::FEATURE_PROJECT_FOLLOWUP)
                ->lockForUpdate()
                ->first();
            $queuedMessages = ProjectFeedbackMessage::query()
                ->where('role', 'user')
                ->where('status', ProjectFeedbackMessage::QUEUED)
                ->whereHas('thread', fn ($query) => $query->where('enrollment_id', $enrollment->id))
                ->count();
            $sentMessages = ProjectFeedbackMessage::query()
                ->where('role', 'user')
                ->where('status', ProjectFeedbackMessage::SENT)
                ->whereHas('thread', fn ($query) => $query->where('enrollment_id', $enrollment->id))
                ->count();
            $threadInFlight = ProjectFeedbackMessage::query()
                ->where('thread_id', $locked->id)
                ->where('role', 'user')
                ->whereIn('status', [ProjectFeedbackMessage::QUEUED, ProjectFeedbackMessage::SENT])
                ->exists();
            if ($threadInFlight) {
                throw ValidationException::withMessages([
                    'message' => ['انتظر رد ركن على الرسالة الحالية'],
                ]);
            }
            $usedRequests = (int) ($usage?->used_requests ?? 0);
            $reservedRequests = max((int) ($usage?->reserved_requests ?? 0), $sentMessages);
            $messageLimit = (int) $contract['project_message_limit'];
            if (
                $messageLimit <= 0
                || $usedRequests + $reservedRequests + $queuedMessages >= $messageLimit
            ) {
                throw ValidationException::withMessages(['message' => ['اكتملت رسائل متابعة المشاريع في هذه الفئة']]);
            }

            $message = ProjectFeedbackMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'thread_id' => $locked->id,
                'role' => 'user',
                'client_request_id' => $clientRequestId,
                'status' => ProjectFeedbackMessage::QUEUED,
                'body' => $body,
                'attachment_request_fingerprint' => hash(
                    'sha256', json_encode($attachmentIds, JSON_THROW_ON_ERROR)
                ),
                'attachment_count' => count($attachmentIds),
            ]);
            $course = Course::query()->findOrFail($locked->course_id);
            try {
                $this->attachments->claim(
                    $user,
                    $course,
                    $attachmentIds,
                    AiInputAttachment::PURPOSE_PROJECT_FOLLOWUP,
                    AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE,
                    (int) $message->id
                );
            } catch (\UnexpectedValueException) {
                throw ValidationException::withMessages([
                    'attachment_ids' => ['أحد المرفقات غير متاح لهذه الرسالة'],
                ]);
            }
            return $message;
        }, 3);

        // Re-dispatching an idempotent queued message recovers a queue push
        // lost after the database commit. The worker atomically claims QUEUED
        // only, so duplicate jobs cannot reach the paid provider twice.
        if ($message->status === ProjectFeedbackMessage::QUEUED) {
            try {
                DurableJobDispatch::afterCommit(
                    new GenerateProjectFeedbackReply((int) $message->id)
                );
            } catch (\Throwable $exception) {
                // The QUEUED message is the durable handoff. The recovery
                // command will enqueue it after the broker returns, so the
                // accepted learner message must not become a false HTTP 500.
                report($exception);
            }
        }

        return $message;
    }

    /** @return array<string,mixed> */
    public function payload(ProjectFeedbackThread $thread): array
    {
        $thread->loadMissing(['enrollment', 'submission']);
        $recentMessages = ProjectFeedbackMessage::query()
            ->where('thread_id', $thread->id)
            ->orderByDesc('id')
            ->limit(60)
            ->get()
            ->reverse()
            ->values();
        $initialReport = ProjectFeedbackMessage::query()
            ->where('thread_id', $thread->id)
            ->where('role', 'assistant')
            ->where('client_request_id', 'like', 'report:%')
            ->orderBy('id')
            ->first();
        if ($initialReport && !$recentMessages->contains('id', $initialReport->id)) {
            $recentMessages->prepend($initialReport);
        }
        $terms = $thread->enrollment
            ? $this->accessPlans->termsForEnrollment($thread->enrollment)
            : null;
        $contract = $this->accessPlans->publicPayloadFromTerms($terms ?? []);
        $replyIncluded = (bool) $contract['project_thread_reply_enabled'];
        $canReply = $this->activeReplyContract($thread) !== null;
        $reportStatus = ProjectSubmissionLifecycle::reportStatus(
            (bool) $contract['project_report_enabled'],
            (string) $thread->status,
            (string) data_get($thread->submission?->submission_metadata, 'ai_feedback.status'),
            (string) $thread->submission?->review_status
        );
        $usage = AiEntitlementUsage::query()
            ->where('enrollment_id', $thread->enrollment_id)
            ->where('feature', AiEntitlementUsage::FEATURE_PROJECT_FOLLOWUP)
            ->first();
        $pendingMessages = ProjectFeedbackMessage::query()
            ->where('role', 'user')
            ->whereIn('status', [ProjectFeedbackMessage::QUEUED, ProjectFeedbackMessage::SENT])
            ->whereHas('thread', fn ($query) => $query->where('enrollment_id', $thread->enrollment_id))
            ->get(['status', 'reserved_tokens']);
        $queuedRequests = $pendingMessages->where('status', ProjectFeedbackMessage::QUEUED)->count();
        $sentRequests = $pendingMessages->where('status', ProjectFeedbackMessage::SENT)->count();
        $reservedRequests = max((int) ($usage?->reserved_requests ?? 0), $sentRequests);
        $sentTokens = (int) $pendingMessages->where('status', ProjectFeedbackMessage::SENT)->sum('reserved_tokens');
        $reservedTokens = max((int) ($usage?->reserved_tokens ?? 0), $sentTokens);

        $attachmentsByMessage = AiInputAttachment::query()
            ->where('owner_type', AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE)
            ->whereIn('owner_id', $recentMessages->pluck('id'))
            ->where('status', AiInputAttachment::READY)
            ->get()->groupBy('owner_id');
        $failurePolicy = app(AiFailurePolicy::class);
        return [
            'id' => $thread->public_id,
            'thread_kind' => 'project_feedback',
            'course_id' => (int) $thread->course_id,
            'project_id' => (int) $thread->project_id,
            'submission_id' => $thread->submission?->public_id,
            'feedback_level' => $contract['project_feedback_level'],
            'report_enabled' => (bool) $contract['project_report_enabled'],
            'can_reply' => $canReply,
            'reply_enabled' => $replyIncluded,
            'attachments_enabled' => $canReply && (bool) ($contract['project_attachments_enabled'] ?? false),
            'attachment_max_files' => $canReply
                ? min(5, max(0, (int) ($contract['project_attachment_max_files'] ?? 0)))
                : 0,
            'status' => $thread->status,
            'report_status' => $reportStatus,
            'remaining_messages' => max(
                0,
                (int) $contract['project_message_limit']
                    - (int) ($usage?->used_requests ?? 0)
                    - $reservedRequests
                    - $queuedRequests
            ),
            'remaining_tokens' => max(
                0,
                (int) $contract['project_token_budget']
                    - (int) ($usage?->used_tokens ?? 0)
                    - $reservedTokens
            ),
            'has_older_messages' => ProjectFeedbackMessage::query()
                ->where('thread_id', $thread->id)
                ->whereNotIn('id', $recentMessages->pluck('id'))
                ->exists(),
            'messages' => $recentMessages->map(function (ProjectFeedbackMessage $message) use (
                $attachmentsByMessage,
                $failurePolicy
            ): array {
                $failure = $message->status === ProjectFeedbackMessage::FAILED
                    ? $failurePolicy->describe((string) $message->error_code)
                    : null;

                return [
                    'id' => $message->public_id,
                    'client_request_id' => $message->client_request_id,
                    'role' => $message->role,
                    'status' => $message->status,
                    'error_code' => $message->error_code,
                    'failure_category' => $failure['category'] ?? null,
                    'can_retry' => $failure['can_retry'] ?? null,
                    'retry_after_seconds' => $failure['retry_after_seconds'] ?? null,
                    'text' => $message->status === ProjectFeedbackMessage::COMPLETED
                        || $message->status === ProjectFeedbackMessage::STREAMING
                        || $message->role === 'user'
                        ? $message->body
                        : null,
                    'created_at' => $message->created_at?->toIso8601String(),
                    'attachments' => $attachmentsByMessage->get($message->id, collect())->map(
                        function (AiInputAttachment $attachment): array {
                            $expiresAt = now()->addMinutes(30);

                            return [
                                'id' => (string) $attachment->public_id,
                                'name' => (string) $attachment->original_file_name,
                                'mime_type' => (string) $attachment->mime_type,
                                'size_bytes' => (int) $attachment->size_bytes,
                                'download_url' => URL::temporarySignedRoute(
                                    'api.project-input-attachments.download',
                                    $expiresAt,
                                    [
                                        'attachment' => $attachment->public_id,
                                        'user' => $attachment->user_id,
                                    ]
                                ),
                                'download_url_expires_at' => $expiresAt->toIso8601String(),
                            ];
                        }
                    )->values(),
                ];
            })->values(),
        ];
    }

    /**
     * Resolve the single reply capability used by presentation, queueing and
     * attachment staging. A plan flag alone never authorizes provider spend.
     *
     * @return array<string,mixed>|null
     */
    public function activeReplyContract(ProjectFeedbackThread $thread): ?array
    {
        $thread->loadMissing('enrollment');
        $enrollment = $thread->enrollment;
        return $enrollment ? $this->replyContractFor($thread, $enrollment) : null;
    }

    /** @return array{state:string,attachment?:AiInputAttachment} */
    public function uploadAttachment(
        User $user,
        ProjectFeedbackThread $thread,
        UploadedFile $file,
        string $clientUploadId
    ): array {
        if (!(bool) $user->active || (int) $thread->user_id !== (int) $user->id) {
            return ['state' => 'not_included'];
        }
        $contract = $this->activeReplyContract($thread);
        if (!$contract || !(bool) ($contract['project_attachments_enabled'] ?? false)) {
            return ['state' => 'not_included'];
        }

        $course = Course::query()->findOrFail($thread->course_id);
        try {
            $attachment = $this->attachments->store(
                $user,
                $course,
                $file,
                AiInputAttachment::PURPOSE_PROJECT_FOLLOWUP,
                $clientUploadId
            );
        } catch (\UnexpectedValueException $exception) {
            return ['state' => match ($exception->getMessage()) {
                'Unsupported AI attachment type.' => 'unsupported',
                'AI attachment staging limit reached.' => 'limit_reached',
                default => 'identity_conflict',
            }];
        }

        return ['state' => 'uploaded', 'attachment' => $attachment];
    }

    /** @return array<string,mixed>|null */
    private function replyContractFor(
        ProjectFeedbackThread $thread,
        CourseEnrollment $enrollment
    ): ?array {
        if (
            $thread->status !== 'ready'
            || !$enrollment->isActive()
        ) return null;

        $terms = $this->accessPlans->termsForEnrollment($enrollment);
        $contract = $this->accessPlans->publicPayloadFromTerms($terms ?? []);

        return $terms
            && (bool) ($contract['project_thread_reply_enabled'] ?? false)
            && $this->courseAccess->enrollmentAllowsVariableCostFeatures($enrollment)
                ? $contract
                : null;
    }

    private function safeBody(string $body, int $limit): string
    {
        // Messages are displayed as text in mobile and escaped in Blade.
        // Technical vocabulary and literal HTML belong to project discussion;
        // neither identifies a transport failure or executable markup here.
        return UnicodeText::limit(UnicodeText::clean($body), $limit);
    }
}
