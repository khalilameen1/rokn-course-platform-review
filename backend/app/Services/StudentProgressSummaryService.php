<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\StudentSectionProgress;
use App\Models\User;
use Illuminate\Support\Collection;

final class StudentProgressSummaryService
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence,
        private readonly CourseRevisionLearnerReadService $revisionReads,
        private readonly CourseAccessPlanService $plans
    ) {
    }

    /**
     * @param Collection<int, User> $users
     * @return Collection<int, array<string, mixed>> keyed by user id
     */
    public function latestForUsers(Collection $users, ?int $courseId = null): Collection
    {
        $userIds = $users->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($userIds === []) {
            return collect();
        }

        $enrollments = CourseEnrollment::query()
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->where(function ($active): void {
                $active->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->with('course')
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id')
            ->map->first();
        $courseIds = $enrollments
            ->pluck('course_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $sectionsByCourse = CourseSection::query()
            ->whereIn('course_id', $courseIds)
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'course_id', 'module_id', 'order', 'section_type', 'sectionable_type'])
            ->groupBy('course_id')
            ->map(fn ($sections) => $this->sectionSequence->learning($sections));
        $sectionIds = $sectionsByCourse->flatten(1)->pluck('id');
        $progressByUser = $this->revisionReads
            ->sectionProgressRowsForUsers($userIds, $sectionIds)
            ->groupBy('user_id');

        return $users->mapWithKeys(function (User $user) use (
            $enrollments,
            $sectionsByCourse,
            $progressByUser
        ): array {
            /** @var CourseEnrollment|null $enrollment */
            $enrollment = $enrollments->get($user->id);
            if (!$enrollment) {
                return [$user->id => [
                    'user' => $user,
                    'has_enrollment' => false,
                    'course' => null,
                    'progress' => null,
                ]];
            }

            $sections = $sectionsByCourse->get($enrollment->course_id, collect());
            $sections = $this->sectionSequence->forProjectsPolicy(
                $sections,
                $this->plans->projectsEnabledForEnrollment($enrollment)
            );
            $sectionIdSet = $sections->pluck('id')->flip();
            $progress = $progressByUser
                ->get($user->id, collect())
                ->filter(fn (StudentSectionProgress $row): bool => $sectionIdSet->has($row->course_section_id));
            $completedIds = $progress
                ->where('is_completed', true)
                ->pluck('course_section_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->flip();
            $sectionsByType = [];
            $completedByType = [];
            foreach ($sections as $section) {
                $type = $section->getSectionType();
                $sectionsByType[$type] = ($sectionsByType[$type] ?? 0) + 1;
                $completedByType[$type] = ($completedByType[$type] ?? 0)
                    + ($completedIds->has((int) $section->id) ? 1 : 0);
            }
            $total = $sections->count();
            $completed = $completedIds->count();

            return [$user->id => [
                'user' => $user,
                'has_enrollment' => true,
                'course' => $enrollment->course,
                'enrolled_at' => $enrollment->enrolled_at,
                'progress' => [
                    'total_sections' => $total,
                    'completed_sections' => $completed,
                    'progress_percentage' => $total > 0
                        ? min(100, (int) round(($completed / $total) * 100))
                        : 0,
                    'sections_by_type' => $sectionsByType,
                    'completed_by_type' => $completedByType,
                    'last_activity' => $progress
                        ->map(fn (StudentSectionProgress $row) => $row->completed_at ?? $row->updated_at)
                        ->filter()
                        ->max(),
                ],
            ]];
        });
    }
}
