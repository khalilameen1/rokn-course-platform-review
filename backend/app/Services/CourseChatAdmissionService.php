<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiInputAttachment;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseChatTurn;
use App\Models\CourseEnrollment;
use App\Models\User;

final class CourseChatAdmissionService
{
    public function __construct(
        private CourseChatTurnService $turns,
        private AiInputAttachmentService $attachments,
        private PaidAiCallExecutionService $paidCalls,
        private AiEntitlementBudgetService $budget
    ) {
    }

    public function admit(
        User $user,
        Course $course,
        CourseEnrollment $enrollment,
        ?int $lessonId,
        string $clientRequestId,
        string $question,
        string $language,
        string $promptVersion,
        array $attachmentIds,
        array $requestContext
    ): CourseChatAdmissionResult {
        try {
            $turn = $this->turns->begin(
                (int) $user->id,
                (int) $course->id,
                (int) $enrollment->id,
                $lessonId,
                $clientRequestId,
                $question,
                $language,
                $promptVersion,
                $attachmentIds
            );
        } catch (\UnexpectedValueException) {
            return new CourseChatAdmissionResult('identity_conflict', null);
        }

        try {
            $claimed = $this->attachments->claim(
                $user,
                $course,
                $attachmentIds,
                AiInputAttachment::PURPOSE_COURSE_CHAT,
                AiInputAttachment::OWNER_COURSE_CHAT_TURN,
                (int) $turn->id
            );
        } catch (\UnexpectedValueException) {
            $failed = $this->turns->failBeforeDispatch($turn, 'chat_attachment_claim_failed');
            return new CourseChatAdmissionResult(
                $failed ? 'identity_conflict' : 'in_progress',
                $turn->fresh()
            );
        }

        if ($turn->status === CourseChatTurn::COMPLETED) {
            $presented = AiUsageEvent::query()
                ->where('request_id', $clientRequestId)
                ->where('user_id', $user->id)
                ->where('enrollment_id', $enrollment->id)
                ->where('feature', 'course_chat')
                ->where('status', 'completed')
                ->first();
            if ($presented && (
                trim((string) data_get($presented->metadata, 'accepted_response', '')) !== ''
                || $this->paidCalls->landedResult($presented) !== null
            )) {
                $this->paidCalls->markPresented($presented);
            }

            return new CourseChatAdmissionResult('completed', $turn, $claimed, (string) $turn->answer);
        }
        if (in_array($turn->status, [CourseChatTurn::FAILED, CourseChatTurn::CANCELLED], true)) {
            return new CourseChatAdmissionResult('terminal', $turn, $claimed);
        }

        $prior = AiUsageEvent::query()->where('request_id', $clientRequestId)->first();
        if (!$prior) {
            return new CourseChatAdmissionResult('new', $turn, $claimed);
        }
        if ((int) $prior->user_id !== (int) $user->id
            || (int) $prior->enrollment_id !== (int) $enrollment->id
            || (string) $prior->feature !== 'course_chat') {
            $this->turns->failBeforeDispatch($turn, 'chat_usage_identity_mismatch');
            return new CourseChatAdmissionResult('identity_conflict', $turn, $claimed);
        }

        $metadata = is_array($prior->metadata) ? $prior->metadata : [];
        $priorContext = is_array($metadata['request_context'] ?? null) ? $metadata['request_context'] : [];
        $sameRequest = ($priorContext['question_hash'] ?? null) === ($requestContext['question_hash'] ?? null)
            && (int) ($priorContext['lesson_id'] ?? 0) === (int) ($requestContext['lesson_id'] ?? 0)
            && ($priorContext['language'] ?? null) === ($requestContext['language'] ?? null)
            && ($priorContext['prompt_version'] ?? null) === ($requestContext['prompt_version'] ?? null);
        if (!$sameRequest && !($prior->status === 'reserved' && $priorContext === [])) {
            $this->turns->failBeforeDispatch($turn, 'chat_usage_identity_mismatch');
            return new CourseChatAdmissionResult('identity_conflict', $turn, $claimed);
        }

        $accepted = trim((string) ($metadata['accepted_response'] ?? ''));
        if ($prior->status === 'completed' && $accepted !== '') {
            if ($this->turns->complete($turn, $accepted, $prior)) {
                $this->paidCalls->markPresented($prior->fresh());

                return new CourseChatAdmissionResult(
                    'completed',
                    $turn->fresh(),
                    $claimed,
                    $accepted
                );
            }

            return new CourseChatAdmissionResult('terminal', $turn->fresh(), $claimed);
        }
        if ($prior->status !== 'reserved') {
            // The usage ledger may become terminal a few milliseconds before
            // the presentation turn. Repair that one durable turn here so an
            // idempotent send cannot flatten an unknown provider outcome into
            // a generic retryable failure and accidentally permit a new call.
            $terminal = $this->turns->reconcileTerminalUsage($turn) ?? $turn;
            if ($terminal->status === CourseChatTurn::COMPLETED) {
                return new CourseChatAdmissionResult(
                    'completed',
                    $terminal,
                    $claimed,
                    (string) $terminal->answer
                );
            }

            return new CourseChatAdmissionResult('terminal', $terminal, $claimed);
        }
        if (!$prior->reservation_expires_at || !$prior->reservation_expires_at->isPast()) {
            return new CourseChatAdmissionResult('streaming', $turn, $claimed);
        }
        if ($this->paidCalls->landedResult($prior) !== null) {
            return new CourseChatAdmissionResult('streaming', $turn, $claimed);
        }

        $providerState = $this->paidCalls->startedState($prior);
        if ($providerState === PaidAiCallExecutionService::LIVE) {
            return new CourseChatAdmissionResult('streaming', $turn, $claimed);
        }
        if ($providerState === PaidAiCallExecutionService::STALE_STARTED) {
            $this->paidCalls->settleUnknown($this->budget, $prior, $requestContext);
            $this->turns->fail($turn, 'chat_provider_outcome_unknown');
        } else {
            $this->budget->release($prior, 'expired_course_chat_request');
            $this->turns->fail($turn, 'chat_request_interrupted');
        }

        return new CourseChatAdmissionResult('terminal', $turn->fresh(), $claimed);
    }
}
