<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiInputAttachment;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\UploadedFile;

final class CourseChatRequestService
{
    public function __construct(
        private CourseAccessPlanService $accessPlans,
        private AiInputAttachmentService $attachments
    ) {
    }

    /** @return array{state:string, attachment?:AiInputAttachment} */
    public function uploadAttachment(
        User $user,
        Course $course,
        CourseChatAccessService $access,
        UploadedFile $file,
        string $clientUploadId
    ): array {
        if (!$course->isPublishedForLearning()
            || !$access->hasLearningAccess((int) $user->id, (int) $course->id)
            || !$access->hasChatAccess((int) $user->id, (int) $course->id)) {
            return ['state' => 'not_found'];
        }

        $enrollment = $access->activeChatEnrollmentFor((int) $user->id, (int) $course->id);
        $terms = $enrollment ? $this->accessPlans->termsForEnrollment($enrollment) : null;
        $contract = $this->attachmentContract($terms);
        if (!$contract['enabled']) {
            return ['state' => 'not_included'];
        }

        try {
            $attachment = $this->attachments->store(
                $user,
                $course,
                $file,
                AiInputAttachment::PURPOSE_COURSE_CHAT,
                $clientUploadId
            );
        } catch (\UnexpectedValueException $exception) {
            return ['state' => $exception->getMessage() === 'Unsupported AI attachment type.'
                ? 'unsupported'
                : 'identity_conflict'];
        }

        return ['state' => 'uploaded', 'attachment' => $attachment];
    }

    /** @return array{enabled:bool,max_files:int} */
    public function attachmentContract(?array $terms): array
    {
        $payload = $this->accessPlans->publicPayloadFromTerms($terms ?? []);
        $maximum = max(0, (int) ($payload['chat_attachment_max_files'] ?? 0));

        return [
            'enabled' => (bool) ($payload['chat_attachments_enabled'] ?? false) && $maximum > 0,
            'max_files' => min(5, $maximum),
        ];
    }
}
