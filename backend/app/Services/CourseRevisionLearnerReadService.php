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
        return $this->sectionProgressRows($userId, $currentSectionIds)
            ->where('is_completed', true)
            ->pluck('course_section_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public function completedSectionProgress(int $userId, int $currentSectionId): ?StudentSectionProgress
    {
        return $this->sectionProgressRows($userId, [$currentSectionId])
            ->first(fn (StudentSectionProgress $row): bool => $row->is_completed);
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
        $userIds = $this->ids($userIds);
        if ($userIds->isEmpty()) return collect();
        $currentSectionIds = $this->ids($currentSectionIds);
        if ($currentSectionIds->isEmpty()) return collect();

        $projectSections = CourseSection::query()
            ->whereIn('id', $currentSectionIds)
            ->where('sectionable_type', Project::class)
            ->get(['id', 'sectionable_id']);
        $projectSectionIds = $projectSections->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        // A mutable progress row remains the lesson read model only. Project
        // completion belongs to the latest canonical submission, so even a
        // legacy project progress row must not compete with that decision.
        $sectionAliases = $this->revisions->equivalentEntityMap(
            CourseSection::class,
            $currentSectionIds
        );
        $nonProjectAliases = array_diff_key(
            $sectionAliases,
            array_fill_keys($projectSectionIds, true)
        );
        $reverse = $this->reverse($nonProjectAliases);
        $progressRows = $reverse === []
            ? collect()
            : StudentSectionProgress::query()
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
                );

        $projectIds = $projectSections->pluck('sectionable_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $submissions = $this->projectSubmissionsForUsers($userIds, $projectIds);
        $sectionsByProject = $projectSections->groupBy('sectionable_id');
        $projectRows = collect();
        foreach ($submissions as $key => $submission) {
            [, $projectId] = explode(':', $key, 2);
            foreach ($sectionsByProject->get((int) $projectId, collect()) as $section) {
                $row = $this->projectProgressRow(
                    (int) $submission->user_id,
                    (int) $section->id,
                    $submission
                );
                if ($completedBefore && (
                    !$row->is_completed
                    || !$row->completed_at
                    || $row->completed_at->getTimestamp() >= $completedBefore->getTimestamp()
                )) {
                    continue;
                }
                $projectRows->push($row);
            }
        }

        return $progressRows
            ->concat($projectRows)
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
        return $this->projectSubmissionsForUsers([$userId], $currentProjectIds, $with)
            ->mapWithKeys(function (ProjectSubmission $submission, string $key): array {
                [, $projectId] = explode(':', $key, 2);

                return [(int) $projectId => $submission];
            });
    }

    /**
     * @param iterable<int> $userIds
     * @param iterable<int> $currentProjectIds
     * @param list<string> $with
     * @return Collection<string,ProjectSubmission> keyed by user ID and current project ID
     */
    private function projectSubmissionsForUsers(
        iterable $userIds,
        iterable $currentProjectIds,
        array $with = []
    ): Collection
    {
        $userIds = $this->ids($userIds);
        $currentProjectIds = $this->ids($currentProjectIds);
        if ($userIds->isEmpty() || $currentProjectIds->isEmpty()) return collect();

        $aliases = $this->revisions->equivalentEntityMap(Project::class, $currentProjectIds);
        $reverse = $this->reverse($aliases);
        if ($reverse === []) return collect();

        $latestIds = ProjectSubmission::query()
            ->selectRaw('MAX(id)')
            ->whereIn('user_id', $userIds)
            ->whereIn('project_id', array_keys($reverse))
            ->groupBy('user_id', 'project_id');

        return ProjectSubmission::query()
            ->with(array_values(array_unique($with)))
            ->whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (ProjectSubmission $row): string => $this->submissionKey(
                (int) $row->user_id,
                $reverse[(int) $row->project_id]
            ))
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

    /** @return Collection<int,int> */
    private function ids(iterable $ids): Collection
    {
        return collect($ids)
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    private function submissionKey(int $userId, int $projectId): string
    {
        return $userId . ':' . $projectId;
    }

    /**
     * Build the progress-compatible read model used by existing consumers.
     * It deliberately remains unsaved: the submission is the durable fact.
     */
    private function projectProgressRow(
        int $userId,
        int $sectionId,
        ProjectSubmission $submission
    ): StudentSectionProgress
    {
        $outcome = $submission->reviewOutcome();
        $completedAt = $outcome['passed']
            ? ($outcome['reviewed_at']
                ?? $submission->auto_pass_at
                ?? $submission->updated_at
                ?? $submission->submitted_at
                ?? $submission->created_at)
            : null;
        $updatedAt = $submission->updated_at
            ?? $submission->submitted_at
            ?? $submission->created_at;
        $row = new StudentSectionProgress();
        $row->forceFill([
            'user_id' => $userId,
            'course_section_id' => $sectionId,
            'is_completed' => (bool) $outcome['passed'],
            'completed_at' => $completedAt,
            'created_at' => $submission->created_at ?? $submission->submitted_at,
            'updated_at' => $updatedAt,
        ]);

        return $row;
    }
}
