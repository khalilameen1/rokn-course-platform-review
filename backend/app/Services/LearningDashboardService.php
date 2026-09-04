<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\FinancialEntitlementHold;
use App\Models\User;
use App\Models\WatchingLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final readonly class LearningDashboardService
{
    private const MAX_ACTIVE_COURSES = 100;

    public function __construct(
        private CourseSectionSequenceService $sectionSequence,
        private LearningProgressStateService $progressState,
        private CourseChatAccessService $courseAccess,
        private CertificateEligibilityService $certificateEligibility,
        private LatestWatchResumeService $latestResume,
        private CourseRevisionLearnerReadService $revisionReads,
        private BunnyService $bunny
    ) {
    }

    /**
     * @return array{items: mixed}
     */
    public function forUser(
        User $user,
        int $perPage = self::MAX_ACTIVE_COURSES,
        ?string $cursor = null
    ): array
    {
        $perPage = max(1, min(self::MAX_ACTIVE_COURSES, $perPage));
        $decodedCursor = $cursor ? Cursor::fromEncoded($cursor) : null;
        if ($cursor && !$decodedCursor) {
            throw ValidationException::withMessages([
                'cursor' => ['مؤشر الصفحة غير صالح'],
            ]);
        }
        $enrollmentPage = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(function (Builder $active): void {
                $active->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereDoesntHave('financialHolds', static function (Builder $holds): void {
                $holds->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                    ->where('entitlement_scope', 'course');
            })
            // Filter before applying the bounded window. Otherwise an
            // unpublished or nested enrollment can consume one of the first
            // 101 rows, hide an older valid course and make has_more false.
            ->whereHas('course', static function (Builder $courses): void {
                $courses->where('is_coming_soon', false)
                    ->whereHas('sections');
            })
            ->with([
                'course' => fn ($courses) => $courses->withCount('sections'),
                'course.photo',
                'course.classifications',
            ])
            // The cursor must be based on a non-null immutable column. Legacy
            // enrollments can have no access_granted_at timestamp.
            ->latest('id')
            ->cursorPaginate(
                $perPage,
                ['*'],
                'cursor',
                $decodedCursor
            );
        $enrollments = $enrollmentPage->getCollection()->values();

        $courseIds = $enrollments
            ->pluck('course_id')
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $sections = CourseSection::query()
            ->whereIn('course_id', $courseIds)
            ->orderBy('course_id')
            ->orderBy('order')
            ->orderBy('id')
            ->get([
                'id', 'course_id', 'module_id', 'title', 'title_ar', 'title_en',
                'section_type', 'sectionable_type', 'sectionable_id', 'order',
            ]);
        $sectionsByCourse = $this->sectionSequence->learningByCourse($sections);
        $sections = $sectionsByCourse->flatten(1);
        $sectionCourse = $sections->pluck('course_id', 'id');
        $progressRows = $this->revisionReads->sectionProgressRows(
            (int) $user->id,
            $sectionCourse->keys()
        );
        $completedSectionIds = $progressRows
            ->where('is_completed', true)
            ->pluck('course_section_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();
        $entitlements = $this->courseAccess->entitlementsFor((int) $user->id, $courseIds);
        $certificateStatuses = $this->certificateEligibility->forCourses(
            $user,
            $enrollments->pluck('course'),
            $enrollments,
            $entitlements
        );
        $progressActivityByCourse = $progressRows
            ->groupBy(fn ($progress) => $sectionCourse->get($progress->course_section_id))
            ->map(fn ($rows) => $rows
                ->map(fn ($row) => $row->completed_at ?? $row->updated_at)
                ->filter()
                ->max());

        $resumeByCourse = collect();
        if ((bool) $user->watch_history_enabled && $courseIds->isNotEmpty()) {
            $resumeByCourse = $this->latestResume->forUser(
                (int) $user->id,
                $courseIds,
                [
                    'lesson:id,list_id,title,title_ar,title_en,thumbnail_path,duration_minutes',
                    'lesson.mediaState:id,lesson_id,duration_seconds',
                    'courseSection:id,course_id,sectionable_type,sectionable_id,order',
                ]
            )->filter(function (WatchingLog $log): bool {
                    return $log->lesson !== null
                        && $log->courseSection !== null
                        && (int) $log->lesson->list_id === (int) $log->course_id
                        && (int) $log->courseSection->course_id === (int) $log->course_id
                        && (int) $log->courseSection->sectionable_id === (int) $log->lesson_id;
                })
                ->keyBy(fn (WatchingLog $log): int => (int) $log->course_id);
        }

        $items = $enrollments->map(function (CourseEnrollment $enrollment) use (
            $progressActivityByCourse,
            $resumeByCourse,
            $sectionsByCourse,
            $completedSectionIds,
            $entitlements,
            $certificateStatuses
        ): array {
            $course = $enrollment->course;
            $courseId = (int) $course->id;
            $courseSections = $sectionsByCourse->get($courseId, collect());
            $state = $this->progressState->summarize(
                $courseSections,
                $completedSectionIds->keys()
            );
            $entitlement = $entitlements[$courseId] ?? [
                'access_type' => 'none', 'chat_available' => false, 'certificate_available' => false,
            ];
            // Current progress may legitimately fall below 100% after a new
            // course revision. The eligibility service also knows the durable
            // revision already earned by this enrollment, so it must own the
            // verdict shown in "My learning" as well as the issue endpoint.
            $certificateStatus = $certificateStatuses[$courseId] ?? [
                'included' => false, 'available' => false, 'reason' => 'upgrade_required',
            ];

            /** @var WatchingLog|null $resumeLog */
            $resumeLog = $resumeByCourse->get($courseId);
            $resume = ['available' => false];
            $watchActivity = null;
            if ($resumeLog) {
                $providerDuration = max(
                    0,
                    (int) ($resumeLog->lesson?->mediaState?->duration_seconds ?? 0)
                );
                $duration = $providerDuration > 0
                    ? $providerDuration
                    : max(0, (int) ($resumeLog->duration_seconds ?? 0));
                $position = max(0, (int) ($resumeLog->position_seconds ?? 0));
                $watchActivity = $resumeLog->watched_at ?? $resumeLog->updated_at;
                $thumbnailPath = trim((string) $resumeLog->lesson?->thumbnail_path);
                $thumbnail = $thumbnailPath !== ''
                    ? $this->bunny->generateBunnySignedUrl($thumbnailPath)
                    : null;
                $resume = [
                    'available' => true,
                    'lesson_id' => (int) $resumeLog->lesson_id,
                    'course_section_id' => (int) $resumeLog->course_section_id,
                    'lesson_title' => (string) ($resumeLog->lesson?->title ?? $resumeLog->lesson_name),
                    'thumbnail' => $thumbnail ?: ($course->image ? (string) $course->image : null),
                    'section_order' => (int) $resumeLog->courseSection?->order,
                    'position_seconds' => $position,
                    'duration_seconds' => $duration > 0 ? $duration : null,
                    'progress_percentage' => $duration > 0
                        ? min(100, round(($position / $duration) * 100, 2))
                        : null,
                    'watched_at' => $watchActivity?->toIso8601String(),
                ];
            }

            $progressActivity = $progressActivityByCourse->get($courseId);
            $lastActivity = collect([$watchActivity, $progressActivity])->filter()->max();

            return [
                'course_id' => $courseId,
                'title' => (string) $course->title,
                'image' => $course->image ? (string) $course->image : null,
                'progress_percentage' => $state['progress_percentage'],
                'completed_sections' => $state['completed_sections'],
                'total_sections' => $state['total_sections'],
                'is_completed' => $state['is_completed'],
                'learning_started' => $state['completed_sections'] > 0 || $resumeLog !== null,
                'resume' => $resume,
                'next_section' => $state['next_section'],
                'last_activity_at' => $lastActivity
                    ? Carbon::parse($lastActivity)->toIso8601String()
                    : null,
                'access_type' => (string) $entitlement['access_type'],
                'chat_available' => (bool) $entitlement['chat_available'],
                'certificate_included' => (bool) $certificateStatus['included'],
                'certificate_available' => (bool) $certificateStatus['available'],
                'access_granted_at' => $enrollment->access_granted_at?->toIso8601String(),
                'tags' => $course->classifications
                    ->map(fn ($classification): array => [
                        'name_ar' => $classification->name_ar,
                        'name_en' => $classification->name_en,
                    ])
                    ->values(),
            ];
        })->sort(function (array $left, array $right): int {
            if ($left['is_completed'] !== $right['is_completed']) {
                return $left['is_completed'] ? 1 : -1;
            }

            $activityOrder = strcmp(
                (string) ($right['last_activity_at'] ?? ''),
                (string) ($left['last_activity_at'] ?? '')
            );
            if ($activityOrder !== 0) {
                return $activityOrder;
            }

            $accessOrder = strcmp(
                (string) ($right['access_granted_at'] ?? ''),
                (string) ($left['access_granted_at'] ?? '')
            );

            return $accessOrder !== 0
                ? $accessOrder
                : ((int) $right['course_id'] <=> (int) $left['course_id']);
        })->values();

        return [
            'items' => $items,
            'pagination' => [
                'limit' => $perPage,
                'has_more' => $enrollmentPage->hasMorePages(),
                'next_cursor' => $enrollmentPage->nextCursor()?->encode(),
            ],
        ];
    }
}
