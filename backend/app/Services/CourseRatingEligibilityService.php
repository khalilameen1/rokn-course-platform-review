<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseRating;
use App\Models\Lesson;
use App\Models\LessonWatchEvidence;
use App\Models\User;

final readonly class CourseRatingEligibilityService
{
    public function __construct(
        private CourseChatAccessService $courseAccess,
        private CourseRevisionLearnerReadService $revisionReads
    )
    {
    }

    /** @return array{can_rate: bool, reason: string} */
    public function for(
        User $user,
        Course $course,
        ?bool $hasLearningAccess = null,
        ?bool $hasEarnedRatingEligibility = null
    ): array
    {
        if (
            !$course->isPublishedForLearning()
            || !($hasLearningAccess
                ?? $this->courseAccess->hasLearningAccess((int) $user->id, (int) $course->id))
        ) {
            return ['can_rate' => false, 'reason' => 'course_access_required'];
        }

        // A review is a fact earned against the stable course identity. A
        // later curriculum publish may legitimately replace the lesson that
        // supplied the original watch evidence; it must not make an existing
        // review impossible to edit or restore after a user deleted it.
        $hasEarnedRatingEligibility ??= CourseRating::withTrashed()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();
        if ($hasEarnedRatingEligibility) {
            return ['can_rate' => true, 'reason' => 'eligible'];
        }

        $lessonSectionIds = $course->relationLoaded('sections')
            ? $course->sections
                ->where('sectionable_type', Lesson::class)
                ->pluck('id')
            : $course->sections()
                ->where('sectionable_type', Lesson::class)
                ->pluck('id');

        if ($lessonSectionIds->isEmpty()) {
            return ['can_rate' => false, 'reason' => 'watch_required'];
        }

        $lessonIds = $course->relationLoaded('sections')
            ? $course->sections->whereIn('id', $lessonSectionIds)->pluck('sectionable_id')
            : $course->sections()->whereIn('id', $lessonSectionIds)->pluck('sectionable_id');
        $hasVerifiedWatch = $this->revisionReads
            ->lessonEvidenceMap((int) $user->id, $lessonIds)
            ->contains(fn (LessonWatchEvidence $row): bool => $row->completed_at !== null);

        return [
            'can_rate' => $hasVerifiedWatch,
            'reason' => $hasVerifiedWatch ? 'eligible' : 'watch_required',
        ];
    }
}
