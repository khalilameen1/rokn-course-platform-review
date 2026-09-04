<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseSection;
use App\Models\CourseAuthoringRevision;
use App\Models\Lesson;
use App\Models\WatchingLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class LatestWatchResumeService
{
    public function __construct(private CourseStagedAuthoringService $revisions) {}

    /**
     * Return one newest resume row per course in the database, rather than
     * loading a learner's entire watch history and de-duplicating it in PHP.
     *
     * @param iterable<int, int|string> $courseIds
     * @param list<string> $relations
     * @return Collection<int, WatchingLog> keyed by course id
     */
    public function forUser(int $userId, iterable $courseIds, array $relations = []): Collection
    {
        $ids = collect($courseIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $currentLessonSections = CourseSection::query()
            ->whereIn('course_id', $ids)
            ->where('sectionable_type', Lesson::class)
            ->get(['id', 'course_id', 'sectionable_id']);
        $lessonAliases = $this->revisions->equivalentEntityMap(
            Lesson::class,
            $currentLessonSections->pluck('sectionable_id')
        );
        // Collection::flatMap() reindexes numeric keys while collapsing its
        // children. Lesson IDs are numeric, so using it here silently turned
        // {historical lesson id => current lesson id} into {0,1,... => id}
        // and could resume the neighbouring reel. Build the identity map
        // explicitly so database IDs remain keys.
        $lessonAliasToCurrent = collect();
        foreach ($lessonAliases as $currentId => $aliases) {
            foreach ($aliases as $alias) {
                $lessonAliasToCurrent->put((int) $alias, (int) $currentId);
            }
        }
        if ($lessonAliasToCurrent->isEmpty()) return collect();
        $currentSectionByLesson = $currentLessonSections->pluck('id', 'sectionable_id');

        // A publish keeps the canonical course ID but moves the previous graph
        // onto an archived course row. Resume evidence written before that
        // swap still carries the archived course_id, so resolve course lineage
        // before ranking instead of filtering those valid rows out.
        $courseAliasToCurrent = $ids->mapWithKeys(fn (int $id): array => [$id => $id]);
        DB::table('course_authoring_revisions')
            ->where('status', CourseAuthoringRevision::ARCHIVED)
            ->whereIn('canonical_course_id', $ids)
            ->get(['canonical_course_id', 'revision_course_id'])
            ->each(function ($revision) use ($courseAliasToCurrent): void {
                $courseAliasToCurrent->put(
                    (int) $revision->revision_course_id,
                    (int) $revision->canonical_course_id
                );
            });

        $courseCaseBindings = [];
        $courseCase = 'CASE course_id';
        foreach ($courseAliasToCurrent as $alias => $current) {
            $courseCase .= ' WHEN ? THEN ?';
            $courseCaseBindings[] = (int) $alias;
            $courseCaseBindings[] = (int) $current;
        }
        $courseCase .= ' ELSE course_id END';

        $candidates = DB::table('watching_logs')
            ->where('user_id', $userId)
            ->whereIn('course_id', $courseAliasToCurrent->keys())
            ->whereIn('lesson_id', $lessonAliasToCurrent->keys())
            ->select(['id', 'watched_at', 'updated_at'])
            ->selectRaw("{$courseCase} as canonical_course_id", $courseCaseBindings);
        $ranked = DB::query()
            ->fromSub($candidates, 'resume_candidates')
            ->select(['id', 'canonical_course_id'])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY canonical_course_id '
                . 'ORDER BY COALESCE(watched_at, updated_at) DESC, id DESC) as resume_rank'
            );

        $logs = WatchingLog::query()
            ->joinSub($ranked, 'latest_resume', function ($join): void {
                $join->on('latest_resume.id', '=', 'watching_logs.id')
                    ->where('latest_resume.resume_rank', 1);
            })
            ->select('watching_logs.*')
            ->get()
            ->each(function (WatchingLog $log) use (
                $lessonAliasToCurrent,
                $currentSectionByLesson,
                $courseAliasToCurrent
            ): void {
                $currentLessonId = (int) $lessonAliasToCurrent->get((int) $log->lesson_id);
                $log->lesson_id = $currentLessonId;
                $log->course_id = (int) $courseAliasToCurrent->get((int) $log->course_id, (int) $log->course_id);
                $log->course_section_id = $currentSectionByLesson->get($currentLessonId);
            });
        if ($relations !== []) $logs->load($relations);

        return $logs->keyBy(static fn (WatchingLog $log): int => (int) $log->course_id);
    }
}
