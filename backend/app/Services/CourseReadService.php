<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final readonly class CourseReadService
{
    public function __construct(
        private CourseDurationService $duration,
        private CourseChatAccessService $courseAccess,
        private CourseCatalogueQueryService $catalogue
    ) {}

    /**
     * @return array{course: Course, entitlement: array<string,mixed>, enrollment: CourseEnrollment|null}
     */
    public function detailedCourse(int $courseId, ?User $user): array
    {
        $resolution = $user
            ? $this->courseAccess->resolveEntitlement((int) $user->id, $courseId)
            : [
                'entitlement' => [
                    'has_learning_access' => false,
                    'access_type' => 'none',
                    'chat_available' => false,
                    'certificate_available' => false,
                    'plan_code' => null,
                    'plan_name' => null,
                    'project_feedback_level' => 'pass_only',
                ],
                'enrollment' => null,
            ];
        $access = $resolution['entitlement'];
        $mayReadPrivateCourse = (bool) ($access['has_learning_access'] ?? false);
        $course = $this->loadDetailedCourse($courseId, !$mayReadPrivateCourse);

        $enrollment = (bool) $access['has_learning_access']
            ? $resolution['enrollment']
            : null;

        return [
            'course' => $course,
            'entitlement' => $access,
            'enrollment' => $enrollment,
        ];
    }

    /** Draft override for the authenticated dashboard only; presentation is shared. */
    public function detailedCourseForAdminPreview(int $courseId): Course
    {
        return $this->loadDetailedCourse($courseId, false, true);
    }

    /**
     * @template T of array
     * @param Closure():T $read
     * @return array{changed:bool,revision:int|null,data:T|null}
     */
    public function readStablePublishedPayload(int $courseId, Closure $read): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $revision = $this->publishedRevision($courseId);
            $payload = $read();
            $finalRevision = $this->publishedRevision($courseId);

            if (
                $revision !== null
                && $revision === $finalRevision
                && (int) ($payload['published_revision'] ?? -1) === $finalRevision
            ) {
                return [
                    'changed' => false,
                    'revision' => $finalRevision,
                    'data' => $payload,
                ];
            }
        }

        return [
            'changed' => true,
            'revision' => $this->publishedRevision($courseId),
            'data' => null,
        ];
    }

    private function publishedRevision(int $courseId): ?int
    {
        $course = Course::query()->find($courseId, [
            'id',
            'authoring_version',
            'last_published_authoring_version',
        ]);
        if (!$course) {
            return null;
        }

        return max(
            0,
            (int) ($course->last_published_authoring_version ?: $course->authoring_version)
        );
    }

    private function loadDetailedCourse(
        int $courseId,
        bool $publicOnly = false,
        bool $adminPreview = false
    ): Course
    {
        $query = Course::query();
        if ($publicOnly) {
            $query = $this->catalogue->constrainPublic($query);
        } elseif (!$adminPreview) {
            $query->where('is_coming_soon', false)
                ->whereHas('sections');
        }

        $course = $query
            ->withCount(['ratings', 'activeEnrollments', 'sections'])
            ->withAvg('ratings', 'rating')
            ->with([
                'photo',
                'grade',
                'coursePath',
                'classifications',
                'teacher' => fn ($teacher) => $teacher
                    ->where('active', true)
                    ->whereIn('role', ['teacher', 'admin']),
                'teacher.photo',
                'teachers' => fn ($teachers) => $teachers
                    ->where('users.active', true)
                    ->orderBy('users.id'),
                'teachers.photo',
                'activePdfs',
                'accessPlans' => fn ($plans) => $plans->where('is_active', true),
                'modules' => function ($modules): void {
                    $modules->with([
                        'sections' => function ($sections): void {
                            $sections->with('sectionable')->orderBy('order');
                        },
                    ])->orderBy('order');
                },
            ])->findOrFail($courseId);
        $this->loadLessonMediaState($course);
        $this->duration->attach($course);

        return $course;
    }

    /**
     * @return array{course: Course|null, enrollment: CourseEnrollment|null, access_type: string}
     */
    public function progressCourse(int $userId, int $courseId): array
    {
        $resolution = $this->courseAccess->resolveEntitlement($userId, $courseId);
        $entitlement = $resolution['entitlement'];
        $enrollment = $entitlement['has_learning_access']
            ? $resolution['enrollment']
            : null;

        if (!$enrollment) {
            $course = Course::query()->withCount('sections')->find($courseId);
            if (!$course || !$course->isPublishedForLearning()) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())
                    ->setModel(Course::class, [$courseId]);
            }

            return [
                'course' => null,
                'enrollment' => null,
                'access_type' => 'none',
            ];
        }

        $course = Course::with([
            'modules' => fn ($modules) => $modules
                ->with(['sections' => fn ($sections) => $sections->orderBy('order')])
                ->orderBy('order'),
        ])->where('is_coming_soon', false)->findOrFail($courseId);

        if (!$course->isPublishedForLearning()) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())
                ->setModel(Course::class, [$courseId]);
        }

        return [
            'course' => $course,
            'enrollment' => $enrollment,
            'access_type' => (string) $entitlement['access_type'],
        ];
    }

    private function loadLessonMediaState(Course $course): void
    {
        $moduleSections = new EloquentCollection(
            $course->modules
                ->flatMap(fn ($module) => $module->sections)
                ->all()
        );
        $moduleSections->loadMorph('sectionable', [
            Lesson::class => ['mediaState'],
        ]);
    }
}
