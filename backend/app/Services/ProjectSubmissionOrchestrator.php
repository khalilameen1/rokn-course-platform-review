<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\UnicodeText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class ProjectSubmissionOrchestrator
{
    public function __construct(
        private ProjectSubmissionService $submissions,
        private CourseCompletionService $courseCompletion,
        private CourseChatAccessService $courseAccess,
        private CourseAccessPlanService $accessPlans,
        private AiInputAttachmentService $attachments
    ) {
    }

    /**
     * @param list<UploadedFile> $files
     * @param array<string, mixed> $metadata
     * @return array{state:string, submission?:ProjectSubmission, field?:string, message?:string}
     */
    public function submit(
        User $user,
        Project $project,
        ?string $text,
        array $files,
        ?string $idempotencyKey,
        array $metadata
    ): array {
        $idempotencyKey = trim((string) $idempotencyKey);
        $replayed = $this->submissions->replayCommittedSubmission(
            $user,
            $project,
            $text,
            $files,
            $idempotencyKey
        );
        if ($replayed) {
            return ['state' => 'submitted', 'submission' => $replayed];
        }

        $learnerText = UnicodeText::clean((string) $text);
        $hasText = $learnerText !== '';
        $maximumFiles = max(1, min(5, (int) ($project->submission_max_files ?: 3)));
        if ($hasText && !(bool) $project->submission_text_enabled) {
            return $this->invalid('submission_text', 'هذا المشروع يحتاج ملفًا من الأنواع المحددة');
        }
        if (count($files) > $maximumFiles) {
            return $this->invalid('submission_files', "اختر {$maximumFiles} ملفات على الأكثر");
        }
        if (!$hasText && $files === []) {
            return $this->invalid('submission_files', 'أضف نصًا أو ملفًا واحدًا على الأقل');
        }

        $allowedMimeTypes = $this->allowedMimeTypes($project);
        $maximumFileBytes = self::maximumFileBytes();
        foreach ($files as $file) {
            if ((int) $file->getSize() > $maximumFileBytes) {
                return $this->invalid(
                    'submission_files',
                    'اختر ملفات بحجم '.self::maximumFileMegabytesLabel().' ميجابايت أو أقل'
                );
            }
            $canonicalMime = $this->attachments->canonicalMime($file);
            if ($canonicalMime === null || !in_array($canonicalMime, $allowedMimeTypes, true)) {
                return $this->invalid('submission_files', 'أحد الملفات بصيغة غير متاحة لهذا المشروع');
            }
        }

        $courseId = (int) $project->section?->course_id;
        if (!$courseId || !$project->section || !$this->courseAccess->hasLearningAccess((int) $user->id, $courseId)) {
            return ['state' => 'forbidden'];
        }
        if (!$this->courseCompletion->canAccessSection($user, $project->section)) {
            return ['state' => 'prerequisites'];
        }

        $enrollment = $this->courseAccess->activeProjectEnrollmentFor((int) $user->id, $courseId);
        $terms = $enrollment ? $this->accessPlans->termsForEnrollment($enrollment) : null;
        $feedbackContract = $this->accessPlans->publicPayloadFromTerms($terms ?? []);
        $reportEnabled = $enrollment
            && $this->courseAccess->enrollmentAllowsVariableCostFeatures($enrollment)
            && (bool) $feedbackContract['project_report_enabled'];

        if ($reportEnabled && $files === [] && mb_strlen($learnerText) < 10) {
            return ['state' => 'report_note_required'];
        }
        // The platform-funded progression review is independent of report quota.
        // GenerateProjectFeedback reserves that quota before any report call.

        return [
            'state' => 'submitted',
            'submission' => $this->submissions->submit(
                $user,
                $project,
                $text,
                $files,
                $idempotencyKey ?: (string) Str::uuid(),
                $metadata
            ),
        ];
    }

    /** @return list<string> */
    public function allowedMimeTypes(Project $project): array
    {
        if ($project->submission_allowed_mime_types === null) {
            return $this->attachments->allowedMimeTypes();
        }
        $configured = array_values(array_intersect(
            array_map('strtolower', (array) $project->submission_allowed_mime_types),
            $this->attachments->allowedMimeTypes()
        ));

        return $configured;
    }

    public static function maximumFileBytes(): int
    {
        return max(1024, min(
            max(1, (int) config('projects.maximum_file_kilobytes', 25600)) * 1024,
            max(1024, (int) config('openrouter.attachment_provider_max_bytes', 8388608))
        ));
    }

    public static function maximumFileKilobytes(): int
    {
        return max(1, (int) floor(self::maximumFileBytes() / 1024));
    }

    public static function maximumFileMegabytesLabel(): string
    {
        return rtrim(rtrim(number_format(self::maximumFileBytes() / 1048576, 2, '.', ''), '0'), '.');
    }

    /** @return array{state:string, field:string, message:string} */
    private function invalid(string $field, string $message): array
    {
        return ['state' => 'invalid', 'field' => $field, 'message' => $message];
    }
}
