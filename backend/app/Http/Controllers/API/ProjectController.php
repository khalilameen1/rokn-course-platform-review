<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\SendProjectFeedbackMessageRequest;
use App\Http\Requests\API\SubmitProjectRequest;
use App\Http\Requests\API\UploadProjectFeedbackAttachmentRequest;
use App\Models\AiInputAttachment;
use App\Models\Project;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CourseChatAccessService;
use App\Services\CourseCompletionService;
use App\Services\CourseRevisionLearnerReadService;
use App\Services\CourseStagedAuthoringService;
use App\Services\ProjectAttachmentDownloadService;
use App\Services\ProjectFeedbackThreadService;
use App\Services\ProjectReportRetryService;
use App\Services\ProjectSubmissionOrchestrator;
use App\Services\ProjectSubmissionPresenter;
use App\Services\ProjectSubmissionService;
use App\Support\DownloadFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final class ProjectController extends Controller
{
    public function __construct(
        private ProjectSubmissionService $submissionService,
        private ProjectSubmissionPresenter $submissions,
        private CourseCompletionService $courseCompletion,
        private ProjectFeedbackThreadService $feedbackThreads,
        private CourseChatAccessService $courseAccess,
        private CourseAccessPlanService $accessPlans,
        private CourseStagedAuthoringService $stagedAuthoring,
        private CourseRevisionLearnerReadService $revisionReads,
        private ProjectAttachmentDownloadService $downloads,
        private ProjectReportRetryService $reportRetries,
        private ProjectSubmissionOrchestrator $submissionOrchestrator
    ) {
    }

    public function show($projectId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return $this->error('سجّل الدخول أولًا', 401);
            }

            $project = Project::with(['section.course', 'section.module'])->findOrFail($projectId);
            if ($revisionChanged = $this->revisionChangedResponse($project)) return $revisionChanged;
            $courseId = (int) optional($project->section)->course_id;
            if (
                !$courseId
                || !$project->section
                || !$this->courseCompletion->canAccessSection($user, $project->section)
            ) {
                return $this->error('هذا المشروع غير متاح لحسابك', 403);
            }

            $latestSubmission = $this->revisionReads
                ->projectSubmissions((int) $user->id, [(int) $project->id])
                ->get((int) $project->id);
            if ($latestSubmission) {
                $latestSubmission = $this->submissionService->finalizeIfDue($latestSubmission);
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
            $submissionMimeTypes = $this->submissionOrchestrator->allowedMimeTypes($project);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل المشروع',
                'data' => [
                    'id' => $project->id,
                    'requirements_text' => $project->requirements_text,
                    // Prompt/model settings deliberately stay server-side.
                    'submission_text_enabled' => (bool) $project->submission_text_enabled,
                    'submission_files_enabled' => $submissionMimeTypes !== [],
                    'submission_max_files' => max(1, min(5, (int) ($project->submission_max_files ?: 3))),
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
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->error('المشروع غير متاح', 404);
        } catch (\Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);
            return $this->error('تعذّر تحميل المشروع', 500);
        }
    }

    /**
     * Secure submission endpoint. Only the attempt is accepted from the client;
     * score and pass/fail are exclusively server decisions.
     */
    public function submit(SubmitProjectRequest $request, $projectId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return $this->error('سجّل الدخول أولًا', 401);
            }

            $request->validated();

            $project = Project::with('section')->findOrFail($projectId);
            $project->loadMissing('section.course');
            if ($revisionChanged = $this->revisionChangedResponse($project)) return $revisionChanged;
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
                (array) $request->input('metadata', [])
            );
            if ($result['state'] === 'invalid') {
                return $this->projectValidationError($result['field'], $result['message']);
            }
            if ($result['state'] === 'forbidden') {
                return $this->error('لا يمكنك تسليم هذا المشروع من حسابك', 403);
            }
            if ($result['state'] === 'prerequisites') {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'code' => 'project_prerequisites_incomplete',
                    'message' => "أكمل المحتوى السابق أولًا\nثم سلّم المشروع",
                    'data' => null,
                ], 409);
            }
            if ($result['state'] === 'report_note_required') {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'code' => 'project_note_required',
                    'message' => 'اكتب سطرًا واضحًا عما نفذته لنعد تقرير مشروعك',
                    'data' => null,
                ], 422);
            }
            $submission = $result['submission'];

            return response()->json([
                'status' => 202,
                'success' => true,
                'message' => 'استلمنا مشروعك وبدأت مراجعته',
                'data' => $this->submissions->present($submission),
            ], 202);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'message' => 'راجع بيانات المشروع',
                'data' => null,
                'errors' => $exception->errors(),
            ], 422);
        } catch (\UnexpectedValueException $exception) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'submission_idempotency_conflict',
                'message' => "تغيّر محتوى المشروع أثناء الإرسال\nأعد المحاولة",
                'data' => null,
            ], 409);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->error('المشروع غير متاح', 404);
        } catch (\Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);
            return $this->error('تعذّر إرسال المشروع', 500);
        }
    }

    public function submissionStatus(ProjectSubmission $submission): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || $submission->user_id !== $user->id) {
            return $this->error('التسليم غير متاح', 404);
        }

        $submission = $this->submissionService->finalizeIfDue($submission);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل حالة التسليم',
            'data' => $this->submissions->present($submission),
        ]);
    }

    public function feedbackThread(ProjectFeedbackThread $thread): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || (int) $thread->user_id !== (int) $user->id) {
            return $this->error('محادثة المشروع غير متاحة', 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل مراجعة المشروع',
            'data' => $this->feedbackThreads->payload($thread),
        ]);
    }

    public function sendFeedbackMessage(SendProjectFeedbackMessageRequest $request, ProjectFeedbackThread $thread): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || (int) $thread->user_id !== (int) $user->id) {
            return $this->error('محادثة المشروع غير متاحة', 404);
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
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return $this->error('الردود غير متاحة لهذا المشروع', 403);
        } catch (\UnexpectedValueException $exception) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'project_message_idempotency_conflict',
                'message' => "تغيّرت الرسالة أثناء الإرسال\nأعد المحاولة",
                'data' => null,
            ], 409);
        }

        return response()->json([
            'status' => 202,
            'success' => true,
            'message' => 'استلمنا رسالتك',
            'data' => $this->feedbackThreads->payload($thread->fresh()),
        ], 202);
    }

    public function uploadFeedbackAttachment(UploadProjectFeedbackAttachmentRequest $request, ProjectFeedbackThread $thread): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || (int) $thread->user_id !== (int) $user->id) {
            return $this->error('محادثة المشروع غير متاحة', 404);
        }
        $validated = $request->validated();
        $result = $this->feedbackThreads->uploadAttachment(
            $user,
            $thread,
            $validated['attachment'],
            (string) $validated['client_upload_id']
        );
        if ($result['state'] === 'not_included') {
            return $this->error('المرفقات غير متاحة في هذه الفئة', 403);
        }
        if ($result['state'] === 'unsupported') {
            return response()->json([
                'status' => 422, 'success' => false,
                'code' => 'project_attachment_type_unsupported',
                'message' => 'صيغة الملف غير مدعومة',
                'data' => null,
            ], 422);
        }
        if ($result['state'] === 'limit_reached') {
            return response()->json([
                'status' => 422, 'success' => false,
                'code' => 'project_attachment_staging_limit_reached',
                'message' => "لديك ملفات مرفوعة لم ترسلها بعد\nأرسلها أولًا ثم حاول مرة أخرى",
                'data' => null,
            ], 422);
        }
        if ($result['state'] === 'identity_conflict') {
            return response()->json([
                'status' => 409, 'success' => false,
                'code' => 'project_attachment_upload_conflict',
                'message' => "تغيّر الملف أثناء الإرسال\nاختره مرة أخرى",
                'data' => null,
            ], 409);
        }
        $attachment = $result['attachment'];
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم رفع الملف',
            'data' => [
                'id' => (string) $attachment->public_id,
                'name' => (string) $attachment->original_file_name,
                'mime_type' => (string) $attachment->mime_type,
                'size_bytes' => (int) $attachment->size_bytes,
            ],
        ]);
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
            || !User::query()->whereKey($signedUserId)->where('active', true)->exists()
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
        if (!$user || !$this->downloads->belongsTo($attachment, (int) $user->id)) {
            abort(404);
        }
        $expiresAt = now()->addMinutes(30);
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تجهيز الملف',
            'data' => [
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
            ],
        ]);
    }

    public function retryInitialReport(ProjectSubmission $submission): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || (int) $submission->user_id !== (int) $user->id) {
            return $this->error('التسليم غير متاح', 404);
        }

        $retry = $this->reportRetries->request($submission, $user);
        if ($retry['state'] === 'unavailable') {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'project_report_retry_unavailable',
                'message' => 'لا يمكن إعادة التقرير لهذه المحاولة',
                'data' => $this->submissions->present($submission),
            ], 409);
        }

        if ($retry['state'] !== 'queued') {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'project_report_retry_' . $retry['state'],
                'message' => $retry['state'] === 'not_terminal'
                    ? 'التقرير قيد التحديث بالفعل'
                    : 'تعذّرت إعادة التقرير بأمان',
                'data' => $this->submissions->present($retry['submission']),
            ], 409);
        }

        return response()->json([
            'status' => 202,
            'success' => true,
            'message' => 'بدأ تحديث التقرير',
            'data' => $this->submissions->present($retry['submission']),
        ], 202);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $status);
    }

    private function revisionChangedResponse(Project $project): ?JsonResponse
    {
        $course = $project->section?->course;
        if (!$course || !$course->is_coming_soon) return null;
        $revision = $this->stagedAuthoring->activeArchiveForCourse($course);
        if (!$revision) return null;
        $canonical = $revision->canonicalCourse()->firstOrFail();

        return response()->json([
            'status' => 409,
            'success' => false,
            'code' => 'course_revision_changed',
            'message' => "تم تحديث الكورس\nنعيد تحميل أحدث نسخة",
            'data' => [
                'course_id' => (int) $canonical->id,
                'published_revision' => (int) (
                    $canonical->last_published_authoring_version ?: $canonical->authoring_version
                ),
                'reload_endpoint' => "/api/v1/courses/{$canonical->id}/details",
            ],
        ], 409);
    }

    /** @param array{disk:\Illuminate\Filesystem\FilesystemAdapter,path:string,name:string,mime:string} $file */
    private function downloadResponse(array $file): Response
    {
        return $file['disk']->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => DownloadFilename::disposition($file['name']),
        ]);
    }

    private function projectValidationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'status' => 422, 'success' => false,
            'message' => 'راجع ملفات المشروع',
            'data' => null, 'errors' => [$field => [$message]],
        ], 422);
    }
}
