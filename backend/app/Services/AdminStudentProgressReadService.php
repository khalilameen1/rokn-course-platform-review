<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\User;
use App\Support\AdminStudentSearch;
use App\Support\SectionProgressSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Staff progress projections; never records completion or changes purchased entitlements. */
final class AdminStudentProgressReadService
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence,
        private readonly CourseRevisionLearnerReadService $revisionReads,
        private readonly CourseAccessPlanService $plans,
        private readonly AdminContentInventoryReadService $inventory
    ) {}

    /** @param array<string, mixed> $filters
     * @return Builder<User>
     */
    public function query(array $filters = []): Builder
    {
        $query = User::query()->students()
            ->orderByDesc('active')
            ->orderByDesc('id');

        return $this->applyFilters($query, $filters);
    }

    /** @param Builder<User> $query @return Builder<User> */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        AdminStudentSearch::apply($query, (string) ($filters['search'] ?? ''));

        // Filter by course enrollment
        if (! empty($filters['course_id'])) {
            $query->whereHas('enrollments', function ($q) use ($filters) {
                $q->active()->where('course_id', $filters['course_id']);
            });
        }

        return $query;
    }

    /** @return Collection<int, Course> */
    public function courses(): Collection
    {
        // Draft working copies and archived revisions share the courses
        // table; list each authored course once, like the course inventory.
        return $this->inventory->courses()
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get(['courses.id', 'courses.name_ar', 'courses.name_en']);

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
                        'completed_at' => $sectionProgress?->is_completed ? ($sectionProgress->completed_at ?? $sectionProgress->updated_at) : null,
                    ];
                }),
            ];
        });

        return [
            'user' => $user,
            'coursesProgress' => $coursesProgress,
            'totalEnrollments' => $enrollments->count(),
        ];
    }
}
