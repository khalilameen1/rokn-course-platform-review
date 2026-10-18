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
        private LearningAchievementSignalService $achievementSignals,
        private CourseRevisionLearnerReadService $revisionReads,
        private CourseRevisionResolver $revisions,
        private CourseSectionAccessService $sectionAccess
    ) {}

    /**
     * @return array{success:bool,status:int,message:string,data:mixed,code?:string}
     */
    public function complete(User $user, int $courseId, int $sectionId): array
    {
        // The player re-sends completion on every eligible heartbeat, including
        // rewatches. When the section is already durably completed and the
        // course has no pending completion write, the answer is a pure read of
        // committed state; answer it from a consistent (non-locking) read.
        // Every other outcome, including each failure, takes the locked path
        // below, which remains the only writer and the authority.
        $alreadyCompleted = $this->alreadyCompletedWithoutWrites($user, $courseId, $sectionId);
        if ($alreadyCompleted !== null) {
            return $alreadyCompleted;
        }

        // Publishing takes an exclusive lock on the canonical course before
        // swapping section IDs. A shared lock waits for that publish and holds
        // the next one off through evidence validation and the progress write,
        // so a request can never complete a section removed by a publish that
        // won the race, while learners of one course no longer queue on its
        // row. Lock order everywhere: learner, then course, then other rows.
        return DB::transaction(function () use ($user, $courseId, $sectionId): array {
            // Match submission, purchase and rating: learner before course.
            User::query()->lockForUpdate()->findOrFail($user->id);
            $course = Course::query()
                ->whereKey($courseId)
                ->sharedLock()
                ->firstOrFail();

            return $this->completeWithinCourseLock($user, $course, $sectionId);
        }, 3);
    }

    /**
     * Lock-free answer for a section that is already completed. Returns null
     * whenever the locked path could produce a different result or a write.
     *
     * @return array{success:true,status:200,message:string,data:mixed}|null
     */
    private function alreadyCompletedWithoutWrites(User $user, int $courseId, int $sectionId): ?array
    {
        $course = Course::query()->whereKey($courseId)->first();
        if (! $course || ! $course->isPublishedForLearning()) {
            return null;
        }
        $sectionId = $this->currentSectionId($sectionId);
        $existingProgress = $this->revisionReads->completedSectionProgress(
            (int) $user->id,
            $sectionId
        );
        if (! $existingProgress || ! $existingProgress->is_completed) {
            return null;
        }
        [$section] = $this->completableSection($user, $course, $sectionId);
        if (! $section) {
            return null;
        }

        $courseProgress = $this->coursePresentation->progressSummary(
            (int) $user->id,
            (int) $course->id
        );
        if ($courseProgress['is_completed']) {
            // Recording the course achievement is a write; keep it under the
            // learner lock that account deletion also takes.
            return null;
        }

        return $this->alreadyCompletedResult($section, $existingProgress, $courseProgress);
    }

    /** @return array{success:bool,status:int,message:string,data:mixed,code?:string} */
    private function completeWithinCourseLock(User $user, Course $course, int $sectionId): array
    {
        $courseId = (int) $course->id;
        if (! $course->isPublishedForLearning()) {
            return $this->failure(404, 'Course is not available for learning');
        }
        // A durable phone command retains the section it originally viewed.
        // Resolve only published learner-state lineage, under the same course
        // lock as completion; current membership/evidence/access still decide.
        $sectionId = $this->currentSectionId($sectionId);
        [$section, $failure] = $this->completableSection($user, $course, $sectionId);
        if (! $section) {
            return $failure;
        }

        $existingProgress = $this->revisionReads->completedSectionProgress(
            (int) $user->id,
            $sectionId
        );

        if ($existingProgress && $existingProgress->is_completed) {
            $courseProgress = DB::transaction(function () use ($user, $courseId): array {
                return $this->recordCourseCompletionIfEligible(
                    (int) $user->id,
                    $courseId
                );
            }, 3);

            return $this->alreadyCompletedResult($section, $existingProgress, $courseProgress);
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
            if (! $lesson) {
                return $this->failure(
                    409,
                    'Open this lesson and try again',
                    'lesson_evidence_unavailable'
                );
            }

            $evidence = $this->learningEvidence->evidenceFor($user, $lesson);
            if (! $evidence['eligible_for_completion']) {
                return $this->failure(
                    409,
                    'Continue this lesson before moving to the next step',
                    'verified_watch_required',
                    ['learning_evidence' => $evidence]
                );
            }
        }

        $sectionState = $this->sectionAccess->sequenceState($user, $section);

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
            if (! $progress->is_completed) {
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
            $this->achievementSignals->record(
                'course.completed',
                "user:{$userId}:course:{$courseId}",
                ['user_id' => $userId, 'course_id' => $courseId],
                'course_enrollment',
                "{$userId}:{$courseId}"
            );
        }

        return $progress;
    }

    private function currentSectionId(int $sectionId): int
    {
        return $this->revisions->currentLearnerEntityMap(
            CourseSection::class,
            [$sectionId]
        )[$sectionId] ?? $sectionId;
    }

    /**
     * @return array{0:CourseSection,1:null}|array{0:null,1:array{success:false,status:int,message:string,data:mixed,code?:string}}
     */
    private function completableSection(User $user, Course $course, int $sectionId): array
    {
        $courseId = (int) $course->id;
        $section = CourseSection::query()
            ->whereKey($sectionId)
            ->where('course_id', $courseId)
            ->first();

        if (! $section) {
            return [null, $this->failure(404, 'Section not found in this course')];
        }
        if (! in_array($section->getSectionType(), ['lesson', 'project'], true)) {
            return [null, $this->failure(404, 'Section is not part of the learning sequence')];
        }
        if (! $this->courseAccess->hasCourseAccess($user, $course)) {
            return [null, $this->failure(403, 'You are not authorized to access this course')];
        }
        if ($section->getSectionType() === 'project' && ! $this->sectionAccess->projectsEnabledForUser((int) $user->id, $courseId)) {
            return [null, $this->failure(403, 'Projects are not included in this subscription', 'projects_not_included')];
        }

        return [$section, null];
    }

    /**
     * @param  array<string, mixed>  $courseProgress
     * @return array{success:true,status:200,message:string,data:mixed}
     */
    private function alreadyCompletedResult(
        CourseSection $section,
        StudentSectionProgress $existingProgress,
        array $courseProgress
    ): array {
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
