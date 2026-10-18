<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\AiPlanLimitReachedException;
use App\Exceptions\AiProviderExposureLimitReachedException;
use App\Exceptions\AiProviderUnavailableException;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateCourseChatReply;
use App\Models\AiInputAttachment;
use App\Models\Course;
use App\Models\CourseChatTurn;
use App\Services\AiEntitlementBudgetService;
use App\Services\AiFailurePolicy;
use App\Services\AiInputAttachmentService;
use App\Services\ApiResponseService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseChatAdmissionService;
use App\Services\CourseChatPromptContextService;
use App\Services\CourseChatRequestService;
use App\Services\CourseChatTurnService;
use App\Services\CourseEntitlementService;
use App\Support\AiRequestTokenEstimate;
use App\Support\DurableJobDispatch;
use App\Support\RoknLocale;
use App\Support\UnicodeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class CourseChatController extends Controller
{
    public function __construct(
        private readonly CourseAccessPlanService $accessPlans,
        private readonly AiEntitlementBudgetService $entitlementBudget,
        private readonly CourseChatTurnService $turns,
        private readonly AiInputAttachmentService $attachments,
        private readonly CourseChatRequestService $requests,
        private readonly CourseChatAdmissionService $admission,
        private readonly CourseChatPromptContextService $promptContext,
        private readonly AiFailurePolicy $failurePolicy,
        private readonly ApiResponseService $responses
    ) {}

    public function sendForCourse(
        Request $request,
        Course $course,
        CourseEntitlementService $access
    ): JsonResponse {
        return $this->send($request, $course, $access);
    }

    public function uploadAttachment(Request $request, Course $course, CourseEntitlementService $access): JsonResponse
    {
        $validated = $request->validate([
            'client_upload_id' => 'required|uuid',
            'attachment' => [
                'required', 'file',
                'max:'.min(
                    (int) config('projects.maximum_file_kilobytes', 25600),
                    (int) floor((int) config('openrouter.attachment_provider_max_bytes', 8388608) / 1024)
                ),
                'mimetypes:'.implode(',', [
                    ...$this->attachments->allowedMimeTypes(),
                    'application/zip',
                    'application/x-zip-compressed',
                    'application/octet-stream',
                ]),
            ],
        ]);
        $result = $this->requests->uploadAttachment(
            auth('api')->user(),
            $course,
            $access,
            $validated['attachment'],
            (string) $validated['client_upload_id']
        );
        if ($result['state'] === 'not_found') {
            abort(404);
        }
        if ($result['state'] === 'not_included') {
            return $this->responses->error('المرفقات غير متاحة في فئتك الحالية', 403, null, ['code' => 'chat_attachments_not_included']);
        }
        if ($result['state'] === 'unsupported') {
            return $this->responses->error('صيغة الملف غير مدعومة', 422, null, ['code' => 'attachment_type_unsupported']);
        }
        if ($result['state'] === 'identity_conflict') {
            return $this->responses->error('تعذر استكمال رفع هذا الملف', 409, null, ['code' => 'attachment_identity_conflict']);
        }
        $attachment = $result['attachment'];

        return $this->responses->success([
            'id' => (string) $attachment->public_id,
            'name' => (string) $attachment->original_file_name,
            'mime_type' => (string) $attachment->mime_type,
            'size_bytes' => (int) $attachment->size_bytes,
        ], 'تم رفع الملف');
    }

    public function history(Request $request, CourseEntitlementService $access): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'lesson_id' => 'nullable|integer|exists:lessons,id',
            'per_page' => 'nullable|integer|min:1|max:50',
            'cursor' => 'nullable|string|max:500',
        ]);
        $user = auth('api')->user();
        $course = Course::query()->findOrFail((int) $validated['course_id']);
        if (! $course->isPublishedForLearning() || ! $access->hasLearningAccess($user->id, $course->id)) {
            return $this->responses->error('المحادثة غير متاحة', 404);
        }

        $lessonId = null;
        if (isset($validated['lesson_id'])) {
            $lesson = $this->promptContext->currentLesson((int) $validated['lesson_id'], $course);
            if (! $lesson) {
                return $this->responses->error('المقطع لا ينتمي إلى هذا الكورس', 422);
            }
            $lessonId = (int) $lesson->id;
        }

        $page = $this->turns->page(
            (int) $user->id,
            (int) $course->id,
            $lessonId,
            (int) ($validated['per_page'] ?? 20)
        );
        $turnIds = collect($page->items())->pluck('id')->all();
        $attachmentsByTurn = AiInputAttachment::query()
            ->where('owner_type', AiInputAttachment::OWNER_COURSE_CHAT_TURN)
            ->whereIn('owner_id', $turnIds)
            ->where('status', AiInputAttachment::READY)
            ->orderBy('id')
            ->get(['owner_id', 'public_id', 'original_file_name', 'mime_type', 'size_bytes'])
            ->groupBy('owner_id');
        $messages = collect($page->items())
            ->reverse()
            ->flatMap(function (CourseChatTurn $turn) use ($attachmentsByTurn): array {
                $failure = $turn->status === CourseChatTurn::FAILED
                    ? $this->failurePolicy->describe((string) $turn->error_code)
                    : null;
                $failedAnswer = $turn->status === CourseChatTurn::FAILED
                    ? $this->failedAnswerText($turn)
                    : null;
                $assistantText = in_array($turn->status, [
                    CourseChatTurn::STREAMING,
                    CourseChatTurn::COMPLETED,
                ], true)
                    ? trim((string) $turn->answer)
                    : $failedAnswer;
                $turnAttachments = $attachmentsByTurn->get($turn->id, collect())
                    ->map(function (AiInputAttachment $attachment): array {
                        $expiresAt = now()->addMinutes(30);

                        return [
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
                        ];
                    })->values()->all();

                return [[
                    'id' => 'user-'.$turn->public_id,
                    'role' => 'user',
                    'text' => (string) $turn->question,
                    'client_request_id' => (string) $turn->client_request_id,
                    'delivery_status' => in_array($turn->status, [
                        CourseChatTurn::QUEUED,
                        CourseChatTurn::STREAMING,
                    ], true) ? 'sent' : $turn->status,
                    'created_at' => $turn->created_at?->toIso8601String(),
                    'context_eligible' => $turn->status === CourseChatTurn::COMPLETED,
                    'attachments' => $turnAttachments,
                ], [
                    'id' => 'assistant-'.$turn->public_id,
                    'role' => 'assistant',
                    'text' => $assistantText,
                    'client_request_id' => (string) $turn->client_request_id,
                    'delivery_status' => (string) $turn->status,
                    'error_code' => $turn->status === CourseChatTurn::FAILED
                        ? ((string) $turn->error_code ?: 'chat_turn_failed')
                        : null,
                    'failure_category' => $failure['category'] ?? null,
                    'can_retry' => $failure['can_retry'] ?? null,
                    'retry_after_seconds' => $failure['retry_after_seconds'] ?? null,
                    'partial' => $failedAnswer !== null,
                    'created_at' => ($turn->completed_at ?? $turn->updated_at)?->toIso8601String(),
                    'context_eligible' => $turn->status === CourseChatTurn::COMPLETED,
                ]];
            })
            ->values();

        return $this->responses->success([
            'messages' => $messages,
            'next_cursor' => $page->nextCursor()?->encode(),
        ], 'تم تحميل المحادثة');
    }

    /**
     * Lightweight polling path for a turn already admitted to the AI queue.
     * A pure read (RFC 9110 safe method): stuck turns are recovered by the
     * scheduled ai:recover-chat-turns command, not by the poll.
     */
    public function status(string $clientRequestId): JsonResponse
    {
        if (! Str::isUuid($clientRequestId)) {
            abort(404);
        }

        $turn = CourseChatTurn::query()
            ->where('user_id', auth('api')->id())
            ->where('client_request_id', $clientRequestId)
            ->first();
        if (! $turn) {
            abort(404);
        }

        if ($turn->status === CourseChatTurn::COMPLETED) {
            return $this->responses->success([
                'message' => (string) $turn->answer,
                'unavailable' => false,
                'can_retry' => false,
                'retry_after_seconds' => 0,
                'client_request_id' => (string) $turn->client_request_id,
                'turn_status' => CourseChatTurn::COMPLETED,
            ], 'تم استلام الرد');
        }

        if (in_array($turn->status, [CourseChatTurn::QUEUED, CourseChatTurn::STREAMING], true)) {
            $partial = $turn->status === CourseChatTurn::STREAMING
                ? trim((string) $turn->answer)
                : '';

            return $this->responses->success([
                'message' => $partial !== ''
                    ? $partial
                    : "نجهز إجابتك الآن\nستظهر خلال لحظات",
                'partial' => $partial !== '',
                'unavailable' => false,
                'can_retry' => true,
                'retry_after_seconds' => $partial !== '' ? 1 : 2,
                'poll_window_seconds' => $this->pollWindowSeconds(),
                'client_request_id' => (string) $turn->client_request_id,
                'turn_status' => (string) $turn->status,
            ], 'نجهز إجابتك الآن', 200, ['code' => 'chat_answer_in_progress']);
        }

        if ($turn->status === CourseChatTurn::CANCELLED) {
            return $this->cancelledResponse($turn);
        }

        $failureCode = (string) ($turn->error_code ?: 'chat_turn_failed');
        $failure = $this->failurePolicy->describe($failureCode);
        $failedAnswer = $this->failedAnswerText($turn);

        return $this->responses->success([
            'message' => $failedAnswer ?? ($failure['can_retry']
                ? "لم تكتمل الإجابة السابقة\nأرسل السؤال مرة أخرى"
                : 'تعذّر تأكيد نتيجة الإجابة السابقة'),
            'partial' => $failedAnswer !== null,
            'unavailable' => true,
            'failure_category' => $failure['category'],
            'can_retry' => $failure['can_retry'],
            'retry_after_seconds' => $failure['retry_after_seconds'],
            'client_request_id' => (string) $turn->client_request_id,
            'turn_status' => (string) $turn->status,
        ], 'لم تكتمل الإجابة', 200, ['code' => $failureCode]);
    }

    /** Preserve a safe streamed checkpoint without presenting it as complete. */
    private function failedAnswerText(CourseChatTurn $turn): ?string
    {
        $partial = trim((string) $turn->answer);

        return $partial !== ''
            ? $partial."\n\nتوقف الرد قبل أن يكتمل"
            : null;
    }

    public function cancel(string $clientRequestId): JsonResponse
    {
        if (! Str::isUuid($clientRequestId)) {
            abort(404);
        }
        $state = $this->turns->cancelForUser((int) auth('api')->id(), $clientRequestId);
        if ($state === 'not_found') {
            abort(404);
        }
        $cancelled = $state === 'cancelled';
        $code = $cancelled
            ? 'chat_turn_cancelled'
            : match ($state) {
                'not_cancellable' => 'chat_turn_completed',
                'terminal' => 'chat_turn_closed',
                default => 'provider_call_in_progress',
            };

        if ($cancelled) {
            return $this->responses->success(null, 'تم الإيقاف', 200, ['code' => $code]);
        }

        return $this->responses->error(match ($state) {
            'not_cancellable' => 'اكتمل الرد بالفعل',
            'terminal' => 'انتهت المحاولة بالفعل',
            default => 'بدأ تجهيز الرد بالفعل',
        }, 409, null, ['code' => $code]);
    }

    private function send(
        Request $request,
        Course $course,
        CourseEntitlementService $access
    ): JsonResponse {
        $validated = $request->validate([
            'message' => 'nullable|string|max:16000|required_without:attachment_ids',
            'attachment_ids' => 'nullable|array|max:5|required_without:message',
            'attachment_ids.*' => 'required|uuid|distinct',
            'client_request_id' => 'nullable|uuid',
            'lesson_id' => 'nullable|integer|exists:lessons,id',
        ]);

        $user = auth('api')->user();
        if (! $course->isPublishedForLearning()) {
            return $this->responses->error('هذا الكورس غير متاح الآن', 404, null, ['code' => 'course_not_available']);
        }
        if (! $access->hasLearningAccess($user->id, $course->id)) {
            return $this->responses->error('افتح الكورس أولًا لإرسال سؤالك', 403, null, ['code' => 'course_access_required']);
        }
        $chatEnrollment = $access->activeChatEnrollmentFor(
            (int) $user->id,
            (int) $course->id
        );
        if (! $chatEnrollment) {
            return $this->responses->error("اشتراكك لا يشمل الشات\nقم بترقية اشتراكك وادفع فرق السعر فقط", 403, null, ['code' => 'chat_upgrade_required']);
        }

        $attachmentIds = array_values($validated['attachment_ids'] ?? []);
        sort($attachmentIds);
        $question = UnicodeText::clean((string) ($validated['message'] ?? ''));
        if ($question === '' && $attachmentIds !== []) {
            $question = 'راجع المرفق';
        }
        if ($question === '') {
            return $this->responses->error('اكتب سؤالك الأول', 422, null, ['code' => 'empty_chat_message']);
        }
        if (UnicodeText::graphemeLength($question) > 1600) {
            return $this->responses->error('الرسالة أطول من الحد المتاح', 422, null, ['code' => 'chat_message_too_long']);
        }

        $clientRequestId = (string) ($validated['client_request_id'] ?? Str::uuid());
        $language = RoknLocale::normalize($user->preferred_locale)
            ?? RoknLocale::normalize(app()->getLocale())
            ?? RoknLocale::ARABIC;
        $promptVersion = $this->promptContext->version($course);
        $questionHash = hash('sha256', $question.'|'.implode('|', $attachmentIds));

        // Never let a client-authored display label become privileged model
        // context. The lesson relation below is the only trusted source.
        $currentStepTitle = '';
        $currentStepDescription = '';
        if (! empty($validated['lesson_id'])) {
            $lesson = $this->promptContext->currentLesson((int) $validated['lesson_id'], $course);
            if (! $lesson) {
                return $this->responses->error('المقطع المختار لا ينتمي إلى هذا الكورس', 422, null, ['code' => 'lesson_course_mismatch']);
            }
            $currentStepTitle = (string) $lesson->title;
            $currentStepDescription = UnicodeText::limit(
                UnicodeText::clean((string) $lesson->description, false),
                500
            );
        }

        $requestContext = [
            'course_id' => (int) $course->id,
            'question_hash' => $questionHash,
            'lesson_id' => isset($lesson) ? (int) $lesson->id : null,
            'language' => $language,
            'prompt_version' => $promptVersion,
            'attachment_count' => count($attachmentIds),
        ];
        $enrollment = $chatEnrollment;
        $planTerms = $enrollment
            ? $this->accessPlans->termsForEnrollment($enrollment)
            : null;
        if (! $enrollment || ! $planTerms) {
            return $this->responses->error("اشتراكك لا يشمل الشات\nقم بترقية اشتراكك وادفع فرق السعر فقط", 403, null, ['code' => 'chat_upgrade_required']);
        }
        $attachmentContract = $this->requests->attachmentContract($planTerms);
        if ($attachmentIds !== [] && (
            ! $attachmentContract['enabled']
            || count($attachmentIds) > $attachmentContract['max_files']
        )) {
            return $this->responses->error('المرفقات غير متاحة في فئتك الحالية', 403, null, ['code' => 'chat_attachments_not_included']);
        }
        $admission = $this->admission->admit(
            $user, $course, $enrollment, isset($lesson) ? (int) $lesson->id : null,
            $clientRequestId, $question, $language, $promptVersion, $attachmentIds, $requestContext
        );
        $turn = $admission->turn;
        $claimedAttachments = $admission->attachments;
        if ($admission->state === 'completed') {
            return $this->completedResponse((string) $admission->answer, $clientRequestId);
        }
        if ($admission->state === 'terminal') {
            return $this->terminalFailureResponse($turn, $clientRequestId);
        }
        if ($admission->state === 'identity_conflict') {
            return $this->responses->error('تعذر استكمال هذا الطلب', 409, null, [
                'code' => 'chat_request_identity_conflict',
            ]);
        }
        if (in_array($admission->state, ['in_progress', 'streaming'], true)) {
            $turn = $turn?->fresh() ?? $turn;
            if ($turn?->status === CourseChatTurn::COMPLETED) {
                return $this->completedResponse((string) $turn->answer, $clientRequestId);
            }
            if (in_array($turn?->status, [CourseChatTurn::FAILED, CourseChatTurn::CANCELLED], true)) {
                return $this->terminalFailureResponse($turn, $clientRequestId);
            }

            return $this->gracefulUnavailable(
                $turn,
                "نجهز إجابتك الآن\nستظهر خلال لحظات", 3,
                'chat_answer_in_progress', $clientRequestId,
                (string) ($turn?->status ?: CourseChatTurn::QUEUED)
            );
        }
        $messages = [[
            'role' => 'system',
            'content' => $this->promptContext->courseBrief($course),
        ]];
        if ($currentStepTitle !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $this->promptContext->currentLessonPrompt(
                    UnicodeText::limit($currentStepTitle, 160),
                    $currentStepDescription
                ),
            ];
        }
        // History is server-owned and scoped to this user/course/lesson. A
        // client cannot inject another account's transcript or turn a local
        // cache corruption into model context.
        try {
            $history = $turn
                ? $this->turns->context(
                    (int) $user->id,
                    (int) $course->id,
                    isset($lesson) ? (int) $lesson->id : null,
                    $language,
                    $promptVersion,
                    (int) $turn->id,
                    4000
                )
                : [];
            // Provider routing is an operations policy. An immutable purchase
            // receipt may grant chat capacity, but must never carry an old
            // moderator-selected model into a learner request.
            $model = $this->promptContext->model();
        } catch (AiProviderUnavailableException $exception) {
            report($exception);
            $code = $this->failurePolicy->providerCode($exception);
            $closed = $this->turns->failBeforeDispatch($turn, $code);
            if (! $closed) {
                return $this->gracefulUnavailable(
                    $turn,
                    "نجهز إجابتك الآن\nستظهر خلال لحظات",
                    3,
                    'chat_answer_in_progress',
                    $clientRequestId,
                    CourseChatTurn::QUEUED
                );
            }

            return $this->gracefulUnavailable(
                $turn,
                "تعذّر الرد الآن\nحاول لاحقًا",
                0,
                $code,
                $clientRequestId
            );
        } catch (\Throwable $exception) {
            report($exception);
            $closed = $this->turns->failBeforeDispatch(
                $turn,
                'chat_preparation_failed'
            );
            if (! $closed) {
                return $this->gracefulUnavailable(
                    $turn,
                    "نجهز إجابتك الآن\nستظهر خلال لحظات",
                    3,
                    'chat_answer_in_progress',
                    $clientRequestId,
                    'queued'
                );
            }

            return $this->gracefulUnavailable(
                $turn,
                "تعذّر الرد الآن\nحاول لاحقًا",
                45,
                'ai_temporarily_unavailable',
                $clientRequestId
            );
        }
        $messages = array_merge($messages, $history);
        $messages[] = [
            'role' => 'system',
            'content' => $this->promptContext->responseContract(),
        ];
        $messages[] = ['role' => 'user', 'content' => $question];

        $maxTokens = AiRequestTokenEstimate::courseChatOutputLimit($planTerms ?? []);
        $estimatedTokens = AiRequestTokenEstimate::forContents(
            array_map(static fn (array $message): string => (string) ($message['content'] ?? ''), $messages),
            $maxTokens,
            $this->attachments->estimatedInputTokens($claimedAttachments)
        );
        $event = null;
        try {
            $event = $this->entitlementBudget->reserve(
                $enrollment,
                'course_chat',
                $estimatedTokens,
                $model,
                $clientRequestId
            );
            if (! $event) {
                throw new AiPlanLimitReachedException('Chat is not included in this plan.');
            }

            DurableJobDispatch::now(new GenerateCourseChatReply(
                (int) $turn->id,
                (int) $enrollment->id,
                $model,
                $messages,
                (float) config('openrouter.temperature', 0.35),
                $maxTokens,
                $requestContext
            ));
        } catch (AiPlanLimitReachedException) {
            $this->turns->failBeforeDispatch(
                $turn,
                'chat_plan_limit_reached'
            );

            return $this->gracefulUnavailable(
                $turn,
                "استخدمت كل رسائلك\nقم بترقية اشتراكك للحصول على رسائل أكثر وادفع فرق السعر فقط",
                1,
                'chat_plan_limit_reached',
                $clientRequestId
            );
        } catch (AiProviderExposureLimitReachedException) {
            $this->turns->failBeforeDispatch(
                $turn,
                'ai_temporarily_unavailable'
            );

            return $this->gracefulUnavailable(
                $turn,
                "تعذر إرسال السؤال الآن\nحاول بعد قليل",
                30,
                'ai_temporarily_unavailable',
                $clientRequestId
            );
        } catch (AiProviderUnavailableException $exception) {
            report($exception);
            $code = $this->failurePolicy->providerCode($exception);
            if ($event?->status === 'reserved') {
                $this->entitlementBudget->release($event, 'course_chat_dispatch_failed');
            }
            $this->turns->failBeforeDispatch($turn, $code);

            return $this->gracefulUnavailable(
                $turn,
                "تعذّر الرد الآن\nحاول لاحقًا",
                0,
                $code,
                $clientRequestId
            );
        } catch (\Throwable $exception) {
            report($exception);
            if ($event?->status === 'reserved') {
                $this->entitlementBudget->release($event, 'course_chat_dispatch_failed');
            }
            $this->turns->failBeforeDispatch(
                $turn,
                'chat_dispatch_failed'
            );

            return $this->gracefulUnavailable(
                $turn,
                "تعذر إرسال السؤال الآن\nحاول مرة أخرى",
                3,
                'chat_dispatch_failed',
                $clientRequestId
            );
        }

        return $this->gracefulUnavailable(
            $turn,
            "نجهز إجابتك الآن\nستظهر خلال لحظات",
            2,
            'chat_answer_in_progress',
            $clientRequestId,
            CourseChatTurn::QUEUED
        );
    }

    private function completedResponse(
        string $answer,
        string $clientRequestId
    ): JsonResponse {
        return $this->responses->success([
            'message' => $answer,
            'unavailable' => false,
            'can_retry' => false,
            'retry_after_seconds' => 0,
            'client_request_id' => $clientRequestId,
            'turn_status' => 'completed',
        ], 'تم استلام الرد');
    }

    private function gracefulUnavailable(
        ?CourseChatTurn $turn,
        string $message,
        int $retryAfter,
        string $code,
        ?string $clientRequestId = null,
        string $turnStatus = 'failed'
    ): JsonResponse {
        $inProgress = in_array($turnStatus, [
            CourseChatTurn::QUEUED,
            CourseChatTurn::STREAMING,
        ], true);
        $failure = $inProgress
            ? ['category' => 'in_progress', 'can_retry' => true, 'retry_after_seconds' => max(1, $retryAfter)]
            : $this->failurePolicy->describe($code);

        return $this->responses->success([
            'message' => $message,
            'unavailable' => ! $inProgress,
            'failure_category' => $failure['category'],
            'can_retry' => $failure['can_retry'],
            'retry_after_seconds' => $inProgress
                ? max(1, $retryAfter)
                : $failure['retry_after_seconds'],
            'poll_window_seconds' => $inProgress
                ? $this->pollWindowSeconds()
                : null,
            'client_request_id' => $clientRequestId,
            'turn_status' => $turnStatus,
        ], $inProgress ? 'نجهز إجابتك الآن' : 'تعذّر الرد الآن', 200, ['code' => $code]);
    }

    private function terminalFailureResponse(
        ?CourseChatTurn $turn,
        string $clientRequestId
    ): JsonResponse {
        if ($turn?->status === CourseChatTurn::CANCELLED) {
            return $this->cancelledResponse($turn);
        }

        $code = (string) ($turn?->error_code ?: 'chat_turn_failed');
        $failure = $this->failurePolicy->describe($code);

        return $this->gracefulUnavailable(
            $turn,
            $failure['can_retry']
                ? "لم تكتمل الإجابة السابقة\nأرسل السؤال مرة أخرى"
                : 'تعذّر تأكيد نتيجة الإجابة السابقة',
            $failure['retry_after_seconds'],
            $code,
            $clientRequestId,
            'failed'
        );
    }

    private function cancelledResponse(CourseChatTurn $turn): JsonResponse
    {
        return $this->responses->success([
            'message' => 'تم إيقاف الرد',
            'unavailable' => false,
            'can_retry' => false,
            'retry_after_seconds' => 0,
            'client_request_id' => (string) $turn->client_request_id,
            'turn_status' => CourseChatTurn::CANCELLED,
        ], 'تم إيقاف الرد', 200, ['code' => (string) ($turn->error_code ?: 'learner_cancelled')]);
    }

    /** Keep the foreground owner alive until the worker or stale-turn repair can decide. */
    private function pollWindowSeconds(): int
    {
        $queuedWindow = max(30, (int) config('openrouter.queue_stale_seconds', 60)) + 5;
        $providerWindow = max(75, (int) config('openrouter.timeout_seconds', 45) + 45) + 5;

        return min(110, max($queuedWindow, $providerWindow));
    }
}
