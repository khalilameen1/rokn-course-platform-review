<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class CertificateEligibilityService
{
    public function __construct(
        private CourseChatAccessService $courseAccess,
        private CourseSectionSequenceService $sectionSequence,
        private LearningEvidenceService $learningEvidence,
        private CurriculumCompletionService $curriculumCompletion,
        private CourseRevisionLearnerReadService $revisionReads
    ) {
    }

    /**
     * Batch eligibility for the learning dashboard. All revision-aware learner
     * evidence is fetched once; decisions retain the scalar rule order.
     *
     * @param Collection<int,Course> $courses
     * @param Collection<int,CourseEnrollment> $enrollments
     * @param array<int,array<string,mixed>> $entitlements
     * @return array<int,array{included:bool,available:bool,reason:string}>
     */
    public function forCourses(
        User $user,
        Collection $courses,
        Collection $enrollments,
        array $entitlements
    ): array {
        $courses = $courses->keyBy('id');
        $enrollments = $enrollments->keyBy('course_id');
        $earnedRevisions = $this->curriculumCompletion->earnedRevisions(
            $enrollments->values()
        );
        $financialReviewOrderIds = Order::query()
            ->whereIn('id', $enrollments->pluck('order_id')->filter()->unique())
            ->where('user_id', $user->id)
            ->whereIn('financial_status', [
                Order::FINANCIAL_PARTIALLY_RECOVERED,
                Order::FINANCIAL_REVIEW_REQUIRED,
            ])
            ->where('unrecovered_coins', '>', 0)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $result = [];
        $coursesRequiringEvidence = collect();
        foreach ($courses as $courseId => $course) {
            $courseId = (int) $courseId;
            $enrollment = $enrollments->get($courseId);
            $included = (bool) ($entitlements[$courseId]['certificate_available'] ?? false);
            if (!$enrollment || !$included) {
                $result[$courseId] = [
                    'included' => false,
                    'available' => false,
                    'reason' => 'upgrade_required',
                ];
                continue;
            }

            $earnedRevision = $earnedRevisions->get((int) $enrollment->id);
            if ($earnedRevision === null && !$enrollment->isActive()) {
                $result[$courseId] = [
                    'included' => true,
                    'available' => false,
                    'reason' => 'entitlement_inactive',
                ];
                continue;
            }
            if (
                $enrollment->order_id
                && $financialReviewOrderIds->has((int) $enrollment->order_id)
            ) {
                $result[$courseId] = [
                    'included' => true,
                    'available' => false,
                    'reason' => 'financial_review',
                ];
                continue;
            }
            if ($earnedRevision !== null) {
                $result[$courseId] = [
                    'included' => true,
                    'available' => true,
                    'reason' => 'ready',
                ];
                continue;
            }
            if (!$course->isPublishedForLearning()) {
                $result[$courseId] = [
                    'included' => true,
                    'available' => false,
                    'reason' => 'course_unavailable',
                ];
                continue;
            }

            $coursesRequiringEvidence->push($course);
        }

        if ($coursesRequiringEvidence->isEmpty()) {
            return $result;
        }

        $courseSections = CourseSection::query()
            ->whereIn('course_id', $coursesRequiringEvidence->pluck('id'))
            ->get([
                'id',
                'course_id',
                'section_type',
                'sectionable_type',
                'sectionable_id',
                'module_id',
                'order',
            ]);
        $sectionsByCourse = $this->sectionSequence->learningByCourse($courseSections);
        $allSections = $sectionsByCourse->flatten(1);
        $completedSectionIds = $this->revisionReads
            ->completedSectionIds((int) $user->id, $allSections->pluck('id'))
            ->flip();

        $lessonSections = $allSections->filter(
            fn (CourseSection $section): bool => $section->getSectionType() === 'lesson'
        );
        $lessons = Lesson::query()
            ->whereIn('id', $lessonSections->pluck('sectionable_id'))
            ->with('mediaState:id,lesson_id,duration_seconds')
            ->get()
            ->keyBy('id');
        $lessonEvidence = $this->revisionReads->lessonEvidenceMap(
            (int) $user->id,
            $lessons->keys()
        );

        $projectSections = $allSections->filter(
            fn (CourseSection $section): bool => $section->getSectionType() === 'project'
        );
        $graduationProjectIds = Project::query()
            ->whereIn('id', $projectSections->pluck('sectionable_id'))
            ->where('is_graduation_project', true)
            ->pluck('id');
        $passedProjectIds = $this->revisionReads
            ->passedProjectIds((int) $user->id, $graduationProjectIds)
            ->flip();

        foreach ($coursesRequiringEvidence as $course) {
            $courseId = (int) $course->id;
            $courseSections = $sectionsByCourse->get($courseId, collect());
            if (
                $courseSections->isEmpty()
                || $courseSections->contains(
                    fn (CourseSection $section): bool =>
                        !$completedSectionIds->has((int) $section->id)
                )
            ) {
                $result[$courseId] = [
                    'included' => true,
                    'available' => false,
                    'reason' => 'course_incomplete',
                ];
                continue;
            }

            $hasIncompleteLessonEvidence = $courseSections
                ->filter(
                    fn (CourseSection $section): bool =>
                        $section->getSectionType() === 'lesson'
                )
                ->contains(function (CourseSection $section) use (
                    $lessons,
                    $lessonEvidence
                ): bool {
                    $lesson = $lessons->get($section->sectionable_id);
                    $evidence = $lesson
                        ? $lessonEvidence->get((int) $lesson->id)
                        : null;
                    $requiredSeconds = $lesson && $evidence
                        ? $this->learningEvidence->requiredSeconds(
                            $lesson,
                            $evidence->duration_seconds
                        )
                        : null;

                    return $requiredSeconds === null
                        || (int) $evidence->verified_seconds < $requiredSeconds;
                });
            if ($hasIncompleteLessonEvidence) {
                $result[$courseId] = [
                    'included' => true,
                    'available' => false,
                    'reason' => 'learning_evidence_incomplete',
                ];
                continue;
            }

            $courseGraduationProjectIds = $courseSections
                ->filter(
                    fn (CourseSection $section): bool =>
                        $section->getSectionType() === 'project'
                )
                ->pluck('sectionable_id')
                ->intersect($graduationProjectIds);
            if ($courseGraduationProjectIds->contains(
                fn ($projectId): bool => !$passedProjectIds->has((int) $projectId)
            )) {
                $result[$courseId] = [
                    'included' => true,
                    'available' => false,
                    'reason' => 'graduation_project_incomplete',
                ];
                continue;
            }

            $result[$courseId] = [
                'included' => true,
                'available' => true,
                'reason' => 'ready',
            ];
        }

        return $result;
    }

    /** @return array{included:bool,available:bool,reason:string} */
    public function for(User $user, Course $course): array
    {
        $enrollment = $this->enrollmentFor($user, $course);
        $earnedRevision = $enrollment
            ? $this->curriculumCompletion->earnedRevision($enrollment)
            : null;
        if (!$enrollment) {
            return ['included' => false, 'available' => false, 'reason' => 'upgrade_required'];
        }

        $included = $this->courseAccess->enrollmentHasCertificateAccess($enrollment);
        if (!$included) return ['included' => false, 'available' => false, 'reason' => 'upgrade_required'];
        if ($earnedRevision === null && !$enrollment->isActive()) {
            return ['included' => true, 'available' => false, 'reason' => 'entitlement_inactive'];
        }
        if ($enrollment->order_id && Order::query()
            ->whereKey($enrollment->order_id)
            ->where('user_id', $user->id)
            ->whereIn('financial_status', [Order::FINANCIAL_PARTIALLY_RECOVERED, Order::FINANCIAL_REVIEW_REQUIRED])
            ->where('unrecovered_coins', '>', 0)
            ->exists()) {
            return ['included' => true, 'available' => false, 'reason' => 'financial_review'];
        }

        // Completion is an earned fact about a published revision. Moving the
        // course to draft to author its next revision, adding sections, or
        // changing its hierarchy must not revoke that fact.
        if ($earnedRevision !== null) {
            return ['included' => true, 'available' => true, 'reason' => 'ready'];
        }
        if (!$course->isPublishedForLearning()) {
            return ['included' => true, 'available' => false, 'reason' => 'course_unavailable'];
        }

        $sections = $this->sectionSequence->learning(
            CourseSection::query()
                ->where('course_id', $course->id)
                ->get(['id', 'course_id', 'section_type', 'sectionable_type', 'sectionable_id', 'module_id', 'order'])
        );
        if ($sections->isEmpty()) return ['included' => true, 'available' => false, 'reason' => 'course_incomplete'];
        $completed = $this->revisionReads->completedSectionIds(
            (int) $user->id,
            $sections->pluck('id')
        )->count();
        if ($completed !== $sections->count()) {
            return ['included' => true, 'available' => false, 'reason' => 'course_incomplete'];
        }

        $lessonSections = $sections->filter(
            fn (CourseSection $section): bool => $section->getSectionType() === 'lesson'
        );
        if ($lessonSections->isNotEmpty()) {
            $lessons = Lesson::query()
                ->whereIn('id', $lessonSections->pluck('sectionable_id'))
                ->with('mediaState:id,lesson_id,duration_seconds')
                ->get()
                ->keyBy('id');
            $evidence = $this->revisionReads->lessonEvidenceMap(
                (int) $user->id,
                $lessons->keys()
            );
            foreach ($lessonSections as $section) {
                $lesson = $lessons->get($section->sectionable_id);
                $row = $lesson
                    ? $evidence->get((int) $lesson->id)
                    : null;
                $required = $lesson && $row
                    ? $this->learningEvidence->requiredSeconds($lesson, $row->duration_seconds)
                    : null;
                if ($required === null || (int) $row->verified_seconds < $required) {
                    return ['included' => true, 'available' => false, 'reason' => 'learning_evidence_incomplete'];
                }
            }
        }

        $graduationProjectIds = $sections
            ->filter(fn (CourseSection $section): bool => $section->getSectionType() === 'project')
            ->pluck('sectionable_id');
        if ($graduationProjectIds->isNotEmpty()) {
            $graduationProjectIds = Project::query()
                ->whereIn('id', $graduationProjectIds)
                ->where('is_graduation_project', true)
                ->pluck('id');
            if ($graduationProjectIds->isNotEmpty()) {
                $passedGraduationProjects = $this->revisionReads->passedProjectIds(
                    (int) $user->id,
                    $graduationProjectIds
                )->count();
                if ($passedGraduationProjects !== $graduationProjectIds->count()) {
                    return ['included' => true, 'available' => false, 'reason' => 'graduation_project_incomplete'];
                }
            }
        }

        return ['included' => true, 'available' => true, 'reason' => 'ready'];
    }

    /**
     * Prefer the enrollment carrying the immutable earned revision. Access
     * expiry may close lessons, but it cannot erase a completion already won.
     */
    public function enrollmentFor(User $user, Course $course): ?CourseEnrollment
    {
        $enrollments = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->latest('id')
            ->get();

        return $enrollments->first(fn (CourseEnrollment $candidate): bool =>
            $this->curriculumCompletion->earnedRevision($candidate) !== null
        ) ?? $enrollments->first(fn (CourseEnrollment $candidate): bool => $candidate->isActive());
    }
}
