<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiEntitlementUsage;
use App\Models\CourseEnrollment;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
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

        $hasText = trim(strip_tags((string) $text)) !== '';
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
        foreach ($files as $file) {
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

        if ($reportEnabled && $files === [] && mb_strlen(trim(strip_tags((string) $text))) < 10) {
            return ['state' => 'report_note_required'];
        }
        if ($reportEnabled) {
            $budgetError = $this->reportBudgetError($project, $enrollment, $terms ?? [], $text, $files);
            if ($budgetError !== null) {
                return $this->invalid('submission_files', $budgetError);
            }
        }

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

    /** @param list<UploadedFile> $files */
    private function reportBudgetError(
        Project $project,
        CourseEnrollment $enrollment,
        array $terms,
        ?string $text,
        array $files
    ): ?string
    {
        $providerMaximum = (int) config('openrouter.attachment_provider_max_bytes', 8388608);
        $attachmentTokens = 0;
        foreach ($files as $file) {
            if ((int) $file->getSize() > $providerMaximum) {
                return 'اختر ملفات أصغر من 8 ميجابايت لنتمكن من مراجعتها';
            }
            try {
                $attachmentTokens += $this->attachments->estimatedUploadedFileTokens($file);
            } catch (\UnexpectedValueException) {
                return "أحد الملفات لا يمكن قراءته للتقرير\nاختر نسخة أخرى";
            }
        }

        $maxOutputTokens = max(80, min((int) config('openrouter.max_tokens', 800), (int) ($terms['max_output_tokens'] ?? 320)));
        $semanticText = trim(strip_tags(implode("\n", [(string) $text, (string) $project->requirements_text])));
        $estimatedRequestTokens = $maxOutputTokens + (int) ceil(strlen($semanticText) / 4) + $attachmentTokens;
        $reportBudget = max(0, (int) ($terms['project_feedback_token_budget'] ?? 0));
        $usage = AiEntitlementUsage::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('feature', AiEntitlementUsage::FEATURE_PROJECT_FEEDBACK)
            ->first(['used_tokens', 'reserved_tokens']);
        $remaining = max(0, $reportBudget - (int) ($usage?->used_tokens ?? 0) - (int) ($usage?->reserved_tokens ?? 0));

        return $remaining <= 0 || $estimatedRequestTokens > $remaining
            ? "الملفات أكبر من مساحة التقرير في فئتك\nاختر ملفات أقل أو صورًا أوضح وأصغر"
            : null;
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

    /** @return array{state:string, field:string, message:string} */
    private function invalid(string $field, string $message): array
    {
        return ['state' => 'invalid', 'field' => $field, 'message' => $message];
    }
}
