<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Services\CourseSectionSequenceService;
use App\Services\StudentProgressSummaryService;
use App\Services\CourseRevisionLearnerReadService;
use App\Services\CourseAccessPlanService;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;

class StudentProgressController extends Controller
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence,
        private readonly CourseRevisionLearnerReadService $revisionReads,
        private readonly CourseAccessPlanService $plans
    ) {
    }

    /**
     * Display a listing of students with their progress
     * Shows last enrolled course progress for each user
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request, StudentProgressSummaryService $summaries)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:120',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);
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

        $users = $query->paginate(15)->appends($request->query());

        $summaryByUser = $summaries->latestForUsers(
            $users->getCollection(),
            !empty($filters['course_id']) ? (int) $filters['course_id'] : null
        );
        $usersWithProgress = $users->getCollection()
            ->map(fn (User $user) => $summaryByUser->get($user->id));

        // Get courses for filter dropdown
        $courses = Course::select('id', 'name_ar', 'name_en')->get();

        return view('admin.student-progress.index', [
            'usersWithProgress' => $usersWithProgress,
            'users' => $users,
            'courses' => $courses,
            'filters' => $filters
        ]);
    }

    /**
     * Show detailed progress for a specific user
     * Displays all enrolled courses with section completion details
     *
     * @param int $userId
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show($userId)
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
            $completed = $sections->filter(
                fn ($section): bool => (bool) ($progressBySection->get($section->id)?->is_completed ?? false)
            );
            $sectionsByType = [];
            $completedByType = [];
            foreach ($sections as $section) {
                $type = $section->getSectionType();
                $sectionsByType[$type] = ($sectionsByType[$type] ?? 0) + 1;
                $completedByType[$type] = ($completedByType[$type] ?? 0)
                    + ($completed->contains('id', $section->id) ? 1 : 0);
            }
            $total = $sections->count();
            $completedCount = $completed->count();
            $progressData = [
                'total_sections' => $total,
                'projects_enabled' => $projectsEnabled,
                'completed_sections' => $completedCount,
                'progress_percentage' => $total > 0
                    ? min(100, (int) round(($completedCount / $total) * 100))
                    : 0,
                'sections_by_type' => $sectionsByType,
                'completed_by_type' => $completedByType,
                'last_activity' => $sections
                    ->map(function ($section) use ($progressBySection) {
                        $row = $progressBySection->get($section->id);
                        return $row?->completed_at ?? $row?->updated_at;
                    })
                    ->filter()
                    ->max(),
            ];

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

        return view('admin.student-progress.show', [
            'user' => $user,
            'coursesProgress' => $coursesProgress,
            'totalEnrollments' => $enrollments->count()
        ]);
    }

    /**
     * Get progress statistics for a specific course and user
     *
     * @param int $userId
     * @param int $courseId
     * @return array
     */
    private function calculateCourseProgress($userId, $courseId)
    {
        $sections = $this->entitledLearningSections((int) $userId, (int) $courseId);

        $totalSections = $sections->count();

        if ($totalSections === 0) {
            return [
                'total_sections' => 0,
                'completed_sections' => 0,
                'progress_percentage' => 0,
                'sections_by_type' => [],
                'completed_by_type' => [],
                'last_activity' => 0
            ];
        }

        // Get completed sections for this user
        $completedSectionIds = $this->revisionReads
            ->completedSectionIds((int) $userId, $sections->pluck('id'))
            ->toArray();

        $completedCount = count($completedSectionIds);

        // Calculate progress by section type
        $sectionsByType = [];
        $completedByType = [];

        foreach ($sections as $section) {
            $type = $section->getSectionType();

            if (!isset($sectionsByType[$type])) {
                $sectionsByType[$type] = 0;
                $completedByType[$type] = 0;
            }

            $sectionsByType[$type]++;

            if (in_array($section->id, $completedSectionIds)) {
                $completedByType[$type]++;
            }
        }

        return [
            'total_sections' => $totalSections,
            'completed_sections' => $completedCount,
            'progress_percentage' => $totalSections > 0 ? round(($completedCount / $totalSections) * 100) : 0,
            'sections_by_type' => $sectionsByType,
            'completed_by_type' => $completedByType,
            'last_activity' => $this->getLastActivity($userId, $courseId)
        ];
    }

    /**
     * Get detailed section information with completion status
     *
     * @param int $userId
     * @param int $courseId
     * @return \Illuminate\Support\Collection
     */
    private function getSectionsDetail($userId, $courseId)
    {
        $sections = $this->entitledLearningSections((int) $userId, (int) $courseId);

        $progress = $this->revisionReads
            ->sectionProgressRows((int) $userId, $sections->pluck('id'))
            ->keyBy('course_section_id');

        return $sections->map(function ($section) use ($progress) {
            $sectionProgress = $progress->get($section->id);

            return [
                'id' => $section->id,
                'title' => $section->title,
                'order' => $section->order,
                'type' => $section->getSectionType(),
                'is_completed' => $sectionProgress ? $sectionProgress->is_completed : false,
                'completed_at' => $sectionProgress
                    ? ($sectionProgress->completed_at ?? $sectionProgress->updated_at)
                    : null
            ];
        });
    }

    /**
     * Get last activity timestamp for a user in a course
     *
     * @param int $userId
     * @param int $courseId
     * @return \Illuminate\Support\Carbon|null
     */
    private function getLastActivity($userId, $courseId)
    {
        $sectionIds = $this->entitledLearningSections((int) $userId, (int) $courseId)->pluck('id');

        $lastProgress = $this->revisionReads
            ->sectionProgressRows((int) $userId, $sectionIds)
            ->sortByDesc(fn ($row) => $row->completed_at ?? $row->updated_at)
            ->first();

        return $lastProgress
            ? ($lastProgress->completed_at ?? $lastProgress->updated_at)
            : null;
    }

    /**
     * Get progress statistics for all users (for dashboard widgets)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
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

        return response()->json([
            'total_users' => $totalUsers,
            'active_enrollments' => $activeEnrollments,
            'average_progress' => $avgProgress,
            'top_students' => $topStudents
        ]);
    }

    /**
     * Get comparison data between multiple students
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function compare(Request $request)
    {
        $request->validate([
            'user_ids' => 'required|array|min:2|max:5',
            'user_ids.*' => [
                'integer',
                \Illuminate\Validation\Rule::exists('users', 'id')
                    ->where(fn ($users) => $users->whereRaw('LOWER(role) = ?', ['client'])),
            ],
            'course_id' => 'required|exists:courses,id'
        ]);

        $courseId = $request->course_id;
        $userIds = $request->user_ids;

        $comparison = collect($userIds)->map(function ($userId) use ($courseId) {
            $user = User::query()->students()->findOrFail($userId);
            $progressData = $this->calculateCourseProgress($userId, $courseId);

            return [
                'user_id' => $userId,
                'user_name' => $user->name,
                'progress' => $progressData
            ];
        });

        return response()->json([
            'course_id' => $courseId,
            'comparisons' => $comparison
        ]);
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
