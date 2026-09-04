<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\StudentSectionProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CourseCompletionService
{
    public function __construct(
        private CoursePresentationService $coursePresentation,
        private LearningEvidenceService $learningEvidence,
        private CourseModuleAccessService $courseAccess,
        private InternalSignalService $internalSignals,
        private CourseRevisionLearnerReadService $revisionReads
    ) {
    }

    /**
     * @return array{success:bool,status:int,message:string,data:mixed,code?:string}
     */
    public function complete(User $user, int $courseId, int $sectionId): array
    {
        // Publishing takes the canonical course lock before swapping section
        // IDs. Hold that same boundary through evidence validation and the
        // progress write so a request can never complete a section removed by
        // a publish that won the race.
        return DB::transaction(function () use ($user, $courseId, $sectionId): array {
            $course = Course::query()
                ->whereKey($courseId)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->completeWithinCourseLock($user, $course, $sectionId);
        }, 3);
    }

    /** @return array{success:bool,status:int,message:string,data:mixed,code?:string} */
    private function completeWithinCourseLock(User $user, Course $course, int $sectionId): array
    {
        $courseId = (int) $course->id;
        if (!$course->isPublishedForLearning()) {
            return $this->failure(404, 'Course is not available for learning');
        }
        $section = CourseSection::query()
            ->whereKey($sectionId)
            ->where('course_id', $courseId)
            ->first();

        if (!$section) {
            return $this->failure(404, 'Section not found in this course');
        }
        if (!in_array($section->getSectionType(), ['lesson', 'project'], true)) {
            return $this->failure(404, 'Section is not part of the learning sequence');
        }
        if (!$this->courseAccess->hasCourseAccess($user, $course)) {
            return $this->failure(403, 'You are not authorized to access this course');
        }

        $existingProgress = $this->revisionReads->completedSectionProgress(
            (int) $user->id,
            $sectionId
        );

        if ($existingProgress && $existingProgress->is_completed) {
            $courseProgress = DB::transaction(function () use ($user, $courseId): array {
                User::query()->lockForUpdate()->findOrFail($user->id);

                return $this->recordCourseCompletionIfEligible(
                    (int) $user->id,
                    $courseId
                );
            }, 3);

            return $this->success(
                'Section already completed',
                [
                    'section' => $this->sectionPayload(
                        $section,
                        $existingProgress->completed_at ?? $existingProgress->updated_at
                    ),
                    'course_progress' => $courseProgress,
                ]
            );
        }

        if ($section->getSectionType() === 'project') {
            return $this->failure(
                409,
                'Submit the project before continuing',
                'project_submission_required'
            );
        }

        if ($section->getSectionType() === 'lesson') {
            $lesson = Lesson::with('courseSection')->find($section->sectionable_id);
            if (!$lesson) {
                return $this->failure(
                    409,
                    'Open this lesson and try again',
                    'lesson_evidence_unavailable'
                );
            }

            $evidence = $this->learningEvidence->evidenceFor($user, $lesson);
            if (!$evidence['eligible_for_completion']) {
                return $this->failure(
                    409,
                    'Continue this lesson before moving to the next step',
                    'verified_watch_required',
                    ['learning_evidence' => $evidence]
                );
            }
        }

        $courseSections = CourseSection::query()
            ->where('course_id', $courseId)
            ->orderBy('order')
            ->get();
        $completedSectionIds = $this->revisionReads->completedSectionIds(
            (int) $user->id,
            $courseSections->pluck('id')
        );
        $sectionState = $this->coursePresentation->sectionLockStatus(
            $courseSections,
            $completedSectionIds,
            (int) $user->id
        )->firstWhere('section_id', $section->id);

        if (($sectionState['is_locked'] ?? true) === true) {
            return $this->failure(
                409,
                'Complete the previous step before continuing',
                $sectionState['lock_reason'] ?? 'section_locked'
            );
        }

        [$progress, $courseProgress] = $this->recordCompletedSection(
            (int) $user->id,
            $section
        );

        return $this->success('Section marked as completed successfully', [
            'section' => $this->sectionPayload(
                $section,
                $progress->completed_at ?? $progress->updated_at
            ),
            'course_progress' => $courseProgress,
        ]);
    }

    /** Project review is the only caller allowed to complete a project gate. */
    public function recordPassedProject(int $userId, CourseSection $section): array
    {
        if ($section->getSectionType() !== 'project') {
            throw new \InvalidArgumentException('Only a passed project can complete a project section.');
        }

        [, $summary] = $this->recordCompletedSection($userId, $section);

        return $summary;
    }

    /** @return array{0:StudentSectionProgress,1:array<string,mixed>} */
    private function recordCompletedSection(int $userId, CourseSection $section): array
    {
        return DB::transaction(function () use ($userId, $section): array {
            User::query()->lockForUpdate()->findOrFail($userId);

            DB::table('student_section_progress')->insertOrIgnore([
                'user_id' => $userId,
                'course_section_id' => (int) $section->id,
                'is_completed' => true,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $progress = StudentSectionProgress::query()
                ->where('user_id', $userId)
                ->where('course_section_id', $section->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (!$progress->is_completed) {
                $progress->forceFill([
                    'is_completed' => true,
                    'completed_at' => $progress->completed_at ?? now(),
                ])->save();
            }

            $summary = $this->recordCourseCompletionIfEligible(
                $userId,
                (int) $section->course_id
            );

            return [$progress, $summary];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function recordCourseCompletionIfEligible(int $userId, int $courseId): array
    {
        $progress = $this->coursePresentation->progressSummary($userId, $courseId);
        if ($progress['is_completed']) {
            $this->internalSignals->record(
                'course.completed',
                "user:{$userId}:course:{$courseId}",
                ['user_id' => $userId, 'course_id' => $courseId],
                'course_enrollment',
                "{$userId}:{$courseId}"
            );
        }

        return $progress;
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

        $sections = CourseSection::query()
            ->where('course_id', $section->course_id)
            ->get();
        $completedSectionIds = $this->revisionReads->completedSectionIds(
            (int) $user->id,
            $sections->pluck('id')
        );

        $state = $this->coursePresentation->sectionLockStatus(
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

    /** @return array<string, mixed> */
    private function sectionPayload(CourseSection $section, mixed $completedAt): array
    {
        return [
            'id' => $section->id,
            'title' => $section->title_ar ?? $section->title,
            'type' => $section->getSectionType(),
            'order' => $section->order,
            'is_completed' => true,
            'completed_at' => $completedAt,
        ];
    }

    /** @return array{success:true,status:200,message:string,data:mixed} */
    private function success(string $message, mixed $data): array
    {
        return [
            'success' => true,
            'status' => 200,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @return array{success:false,status:int,message:string,data:mixed,code?:string}
     */
    private function failure(
        int $status,
        string $message,
        ?string $code = null,
        mixed $data = null
    ): array {
        $result = [
            'success' => false,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
        if ($code !== null) {
            $result['code'] = $code;
        }

        return $result;
    }
}
