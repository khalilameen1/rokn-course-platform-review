<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseEnrollment;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;

/** Captures editorial claims once, inside the user/course issuance transaction. */
final readonly class CertificateIssuanceSnapshotService
{
    public const DESIGN_VERSION = CertificateArtworkRenderer::VERSION;

    public function __construct(
        private CertificateTextTemplateService $templates,
        private CertificateEligibilityService $eligibility,
        private CurriculumCompletionService $completion,
        private CourseRevisionResolver $revisions,
        private CertificateQrDestinationService $qrDestinations
    ) {
    }

    /** Authoring shows a hypothetical completion, never a student's earned fact. */
    public function forPreview(Course $course): array
    {
        return [
            'certificate_design_version' => self::DESIGN_VERSION,
            'certificate_text' => CertificateTextTemplateService::COMPLETION_PREFIX,
            'certificate_completion_text' => $this->hasPassageProjects($course)
                ? CertificateTextTemplateService::PROJECTS_COMPLETION : '',
        ];
    }

    public function hasPassageProjects(Course $course): bool
    {
        return Project::query()
            ->whereHas('section', fn ($sections) => $sections->where('course_id', $course->id))
            ->where('is_graduation_project', false)
            ->exists();
    }

    /** @return array<string,mixed>|null */
    public function forIssuance(User $user, Course $course, string $publicId): ?array
    {
        $template = $this->templates->forIssuance($course);
        if ($template === null) {
            return null;
        }

        $enrollment = $this->eligibility->enrollmentFor($user, $course);
        $earnedRevision = $enrollment ? $this->completion->earnedRevision($enrollment) : null;
        $evidence = $enrollment && $earnedRevision !== null
            ? $this->passedProjects($user, $course, $enrollment, $earnedRevision)
            : [];

        return [
            'certificate_design_version' => self::DESIGN_VERSION,
            'certificate_text_template_key' => $template['key'],
            'certificate_text' => $template['text'],
            'certificate_completion_text' => $evidence !== []
                ? CertificateTextTemplateService::PROJECTS_COMPLETION : '',
            'certificate_curriculum_revision' => $earnedRevision,
            'certificate_project_evidence' => $evidence,
            'certificate_qr_snapshot' => $this->qrDestinations->forIssuance($user, $publicId),
        ];
    }

    /** @return list<array{project_id:int,submission_id:int}> */
    private function passedProjects(
        User $user,
        Course $course,
        CourseEnrollment $enrollment,
        int $earnedRevision
    ): array {
        // A bare completion integer is insufficient to place review facts in
        // time. The earned graph and its completion boundary must both exist.
        $completedAt = $enrollment->curriculum_completed_at;
        if (!$completedAt) {
            return [];
        }
        $graph = $this->earnedGraph($course, $earnedRevision);
        if (!$graph) {
            return [];
        }

        $projects = Project::query()
            ->whereHas('section', fn ($sections) => $sections->where('course_id', $graph->id))
            ->get(['id', 'is_graduation_project']);
        if ($projects->isEmpty() || !$projects->contains(
            fn (Project $project): bool => !$project->is_graduation_project
        )) {
            return [];
        }

        $currentIds = $this->revisions->currentLearnerEntityMap(
            Project::class,
            $projects->pluck('id')
        );
        $evidence = [];
        foreach ($projects as $project) {
            $aliases = $this->revisions->equivalentEntityIds(
                Project::class,
                $currentIds[(int) $project->id] ?? (int) $project->id
            );
            $aliases[] = (int) $project->id;
            $latest = ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', array_unique($aliases))
                ->where(function ($query) use ($completedAt): void {
                    $query->where('submitted_at', '<=', $completedAt)
                        ->orWhere(fn ($missingSubmissionTime) => $missingSubmissionTime
                            ->whereNull('submitted_at')->where('created_at', '<=', $completedAt));
                })
                ->latest('id')
                ->first();
            if (
                !$latest
                || !$latest->reviewOutcome()['passed']
                || !$latest->reviewed_at
                || $latest->reviewed_at->greaterThan($completedAt)
            ) {
                return [];
            }
            $evidence[] = [
                'project_id' => (int) $project->id,
                'submission_id' => (int) $latest->id,
            ];
        }

        return $evidence;
    }

    private function earnedGraph(Course $course, int $earnedRevision): ?Course
    {
        $currentRevision = max(1, (int) (
            $course->last_published_authoring_version ?: $course->authoring_version ?: 1
        ));
        if ($currentRevision === $earnedRevision) {
            return $course;
        }

        // An archived aggregate retains the previous published version and
        // its exact sections. Never inspect the newest graph for an old award.
        return CourseAuthoringRevision::query()
            ->where('canonical_course_id', $course->id)
            ->where('status', CourseAuthoringRevision::ARCHIVED)
            ->whereHas('revisionCourse', fn ($query) => $query
                ->where('last_published_authoring_version', $earnedRevision))
            ->with('revisionCourse')
            ->latest('id')
            ->first()?->revisionCourse;
    }
}
