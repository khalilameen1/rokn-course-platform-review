<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonWatchEvidence;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\StudentSectionProgress;
use DateTimeInterface;
use Illuminate\Support\Collection;

/** Reads immutable learner facts through semantically continuous revisions. */
final readonly class CourseRevisionLearnerReadService
{
    public function __construct(private CourseStagedAuthoringService $revisions) {}

    /** @return Collection<int,int> current section IDs */
    public function completedSectionIds(int $userId, iterable $currentSectionIds): Collection
    {
        $aliases = $this->revisions->equivalentEntityMap(CourseSection::class, $currentSectionIds);
        $reverse = $this->reverse($aliases);
        if ($reverse === []) return collect();

        return StudentSectionProgress::query()
            ->where('user_id', $userId)
            ->whereIn('course_section_id', array_keys($reverse))
            ->where('is_completed', true)
            ->pluck('course_section_id')
            ->map(fn ($id): int => $reverse[(int) $id])
            ->unique()->values();
    }

    public function completedSectionProgress(int $userId, int $currentSectionId): ?StudentSectionProgress
    {
        $aliases = $this->revisions->equivalentEntityIds(CourseSection::class, $currentSectionId);

        return StudentSectionProgress::query()
            ->where('user_id', $userId)
            ->whereIn('course_section_id', $aliases)
            ->where('is_completed', true)
            // Completion is irreversible; retain the original earned time
            // instead of making a publish look like a new completion.
            ->orderByRaw('completed_at IS NULL')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->first();
    }

    /** @return Collection<int,StudentSectionProgress> projected to current section IDs */
    public function sectionProgressRows(int $userId, iterable $currentSectionIds): Collection
    {
        return $this->sectionProgressRowsForUsers([$userId], $currentSectionIds);
    }

    /** @return Collection<int,StudentSectionProgress> projected to current section IDs */
    public function sectionProgressRowsForUsers(
        iterable $userIds,
        iterable $currentSectionIds,
        ?DateTimeInterface $completedBefore = null
    ): Collection
    {
        $userIds = collect($userIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        if ($userIds->isEmpty()) return collect();
        $aliases = $this->revisions->equivalentEntityMap(CourseSection::class, $currentSectionIds);
        $reverse = $this->reverse($aliases);
        if ($reverse === []) return collect();

        return StudentSectionProgress::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('course_section_id', array_keys($reverse))
            ->when($completedBefore, function ($query) use ($completedBefore): void {
                $query->where('is_completed', true)
                    ->whereNotNull('completed_at')
                    ->where('completed_at', '<', $completedBefore);
            })
            ->get()
            ->each(fn (StudentSectionProgress $row) =>
                $row->course_section_id = $reverse[(int) $row->course_section_id]
            )
            ->groupBy(fn (StudentSectionProgress $row): string =>
                $row->user_id . ':' . $row->course_section_id
            )
            ->map(function (Collection $rows): StudentSectionProgress {
                $completed = $rows->where('is_completed', true)
                    ->sortBy(fn (StudentSectionProgress $row): int =>
                        $row->completed_at?->getTimestamp() ?? PHP_INT_MAX
                    )->first();

                return $completed ?? $rows->sortByDesc(
                    fn (StudentSectionProgress $row): int =>
                        $row->updated_at?->getTimestamp() ?? 0
                )->first();
            })
            ->values();
    }

    /** @return Collection<int,int> current project IDs */
    public function passedProjectIds(int $userId, iterable $currentProjectIds): Collection
    {
        return $this->projectSubmissions($userId, $currentProjectIds)
            ->filter(fn (ProjectSubmission $submission): bool =>
                $submission->reviewOutcome()['passed']
            )
            ->keys()
            ->map(static fn ($id): int => (int) $id)
            ->values();
    }

    /**
     * Latest canonical submission keyed by the current published project ID.
     *
     * A course revision can leave submissions attached to an archived project
     * snapshot. We first bound the query to the latest row for every equivalent
     * project, then choose the newest row across that logical project. This
     * keeps navigation, reports, portfolio and certificate eligibility on one
     * state instead of a mutable shadow evaluation table.
     *
     * @param list<string> $with
     * @return Collection<int,ProjectSubmission>
     */
    public function projectSubmissions(
        int $userId,
        iterable $currentProjectIds,
        array $with = []
    ): Collection
    {
        $aliases = $this->revisions->equivalentEntityMap(Project::class, $currentProjectIds);
        $reverse = $this->reverse($aliases);
        if ($reverse === []) return collect();

        $latestIds = ProjectSubmission::query()
            ->selectRaw('MAX(id)')
            ->where('user_id', $userId)
            ->whereIn('project_id', array_keys($reverse))
            ->groupBy('project_id');

        return ProjectSubmission::query()
            ->with(array_values(array_unique($with)))
            ->whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (ProjectSubmission $row): int => $reverse[(int) $row->project_id])
            ->map(fn (Collection $rows): ProjectSubmission => $rows->first());
    }

    public function lessonEvidence(int $userId, int $currentLessonId): ?LessonWatchEvidence
    {
        return $this->lessonEvidenceMap($userId, [$currentLessonId])->get($currentLessonId);
    }

    /** @return Collection<int,LessonWatchEvidence> keyed by current lesson ID */
    public function lessonEvidenceMap(int $userId, iterable $currentLessonIds): Collection
    {
        $aliases = $this->revisions->equivalentEntityMap(Lesson::class, $currentLessonIds);
        $reverse = $this->reverse($aliases);
        if ($reverse === []) return collect();

        return LessonWatchEvidence::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_id', array_keys($reverse))
            ->orderByDesc('completed_at')
            ->orderByDesc('verified_seconds')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (LessonWatchEvidence $row): int => $reverse[(int) $row->lesson_id])
            ->map(fn (Collection $rows): LessonWatchEvidence => $rows->first());
    }

    /**
     * @param array<int,list<int>> $aliases
     * @return array<int,int> historical ID => current ID
     */
    private function reverse(array $aliases): array
    {
        $reverse = [];
        foreach ($aliases as $current => $ids) {
            foreach ($ids as $id) $reverse[(int) $id] = (int) $current;
        }

        return $reverse;
    }
}
