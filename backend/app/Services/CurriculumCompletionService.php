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
        private readonly CourseRevisionLearnerReadService $revisionReads
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
            if ($earnedRevision > 0) {
                return $earnedRevision;
            }

            // The durable marker is itself the authority. Never trust a
            // caller merely because it named its signal `course.completed`.
            $learningSectionIds = $this->sectionSequence->learning(
                CourseSection::query()->where('course_id', $courseId)->get()
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

            $enrollment->forceFill([
                'completed_curriculum_revision' => $revision,
                'curriculum_completed_at' => now(),
            ])->save();

            return $revision;
        }, 3);
    }

    public function earnedRevision(CourseEnrollment $enrollment): ?int
    {
        if (!Schema::hasColumn('course_enrollments', 'completed_curriculum_revision')) {
            return null;
        }

        $revision = (int) ($enrollment->completed_curriculum_revision ?? 0);

        return $revision > 0 ? $revision : null;
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
            )
            ->mapWithKeys(fn (CourseEnrollment $enrollment): array => [
                (int) $enrollment->id => (int) $enrollment->completed_curriculum_revision,
            ]);
    }
}
