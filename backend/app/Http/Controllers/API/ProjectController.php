<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\AiPlanLimitReachedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\SendProjectFeedbackMessageRequest;
use App\Http\Requests\API\SubmitProjectRequest;
use App\Http\Requests\API\UploadProjectFeedbackAttachmentRequest;
use App\Models\AiInputAttachment;
use App\Models\Project;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseEntitlementService;
use App\Services\CourseRevisionLearnerReadService;
use App\Services\CourseRevisionResolver;
use App\Services\CourseSectionAccessService;
use App\Services\ProjectAttachmentDownloadService;
use App\Services\ProjectFeedbackThreadService;
use App\Services\ProjectReportRetryService;
use App\Services\ProjectSubmissionEvaluationScheduler;
use App\Services\ProjectSubmissionEvaluationService;
use App\Services\ProjectSubmissionFilePolicy;
use App\Services\ProjectSubmissionOrchestrator;
use App\Services\ProjectSubmissionPresenter;
use App\Support\DownloadFilename;
use App\Support\UploadBudget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\Response;

final class ProjectController extends Controller
{
    public function __construct(
        private ProjectSubmissionEvaluationScheduler $evaluationScheduler,
        private ProjectSubmissionPresenter $submissions,
        private CourseSectionAccessService $sectionAccess,
        private ProjectFeedbackThreadService $feedbackThreads,
        private CourseEntitlementService $courseAccess,
        private CourseAccessPlanService $accessPlans,
        private CourseRevisionResolver $revisionResolver,
        private CourseRevisionLearnerReadService $revisionReads,
        private ProjectAttachmentDownloadService $downloads,
        private ProjectReportRetryService $reportRetries,
        private ProjectSubmissionOrchestrator $submissionOrchestrator,
        private ProjectSubmissionEvaluationService $evaluations,
        private ProjectSubmissionFilePolicy $filePolicy,
        private ApiResponseService $responses
    ) {}

    public function show($projectId): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user) {
            return $this->responses->error('سجّل الدخول أولًا', 401);
        }

        try {
            $project = Project::with(['section.course', 'section.module'])->findOrFail($projectId);
        } catch (ModelNotFoundException) {
            return $this->responses->error('المشروع غير متاح', 404);
        }
        if ($revisionChanged = $this->revisionChangedResponse($project)) {
            return $revisionChanged;
        }
        $courseId = (int) optional($project->section)->course_id;
        if (
            ! $courseId
            || ! $project->section
            || ! $this->sectionAccess->canAccessSection($user, $project->section)
        ) {
            return $this->responses->error('هذا المشروع غير متاح لحسابك', 403);
        }

        $latestSubmission = $this->revisionReads
            ->projectSubmissions((int) $user->id, [(int) $project->id])
            ->get((int) $project->id);
        if ($latestSubmission) {
            $latestSubmission = $this->evaluationScheduler->dispatchIfDue($latestSubmission);
        }

        $enrollment = $this->courseAccess->activeProjectEnrollmentFor((int) $user->id, $courseId);
        $terms = $enrollment ? $this->accessPlans->termsForEnrollment($enrollment) : null;
        $feedbackContract = $this->accessPlans->publicPayloadFromTerms($terms ?? []);
        $variableCostAllowed = $enrollment
            && $this->courseAccess->enrollmentAllowsVariableCostFeatures($enrollment);
        $feedbackLevel = $variableCostAllowed
            ? (string) $feedbackContract['project_feedback_level']
            : 'pass_only';
        $projectReportEnabled = $variableCostAllowed
            && (bool) $feedbackContract['project_report_enabled'];
        $projectReplyEnabled = (bool) $feedbackContract['project_thread_reply_enabled']
            && $variableCostAllowed;
        $latestSubmissionPayload = $latestSubmission
            ? $this->submissions->present($latestSubmission)
            : null;
        $submissionMimeTypes = $this->filePolicy->allowedMimeTypes($project);

        return $this->responses->success([
            'id' => $project->id,
            'requirements_text' => $project->requirements_text,
            // Prompt/model settings deliberately stay server-side.
            'submission_text_enabled' => (bool) $project->submission_text_enabled,
            'submission_files_enabled' => $submissionMimeTypes !== [],
            'submission_max_files' => ProjectSubmissionFilePolicy::maximumFiles($project),
            'submission_max_file_bytes' => ProjectSubmissionFilePolicy::maximumFileBytes(),
            'submission_allowed_mime_types' => $submissionMimeTypes,
            'is_graduation_project' => $project->is_graduation_project,
            'project_feedback' => [
                'level' => $feedbackLevel,
                'report_enabled' => $projectReportEnabled,
                'reply_enabled' => $projectReplyEnabled,
                'message_limit' => (int) $feedbackContract['project_message_limit'],
                'token_budget' => (int) $feedbackContract['project_token_budget'],
            ],
            'section' => [
                'id' => $project->section->id,
                'title' => $project->section->title,
                'order' => $project->section->order,
            ],
            'module' => $project->section->module ? [
                'id' => $project->section->module->id,
                'title' => $project->section->module->title,
                'order' => $project->section->module->order,
            ] : null,
            'course' => [
                'id' => $project->section->course->id,
                'title' => $project->section->course->name_ar,
            ],
            'latest_submission' => $latestSubmissionPayload,
        ], 'تم تحميل المشروع');
    }

    /**
     * Secure submission endpoint. Only the attempt is accepted from the client;
     * score and pass/fail are exclusively server decisions.
     */
    public function submit(SubmitProjectRequest $request, $projectId): JsonResponse
    {
        $uploadBudget = UploadBudget::start(
            (float) config('projects.submission_request_budget_seconds', 16)
        );
        $user = auth('api')->user();
        if (! $user) {
            return $this->responses->error('سجّل الدخول أولًا', 401);
        }

        $request->validated();

        try {
            $project = Project::with('section')->findOrFail($projectId);
            $project->loadMissing('section.course');
            if ($revisionChanged = $this->revisionChangedResponse($project, true)) {
                return $revisionChanged;
            }
            $files = array_values(array_filter([
                ...($request->file('submission_files', []) ?: []),
                $request->file('submission_file'),
            ]));
            $result = $this->submissionOrchestrator->submit(
                $user,
                $project,
                $request->input('submission_text'),
                $files,
                (string) ($request->header('Idempotency-Key') ?: $request->input('client_submission_id')),
                (array) $request->input('metadata', []),
                $uploadBudget
            );
        } catch (AuthorizationException $exception) {
            // Publication can win while files are staged, after the first
            // revision check. Return the same reload contract, not a false
            // loss-of-access message; unrelated authorization failures stay 403.
            $current = isset($project) ? $project->fresh(['section.course']) : null;
            if ($current && ($revisionChanged = $this->revisionChangedResponse($current, true))) {
                return $revisionChanged;
            }
            throw $exception;
        } catch (ValidationException $exception) {
            return $this->responses->error('راجع بيانات المشروع', 422, null, [
                'errors' => $exception->errors(),
            ]);
        } catch (\UnexpectedValueException) {
            return $this->responses->error(
                "تغيّر محتوى المشروع أثناء الإرسال\nأعد المحاولة",
                409,
                null,
                ['code' => 'submission_idempotency_conflict']
            );
        } catch (ModelNotFoundException) {
            return $this->responses->error('المشروع غير متاح', 404);
        } catch (FilesystemException $exception) {
            report($exception);

            return $this->responses->error(
                'تعذّر حفظ الملف الآن حاول مرة أخرى',
                503,
                null,
                ['code' => 'project_upload_temporarily_unavailable'],
                ['Retry-After' => '3']
            );
        }

        return match ($result['state']) {
            'invalid' => $this->responses->error('راجع ملفات المشروع', 422, null, [
                'errors' => [$result['field'] => [$result['message']]],
            ]),
            'forbidden' => $this->responses->error('لا يمكنك تسليم هذا المشروع من حسابك', 403),
            'prerequisites' => $this->responses->error(
                "أكمل المحتوى السابق أولًا\nثم سلّم المشروع",
                409,
                null,
                ['code' => 'project_prerequisites_incomplete']
            ),
            'report_note_required' => $this->responses->error(
                'اكتب سطرًا واضحًا عما نفذته لنعد تقرير مشروعك',
                422,
                null,
                ['code' => 'project_note_required']
            ),
            default => $this->responses->success(
                $this->submissions->present($result['submission']),
                'استلمنا مشروعك وبدأت مراجعته',
                202
            ),
        };
    }

    /** Recover a committed upload whose HTTP acknowledgement did not arrive. */
    public function lookupSubmission(Request $request, int $project): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user) {
            return $this->responses->error('سجّل الدخول أولًا', 401);
        }
        $validated = $request->validate([
            'client_submission_id' => ['required', 'string', 'max:100'],
        ]);
        $key = $validated['client_submission_id'];
        $submission = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project)
            ->where('idempotency_key', $key)
            ->first();
        // MySQL's case-insensitive collation must not turn a different opaque
        // client key into an acknowledgement of this upload.
        if (! $submission || ! hash_equals((string) $submission->idempotency_key, $key)) {
            return $this->responses->error('التسليم غير موجود', 404);
        }

        // This is a read receipt, not review recovery or a new submission.
        // In particular, do not dispatch work for an overdue pending row.
        return $this->responses->success(array_merge($this->submissions->present($submission), [
            'client_submission_id' => (string) $submission->idempotency_key,
        ]), 'تم العثور على التسليم');
    }

    public function submissionStatus(ProjectSubmission $submission): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user || $submission->user_id !== $user->id) {
            return $this->responses->error('التسليم غير متاح', 404);
        }

        $submission = $this->evaluationScheduler->dispatchIfDue($submission);

        return $this->responses->success($this->submissions->present($submission), 'تم تحميل حالة التسليم');
    }

    public function feedbackThread(ProjectFeedbackThread $thread): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user || (int) $thread->user_id !== (int) $user->id) {
            return $this->responses->error('محادثة المشروع غير متاحة', 404);
        }

        return $this->responses->success($this->feedbackThreads->payload($thread), 'تم تحميل مراجعة المشروع');
    }

    public function sendFeedbackMessage(SendProjectFeedbackMessageRequest $request, ProjectFeedbackThread $thread): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user || (int) $thread->user_id !== (int) $user->id) {
            return $this->responses->error('محادثة المشروع غير متاحة', 404);
        }

        $validated = $request->validated();

        try {
            $this->feedbackThreads->queueReply(
                $user,
                $thread,
                (string) ($validated['message'] ?? ''),
                (string) $validated['client_request_id'],
                array_values($validated['attachment_ids'] ?? [])
            );
        } catch (AiPlanLimitReachedException) {
            return $this->responses->error(
                'انتهى حد مناقشة المشاريع في اشتراكك',
                422,
                null,
                ['code' => 'project_discussion_limit_reached']
            );
        } catch (AuthorizationException) {
            return $this->responses->error('الردود غير متاحة لهذا المشروع', 403);
        } catch (\UnexpectedValueException) {
            return $this->responses->error(
                "تغيّرت الرسالة أثناء الإرسال\nأعد المحاولة",
                409,
                null,
                ['code' => 'project_message_idempotency_conflict']
            );
        }

        return $this->responses->success($this->feedbackThreads->payload($thread->fresh()), 'استلمنا رسالتك', 202);
    }

    public function uploadFeedbackAttachment(UploadProjectFeedbackAttachmentRequest $request, ProjectFeedbackThread $thread): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user || (int) $thread->user_id !== (int) $user->id) {
            return $this->responses->error('محادثة المشروع غير متاحة', 404);
        }
        $validated = $request->validated();
        $result = $this->feedbackThreads->uploadAttachment(
            $user,
            $thread,
            $validated['attachment'],
            (string) $validated['client_upload_id']
        );

        return match ($result['state']) {
            'not_included' => $this->responses->error('المرفقات غير متاحة في هذه الفئة', 403),
            'unsupported' => $this->responses->error(
                'صيغة الملف غير مدعومة',
                422,
                null,
                ['code' => 'project_attachment_type_unsupported']
            ),
            'limit_reached' => $this->responses->error(
                "لديك ملفات مرفوعة لم ترسلها بعد\nأرسلها أولًا ثم حاول مرة أخرى",
                422,
                null,
                ['code' => 'project_attachment_staging_limit_reached']
            ),
            'identity_conflict' => $this->responses->error(
                "تغيّر الملف أثناء الإرسال\nاختره مرة أخرى",
                409,
                null,
                ['code' => 'project_attachment_upload_conflict']
            ),
            default => $this->responses->success([
                'id' => (string) $result['attachment']->public_id,
                'name' => (string) $result['attachment']->original_file_name,
                'mime_type' => (string) $result['attachment']->mime_type,
                'size_bytes' => (int) $result['attachment']->size_bytes,
            ], 'تم رفع الملف'),
        };
    }

    /**
     * Short-lived download used by the device viewer. The signature binds the
     * learner id and attachment id, so the viewer does not need to forward the
     * app bearer token to a second process.
     */
    public function downloadInputAttachment(Request $request, AiInputAttachment $attachment): Response
    {
        $signedUserId = (int) $request->query('user');
        if (
            $signedUserId <= 0
            || $signedUserId !== (int) $attachment->user_id
            || ! User::query()->whereKey($signedUserId)->where('active', true)->exists()
        ) {
            abort(404);
        }

        $file = $this->downloads->attachment($attachment, $signedUserId);

        return $file ? $this->downloadResponse($file) : abort(404);
    }

    /** Refresh short-lived artifact metadata without exposing storage paths. */
    public function showInputAttachment(AiInputAttachment $attachment): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user || ! $this->downloads->belongsTo($attachment, (int) $user->id)) {
            abort(404);
        }
        $expiresAt = now()->addMinutes(30);

        return $this->responses->success([
            'id' => (string) $attachment->public_id,
            'name' => (string) $attachment->original_file_name,
            'mime_type' => (string) $attachment->mime_type,
            'size_bytes' => (int) $attachment->size_bytes,
            'download_url' => URL::temporarySignedRoute(
                'api.project-input-attachments.download',
                $expiresAt,
                ['attachment' => $attachment->public_id, 'user' => $attachment->user_id]
            ),
            'download_url_expires_at' => $expiresAt->toIso8601String(),
        ], 'تم تجهيز الملف');
    }

    public function retryEvaluation(ProjectSubmission $submission): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user || (int) $submission->user_id !== (int) $user->id) {
            return $this->responses->error('التسليم غير متاح', 404);
        }

        try {
            $submission = $this->evaluations->retryEvaluation($submission, $user);
        } catch (ValidationException) {
            // A concurrent retry can already own this submission. Return its
            // current state rather than making the client upload it again.
            $submission->refresh();
        }
        $payload = $this->submissions->present($submission);

        return $payload['submission_status'] === 'review_unavailable'
            ? $this->responses->error(
                "لم تكتمل المراجعة\nتسليمك محفوظ",
                409,
                $payload,
                ['code' => 'project_review_retry_unavailable']
            )
            : $this->responses->success($payload, 'تم تحديث حالة المراجعة', 202);
    }

    public function retryInitialReport(ProjectSubmission $submission): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user || (int) $submission->user_id !== (int) $user->id) {
            return $this->responses->error('التسليم غير متاح', 404);
        }

        $retry = $this->reportRetries->request($submission, $user);
        if ($retry['state'] === 'unavailable') {
            return $this->responses->error(
                'لا يمكن إعادة التقرير لهذه المحاولة',
                409,
                $this->submissions->present($submission),
                ['code' => 'project_report_retry_unavailable']
            );
        }

        if ($retry['state'] !== 'queued') {
            return $this->responses->error(
                $retry['state'] === 'not_terminal'
                    ? 'التقرير قيد التحديث بالفعل'
                    : 'تعذّرت إعادة التقرير بأمان',
                409,
                $this->submissions->present($retry['submission']),
                ['code' => 'project_report_retry_'.$retry['state']]
            );
        }

        return $this->responses->success(
            $this->submissions->present($retry['submission']),
            'بدأ تحديث التقرير',
            202
        );
    }

    private function revisionChangedResponse(Project $project, bool $submissionAdmissionClosed = false): ?JsonResponse
    {
        $contract = $this->revisionResolver->projectRevisionChange($project);
        if ($contract === null) {
            return null;
        }
        if ($submissionAdmissionClosed) {
            // A fresh old-project admission now shares the publish lock.
            // Clients must still recover the exact original receipt first.
            $contract['submission_admission_closed'] = true;
        }

        return $this->responses->error(
            "تم تحديث الكورس\nنعيد تحميل أحدث نسخة",
            409,
            $contract,
            ['code' => 'course_revision_changed']
        );
    }

    /** @param array{disk:FilesystemAdapter,path:string,name:string,mime:string} $file */
    private function downloadResponse(array $file): Response
    {
        return $file['disk']->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => DownloadFilename::disposition($file['name']),
        ]);
    }
}
