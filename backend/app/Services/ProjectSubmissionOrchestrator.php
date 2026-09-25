<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\UnicodeText;
use App\Support\UploadBudget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class ProjectSubmissionOrchestrator
{
    public function __construct(
        private ProjectSubmissionService $submissions,
        private CourseSectionAccessService $sectionAccess,
        private CourseEntitlementService $courseAccess,
        private CourseAccessPlanService $accessPlans,
        private ProjectSubmissionFilePolicy $files
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
        array $metadata,
        ?UploadBudget $uploadBudget = null
    ): array {
        $uploadBudget ??= UploadBudget::start((float) config('projects.submission_request_budget_seconds', 16));
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
        $maximumFiles = ProjectSubmissionFilePolicy::maximumFiles($project);
        if ($hasText && !(bool) $project->submission_text_enabled) {
            return $this->invalid('submission_text', 'هذا المشروع يحتاج ملفًا من الأنواع المحددة');
        }
        if (count($files) > $maximumFiles) {
            return $this->invalid('submission_files', "اختر {$maximumFiles} ملفات على الأكثر");
        }
        if (!$hasText && $files === []) {
            return $this->invalid('submission_files', 'أضف نصًا أو ملفًا واحدًا على الأقل');
        }

        $maximumFileBytes = ProjectSubmissionFilePolicy::maximumFileBytes();
        foreach ($files as $file) {
            if ((int) $file->getSize() > $maximumFileBytes) {
                return $this->invalid(
                    'submission_files',
                    'اختر ملفات بحجم '.ProjectSubmissionFilePolicy::maximumFileMegabytesLabel().' ميجابايت أو أقل'
                );
            }
            if (!$this->files->acceptsType($project, $file)) {
                return $this->invalid('submission_files', 'أحد الملفات بصيغة غير متاحة لهذا المشروع');
            }
        }

        $courseId = (int) $project->section?->course_id;
        if (!$courseId || !$project->section || !$this->courseAccess->hasLearningAccess((int) $user->id, $courseId)) {
            return ['state' => 'forbidden'];
        }
        if (!$this->sectionAccess->canAccessSection($user, $project->section)) {
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
                $metadata,
                $uploadBudget
            ),
        ];
    }

    /** @return array{state:string, field:string, message:string} */
    private function invalid(string $field, string $message): array
    {
        return ['state' => 'invalid', 'field' => $field, 'message' => $message];
    }
}
