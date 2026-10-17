<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Support\AdminStudentSearch;
use App\Support\SectionProgressSummary;

/** Staff progress projections; never records completion or changes purchased entitlements. */
final class AdminStudentProgressReadService
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence,
        private readonly CourseRevisionLearnerReadService $revisionReads,
        private readonly CourseAccessPlanService $plans,
        private readonly StudentProgressSummaryService $summaries,
        private readonly AdminContentInventoryReadService $inventory
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

        AdminStudentSearch::apply($query, (string) ($filters['search'] ?? ''));

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

        // Draft working copies and archived revisions share the courses
        // table; list each authored course once, like the course inventory.
        $courses = $this->inventory->courses()
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get(['courses.id', 'courses.name_ar', 'courses.name_en']);

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
}
