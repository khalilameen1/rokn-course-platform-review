<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Resources\BaseCourseResource;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Support\RoknLocale;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final readonly class CoursePresentationService
{
    public function __construct(
        private CourseSectionSequenceService $sectionSequence,
        private LearningProgressStateService $progressState,
        private CertificateEligibilityService $certificateEligibility,
        private CourseRevisionLearnerReadService $revisionReads,
        private LatestWatchResumeService $latestResume,
        private CourseAccessPlanService $plans,
        private CourseSectionAccessService $sectionAccess
    )
    {
    }

    /**
     * @return array{courses: array<int, mixed>, pagination: array<string, int|null>}
     */
    public function mobileCataloguePayload(LengthAwarePaginator $courses): array
    {
        // Resource mapping must not mutate a paginator retained by an in-memory
        // cache store or a long-lived worker. Otherwise the next request sees
        // resources where the cached contract promises Course models.
        $presentedCourses = clone $courses;
        $presentedCourses->setCollection(
            $courses->getCollection()->map(
                fn (Course $course): BaseCourseResource => new BaseCourseResource($course)
            )
        );

        return [
            'courses' => $presentedCourses->items(),
            'pagination' => [
                'current_page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
                'from' => $courses->firstItem(),
                'to' => $courses->lastItem(),
            ],
        ];
    }

    /** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
    public function searchPayload(LengthAwarePaginator $courses, string $locale): array
    {
        $locale = RoknLocale::normalize($locale) ?? RoknLocale::ARABIC;
        $items = $courses->getCollection()->map(function (Course $course) use ($locale): array {
            $teacher = $course->teachers->first() ?: $course->teacher;
            $ratingsCount = (int) $course->ratings_count;
            $ratingAverage = round((float) ($course->ratings_avg_rating ?? 0), 1);

            return [
                'course_id' => (int) $course->id,
                'title' => (string) ($locale === RoknLocale::ARABIC
                    ? ($course->name_ar ?: $course->name_en)
                    : ($course->name_en ?: $course->name_ar)),
                'image' => $course->image ? (string) $course->image : null,
                'teacher_name' => $teacher ? (string) $teacher->name : null,
                'badge' => $locale === RoknLocale::ARABIC
                    ? ($course->catalog_badge_ar ?: $course->catalog_badge_en)
                    : ($course->catalog_badge_en ?: $course->catalog_badge_ar),
                'badge_tone' => $course->catalog_badge_tone ?: 'neutral',
                'is_coming_soon' => (bool) $course->is_coming_soon,
                'preview_count' => (int) $course->preview_reels_count,
                'duration_minutes' => max(0, (int) ($course->duration_minutes_computed ?? 0)),
                'ratings_count' => $ratingsCount,
                'rating_average' => $ratingAverage,
                'average_rating' => $ratingsCount > 0 ? $ratingAverage : null,
                'students_count' => (int) $course->active_enrollments_count,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
            ],
        ];
    }

    public function detailedCourse(
        Course $course,
        ?User $user,
        array $resolvedEntitlement,
        ?CourseEnrollment $resolvedEnrollment = null
    ): BaseCourseResource {
        $hasAccess = $user !== null
            && $resolvedEnrollment !== null
            && (bool) ($resolvedEntitlement['has_learning_access'] ?? false);
        $courseSections = $this->sectionSequence->fromModules($course->modules);
        $completedSectionIds = collect();
        if ($user && $hasAccess) {
            $completedSectionIds = $this->revisionReads->completedSectionIds(
                (int) $user->id,
                $courseSections->pluck('id')
            );
            $resource = (new CourseResource($course))->withLearningContext(
                $user,
                $completedSectionIds,
                $resolvedEntitlement,
                $resolvedEnrollment
            );
        } else {
            $resource = new BaseCourseResource($course);
        }

        $entitlement = $resolvedEntitlement;
        $certificateIncludedByPlan = (bool) $entitlement['certificate_available'];
        // CertificateEligibilityService is the single owner of the earned
        // completion contract. In particular, an already-earned published
        // revision remains eligible after a moderator publishes extra steps;
        // pre-gating on the current map would hide that certificate here while
        // the issue endpoint correctly continued to allow it.
        $certificateStatus = $user && $hasAccess && $certificateIncludedByPlan
            ? $this->certificateEligibility->for($user, $course)
            : ['included' => $certificateIncludedByPlan, 'available' => false];
        $certificateIncluded = (bool) $certificateStatus['included'];
        $certificateAvailable = (bool) $certificateStatus['available'];
        $learningStarted = $hasAccess && (
            $completedSectionIds->isNotEmpty()
            || $this->latestResume
                ->forUser((int) $user->id, [(int) $course->id])
                ->has((int) $course->id)
        );

        return $resource->withAccessPlans()->withEntitlement(
            (string) $entitlement['access_type'],
            (bool) $entitlement['chat_available'],
            $certificateIncluded,
            $certificateAvailable,
            $learningStarted
        );
    }

    /**
     * Build the exact entitled learner resource for a dashboard-only preview.
     * No enrollment, progress row or publication flag is written.
     *
     * @param array<string, mixed> $planContract
     */
    public function dashboardPreview(
        Course $course,
        User $actor,
        array $planContract,
        string $accessType
    ): CourseResource {
        $entitlement = $planContract + [
            'has_learning_access' => true,
            'access_type' => $accessType,
            'chat_available' => (bool) ($planContract['chat_enabled'] ?? false),
            'certificate_available' => (bool) ($planContract['certificate_enabled'] ?? false),
            'projects_available' => (bool) ($planContract['projects_enabled'] ?? true),
            'project_feedback_level' => (string) (
                $planContract['project_feedback_level'] ?? 'pass_only'
            ),
        ];

        return (new CourseResource($course))
            ->withLearningContext($actor, collect(), $entitlement, null)
            ->withDashboardPreviewContext($actor, $planContract)
            ->withEntitlement(
                $accessType,
                (bool) $entitlement['chat_available'],
                (bool) $entitlement['certificate_available'],
                false,
                false
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function progressPayload(
        Course $course,
        CourseEnrollment $enrollment,
        string $accessType,
        int $userId
    ): array {
        $learningSections = $this->sectionSequence->learning(
            $this->sectionSequence->fromModules($course->modules),
            $this->plans->projectsEnabledForEnrollment($enrollment)
        );
        $completedSectionIds = $this->revisionReads->completedSectionIds(
            $userId,
            $learningSections->pluck('id')
        );
        $summary = $this->progressState->summarize(
            $learningSections,
            $completedSectionIds
        );

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->name_ar,
                'title_en' => $course->name_en,
                'image' => $course->image,
            ],
            'enrollment' => [
                'id' => $enrollment->id,
                'enrolled_at' => $enrollment->enrolled_at,
                'expires_at' => $enrollment->expires_at,
                'is_active' => $enrollment->isActive(),
                'access_type' => $accessType,
            ],
            'progress' => $summary,
            'sections' => $this->sectionAccess->sectionLockStatus(
                $learningSections,
                $completedSectionIds,
                $userId
            ),
        ];
    }

    /** @return array<string,mixed> */
    public function progressSummary(int $userId, int $courseId): array
    {
        $course = Course::with([
            'modules' => fn ($modules) => $modules
                ->with(['sections' => fn ($sections) => $sections->orderBy('order')])
                ->orderBy('order'),
        ])->findOrFail($courseId);
        $learningSections = $this->sectionSequence->learning(
            $this->sectionSequence->fromModules($course->modules),
            $this->sectionAccess->projectsEnabledForUser($userId, $courseId)
        );
        $completedSectionIds = $this->revisionReads->completedSectionIds(
            $userId,
            $learningSections->pluck('id')
        );
        return $this->progressState->summarize(
            $learningSections,
            $completedSectionIds
        );
    }
}
