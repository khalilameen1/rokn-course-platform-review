<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Support\SectionProgressSummary;
use Illuminate\Support\Collection;

/** Staff progress projections; never records completion or changes purchased entitlements. */
final class AdminStudentProgressReadService
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence,
        private readonly CourseRevisionLearnerReadService $revisionReads,
        private readonly CourseAccessPlanService $plans,
        private readonly StudentProgressSummaryService $summaries
    ) {
    }

    /** @param array<string, mixed> $filters
     *  @param array<string, mixed> $queryParameters
     *  @return array<string, mixed>
     */
    public function listing(array $filters, array $queryParameters): array
    {
        $query = User::query()->students()
            ->orderByDesc('active')
            ->orderByDesc('id');

        // Search functionality
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('name_ar', 'LIKE', "%{$search}%")
                  ->orWhere('name_en', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        // Filter by course enrollment
        if (!empty($filters['course_id'])) {
            $query->whereHas('enrollments', function($q) use ($filters) {
                $q->active()->where('course_id', $filters['course_id']);
            });
        }

        $users = $query->paginate(15)->appends($queryParameters);

        $summaryByUser = $this->summaries->latestForUsers(
            $users->getCollection(),
            !empty($filters['course_id']) ? (int) $filters['course_id'] : null
        );
        $usersWithProgress = $users->getCollection()
            ->map(fn (User $user) => $summaryByUser->get($user->id));

        // Get courses for filter dropdown
        $courses = Course::select('id', 'name_ar', 'name_en')->get();

        return [
            'usersWithProgress' => $usersWithProgress,
            'users' => $users,
            'courses' => $courses,
            'filters' => $filters
        ];
    }


    public function workspace(int $userId): array
    {
        $user = User::query()->students()->findOrFail($userId);

        // Get all active enrollments for the user
        $enrollments = CourseEnrollment::where('user_id', $userId)
            ->active()
            ->with(['course', 'order'])
            ->orderBy('enrolled_at', 'desc')
            ->orderByDesc('id')
            ->get();

        $courseIds = $enrollments->pluck('course_id')->filter()->unique()->values();
        $sectionsByCourse = CourseSection::query()
            ->whereIn('course_id', $courseIds)
            ->orderBy('order')
            ->orderBy('id')
            ->get([
                'id', 'course_id', 'module_id', 'title', 'title_ar', 'title_en',
                'order', 'section_type', 'sectionable_type',
            ])
            ->groupBy('course_id')
            ->map(fn ($sections) => $this->sectionSequence->learning($sections));
        $progressBySection = $this->revisionReads
            ->sectionProgressRows((int) $userId, $sectionsByCourse->flatten(1)->pluck('id'))
            ->keyBy('course_section_id');

        // Calculate progress for each enrolled course
        $coursesProgress = $enrollments->map(function ($enrollment) use ($sectionsByCourse, $progressBySection) {
            $projectsEnabled = $this->plans->projectsEnabledForEnrollment($enrollment);
            $sections = $this->sectionSequence->forProjectsPolicy(
                $sectionsByCourse->get($enrollment->course_id, collect()), $projectsEnabled
            );
            $progressData = SectionProgressSummary::for($sections, $progressBySection);
            $progressData['projects_enabled'] = $projectsEnabled;

            return [
                'enrollment' => $enrollment,
                'course' => $enrollment->course,
                'progress' => $progressData,
                'sections_detail' => $sections->map(function ($section) use ($progressBySection): array {
                    $sectionProgress = $progressBySection->get($section->id);

                    return [
                        'id' => $section->id,
                        'title' => $section->title,
                        'order' => $section->order,
                        'type' => $section->getSectionType(),
                        'is_completed' => (bool) ($sectionProgress?->is_completed ?? false),
                        'completed_at' => $sectionProgress?->completed_at ?? $sectionProgress?->updated_at,
                    ];
                }),
            ];
        });

        return [
            'user' => $user,
            'coursesProgress' => $coursesProgress,
            'totalEnrollments' => $enrollments->count()
        ];
    }


    public function statistics(): array
    {
        $totalUsers = User::query()->students()->count();
        $activeEnrollmentRows = CourseEnrollment::query()
            ->active()
            ->whereHas('user', fn ($users) => $users->students())
            // Snapshot and completion scope must survive projection. Loading
            // only user/course IDs silently treats watch-only as legacy access.
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'course_id', 'access_plan_id', 'access_plan_snapshot', 'completed_with_projects'])
            ->unique(fn (CourseEnrollment $row) => $row->user_id . ':' . $row->course_id)
            ->values();
        $activeEnrollments = $activeEnrollmentRows->count();

        $learningSections = $this->sectionSequence->learning(CourseSection::query()
            ->whereIn('course_id', $activeEnrollmentRows->pluck('course_id')->unique())
            ->get(['id', 'course_id', 'module_id', 'order', 'section_type', 'sectionable_type']));
        $learningSectionIds = $learningSections->pluck('id');
        $sectionsByCourse = $learningSections->groupBy('course_id');
        $courseBySection = $learningSections->pluck('course_id', 'id');
        $activePairs = $activeEnrollmentRows->keyBy(
            fn (CourseEnrollment $row): string => $row->user_id . ':' . $row->course_id
        );
        $entitledSectionsByPair = $activePairs->map(fn (CourseEnrollment $enrollment) =>
            $this->sectionSequence->forProjectsPolicy(
                $sectionsByCourse->get($enrollment->course_id, collect()),
                $this->plans->projectsEnabledForEnrollment($enrollment)
            )->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])
        );
        $completedRows = $this->revisionReads->sectionProgressRowsForUsers(
            $activeEnrollmentRows->pluck('user_id'),
            $learningSectionIds
        )->where('is_completed', true)
            ->filter(function ($row) use ($courseBySection, $entitledSectionsByPair): bool {
                $courseId = $courseBySection->get((int) $row->course_section_id);

                return $courseId !== null
                    && ($entitledSectionsByPair->get($row->user_id . ':' . $courseId)?->has((int) $row->course_section_id) ?? false);
            });
        $completedCounts = $completedRows
            ->groupBy(fn ($row): string => $row->user_id . ':'
                . $courseBySection->get((int) $row->course_section_id))
            ->map->count();
        $progressPercentages = $activeEnrollmentRows
            ->map(function (CourseEnrollment $enrollment) use ($entitledSectionsByPair, $completedCounts): ?float {
                $total = $entitledSectionsByPair->get($enrollment->user_id . ':' . $enrollment->course_id)?->count() ?? 0;
                if ($total === 0) {
                    return null;
                }

                $completed = (int) $completedCounts->get(
                    $enrollment->user_id . ':' . $enrollment->course_id,
                    0
                );

                return min(100, ($completed / $total) * 100);
            })
            ->filter(fn ($percentage) => $percentage !== null);
        $avgProgress = $progressPercentages->isNotEmpty()
            ? round((float) $progressPercentages->average(), 2)
            : 0;

        $topCounts = $completedRows->countBy('user_id')->sortDesc()->take(5);
        $topNames = User::query()->whereIn('id', $topCounts->keys())->pluck('name', 'id');
        $topStudents = $topCounts->map(fn (int $count, int $userId): array => [
            'id' => $userId,
            'name' => $topNames->get($userId),
            'completed_count' => $count,
        ])->values();

        return [
            'total_users' => $totalUsers,
            'active_enrollments' => $activeEnrollments,
            'average_progress' => $avgProgress,
            'top_students' => $topStudents
        ];
    }


    /** @param list<int> $userIds @return Collection<int, array<string, mixed>> */
    public function compare(array $userIds, int $courseId): Collection
    {
        return collect($userIds)->map(function ($userId) use ($courseId) {
            $user = User::query()->students()->findOrFail($userId);
            $sections = $this->entitledLearningSections((int) $userId, $courseId);
            $progressData = SectionProgressSummary::for($sections, $this->revisionReads
                ->sectionProgressRows((int) $userId, $sections->pluck('id')));
            // Keep the existing comparison API's empty-path marker.
            if ($sections->isEmpty()) $progressData['last_activity'] = 0;

            return [
                'user_id' => $userId,
                'user_name' => $user->name,
                'progress' => $progressData
            ];
        });
    }


    /** Staff reads follow the purchased path, not today's mutable offer. */
    private function entitledLearningSections(int $userId, int $courseId): Collection
    {
        $enrollment = CourseEnrollment::query()->where('user_id', $userId)
            ->where('course_id', $courseId)->active()->orderByDesc('id')->first();
        if (!$enrollment) return collect();

        return $this->sectionSequence->learning(
            CourseSection::query()->where('course_id', $courseId)->get(),
            $this->plans->projectsEnabledForEnrollment($enrollment)
        );
    }
}
