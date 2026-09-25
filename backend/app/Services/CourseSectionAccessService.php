<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\User;
use Illuminate\Support\Collection;

/** Read-only subscription and crossing-project gates shared by every learner surface. */
final readonly class CourseSectionAccessService
{
    public function __construct(
        private CourseModuleAccessService $courseAccess,
        private CourseSectionSequenceService $sectionSequence,
        private CourseRevisionLearnerReadService $revisionReads,
        private CourseAccessPlanService $plans
    ) {
    }

    public function canAccessSection(User $user, CourseSection $section): bool
    {
        return (bool) $this->sectionAccessState($user, $section)['can_access'];
    }

    /** @return array{can_access:bool,is_locked:bool,lock_reason:?string} */
    public function sectionAccessState(User $user, CourseSection $section): array
    {
        $course = $section->relationLoaded('course')
            ? $section->course
            : Course::find($section->course_id);
        if (!$course || !$this->courseAccess->hasCourseAccess($user, $course)) {
            return [
                'can_access' => false,
                'is_locked' => true,
                'lock_reason' => 'course_purchase_required',
            ];
        }
        if ($section->getSectionType() === 'project' && !$this->projectsEnabledForUser((int) $user->id, (int) $course->id)) {
            return ['can_access' => false, 'is_locked' => true, 'lock_reason' => 'projects_not_included'];
        }

        return $this->sequenceState($user, $section);
    }

    /**
     * Sequence gate only. Mutation callers must authorize the course/plan first
     * and retain their transaction locks through the eventual progress write.
     *
     * @return array{can_access:bool,is_locked:bool,lock_reason:?string}
     */
    public function sequenceState(User $user, CourseSection $section): array
    {
        $sections = CourseSection::query()
            ->where('course_id', $section->course_id)
            ->get();
        $completedSectionIds = $this->revisionReads->completedSectionIds(
            (int) $user->id,
            $sections->pluck('id')
        );

        $state = $this->sectionLockStatus(
            $sections,
            $completedSectionIds,
            (int) $user->id
        )->firstWhere('section_id', $section->id);

        return [
            'can_access' => (bool) ($state['can_access'] ?? false),
            'is_locked' => (bool) ($state['is_locked'] ?? true),
            'lock_reason' => isset($state['lock_reason'])
                ? (string) $state['lock_reason']
                : null,
        ];
    }

    public function sectionLockStatus(
        Collection $sections,
        Collection $completedSectionIds,
        ?int $userId = null,
        ?bool $projectsEnabled = null
    ): Collection {
        $projectsEnabled ??= $this->projectsEnabledForUser($userId, (int) $sections->first()?->course_id);
        $orderedSections = $this->sectionSequence->learning($sections, $projectsEnabled);

        $projectIds = $orderedSections
            ->filter(fn ($section): bool => $section->getSectionType() === 'project')
            ->pluck('sectionable_id')
            ->filter();
        $passedProjectIds = $userId && $projectIds->isNotEmpty()
            ? $this->revisionReads->passedProjectIds($userId, $projectIds)
            : collect();
        $hasUnpassedProjectGate = false;

        return $orderedSections->map(function ($section) use (
            &$hasUnpassedProjectGate,
            $completedSectionIds,
            $passedProjectIds,
            $userId
        ): array {
            $isProject = $section->getSectionType() === 'project';
            $projectPassed = $isProject
                && $userId
                && $passedProjectIds->contains($section->sectionable_id);
            // Review status is authoritative for a project. The derived
            // progress row can lag a committed review and must never open or
            // close a crossing gate by itself.
            $isCompleted = $isProject && $userId
                ? $projectPassed
                : $completedSectionIds->contains($section->id);
            $isLocked = false;
            $lockReason = null;

            if ($userId && $hasUnpassedProjectGate) {
                $isLocked = true;
                $lockReason = 'module_project_not_passed';
            }

            if ($userId && $isProject && !$projectPassed) {
                $hasUnpassedProjectGate = true;
            }

            return [
                'section_id' => $section->id,
                'title' => $section->title_ar ?? $section->title,
                'type' => $section->getSectionType(),
                'order' => $section->order,
                'module_id' => $section->module_id,
                'is_completed' => $isCompleted,
                'is_locked' => $isLocked,
                'lock_reason' => $lockReason,
                'can_access' => !$isLocked,
            ];
        });
    }

    public function projectsEnabledForUser(?int $userId, int $courseId): bool
    {
        if (!$userId || !$courseId) return true;

        $enrollment = CourseEnrollment::query()
            ->where('user_id', $userId)->where('course_id', $courseId)->first();

        // Public/legacy callers retain the full curriculum. Access itself is
        // enforced separately; only an explicit captured watch-only plan skips projects.
        return !$enrollment || $this->plans->projectsEnabledForEnrollment($enrollment);
    }
}
