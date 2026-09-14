<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owns the irreversible boundary between mutable course authoring and a
 * learner's earned completion.
 */
final class CurriculumCompletionService
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence,
        private readonly CourseRevisionLearnerReadService $revisionReads,
        private readonly CourseAccessPlanService $plans
    ) {
    }

    public function markCompleted(
        int $userId,
        int $courseId,
        ?int $requestedRevision = null
    ): ?int {
        return DB::transaction(function () use ($userId, $courseId, $requestedRevision): ?int {
            $course = Course::query()->whereKey($courseId)->lockForUpdate()->first();
            if (!$course) {
                return null;
            }

            $revision = max(
                1,
                (int) ($requestedRevision
                    ?: $course->last_published_authoring_version
                    ?: $course->authoring_version
                    ?: 1)
            );

            // Rolling-deploy compatibility: version the signal immediately;
            // persistence starts as soon as the additive migration is present.
            if (!Schema::hasColumns('course_enrollments', [
                'completed_curriculum_revision',
                'curriculum_completed_at',
            ])) {
                return $revision;
            }

            $enrollment = CourseEnrollment::query()
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->lockForUpdate()
                ->first();
            if (!$enrollment) {
                return null;
            }

            $earnedRevision = (int) ($enrollment->completed_curriculum_revision ?? 0);
            $projectsEnabled = $this->plans->projectsEnabledForEnrollment($enrollment);
            $watchOnlyCompletion = $enrollment->completed_with_projects === false;
            if ($earnedRevision > 0 && !($watchOnlyCompletion && $projectsEnabled)) {
                return $earnedRevision;
            }

            // The durable marker is itself the authority. Never trust a
            // caller merely because it named its signal `course.completed`.
            $learningSectionIds = $this->sectionSequence->learning(
                CourseSection::query()->where('course_id', $courseId)->get(),
                $projectsEnabled
            )->pluck('id');
            if ($learningSectionIds->isEmpty()) {
                return null;
            }
            $completedSections = $this->revisionReads
                ->completedSectionIds($userId, $learningSectionIds)
                ->count();
            if ($completedSections !== $learningSectionIds->count()) {
                return null;
            }

            $completion = [
                'completed_curriculum_revision' => $revision,
                'curriculum_completed_at' => now(),
            ];
            if (Schema::hasColumn('course_enrollments', 'completed_with_projects')) {
                $completion['completed_with_projects'] = $projectsEnabled;
            } elseif (!$projectsEnabled) {
                // A rolling deployment may not mistake watching for completing
                // the practical curriculum before its additive scope column exists.
                return null;
            }
            $enrollment->forceFill($completion)->save();

            return $revision;
        }, 3);
    }

    public function earnedRevision(CourseEnrollment $enrollment): ?int
    {
        if (!Schema::hasColumn('course_enrollments', 'completed_curriculum_revision')) {
            return null;
        }

        $revision = (int) ($enrollment->completed_curriculum_revision ?? 0);

        // Completing the watch-only path must not award a practical certificate
        // immediately after an upgrade. The required projects still need passing.
        return $revision > 0 && $enrollment->completed_with_projects !== false ? $revision : null;
    }

    /**
     * Resolve a dashboard page without repeating the same schema probe for
     * every enrollment.
     *
     * @param Collection<int,CourseEnrollment> $enrollments
     * @return Collection<int,int> keyed by enrollment id
     */
    public function earnedRevisions(Collection $enrollments): Collection
    {
        if (!Schema::hasColumn('course_enrollments', 'completed_curriculum_revision')) {
            return collect();
        }

        return $enrollments
            ->filter(fn (CourseEnrollment $enrollment): bool =>
                (int) ($enrollment->completed_curriculum_revision ?? 0) > 0
                    && $enrollment->completed_with_projects !== false
            )
            ->mapWithKeys(fn (CourseEnrollment $enrollment): array => [
                (int) $enrollment->id => (int) $enrollment->completed_curriculum_revision,
            ]);
    }
}
