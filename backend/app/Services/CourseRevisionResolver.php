<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\PlaybackSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Resolves draft/published identities and learner continuity without authoring writes. */
final class CourseRevisionResolver
{
    public function canonicalFor(Course $course): Course
    {
        $revision = CourseAuthoringRevision::query()
            ->where('revision_course_id', $course->id)->latest('id')->first();

        return $revision
            ? Course::query()->findOrFail($revision->canonical_course_id)
            : $course;
    }

    /** Return an existing working revision without creating one on a read. */
    public function activeDraftFor(Course $course): ?Course
    {
        $canonical = $this->canonicalFor($course);
        $revision = CourseAuthoringRevision::query()
            ->where('canonical_course_id', $canonical->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->where('active_slot', CourseAuthoringRevision::draftSlot((int) $canonical->id))
            ->first(['revision_course_id']);

        return $revision
            ? Course::query()->find($revision->revision_course_id)
            : null;
    }

    public function isManagedDraft(Course $course): bool
    {
        return CourseAuthoringRevision::query()
            ->where('revision_course_id', $course->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->exists();
    }

    public function explicitHeroSelection(Course $draft): ?bool
    {
        $revisionId = CourseAuthoringRevision::query()
            ->where('revision_course_id', $draft->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->value('id');
        if (!$revisionId) return null;

        $explicit = DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revisionId)
            ->where('entity_type', CourseAuthoringRevision::HERO_SELECTION_MARKER)
            ->exists();

        return $explicit ? (bool) $draft->is_main_course : null;
    }

    public function activeArchiveForCourse(Course $course): ?CourseAuthoringRevision
    {
        return CourseAuthoringRevision::query()
            ->where('revision_course_id', $course->id)
            ->where('status', CourseAuthoringRevision::ARCHIVED)
            ->where('retain_until', '>', now())
            ->latest('id')->first();
    }

    /**
     * Resolve the one narrow grace case: a player allocated before publish is
     * allowed to finish its old media. The mapping points progress back at the
     * stable canonical course; it never grants discovery or a new old session.
     *
     * @return array{revision:CourseAuthoringRevision,session:PlaybackSession,canonical_course:Course,current_lesson:?Lesson,current_section:?CourseSection}|null
     */
    public function archivedPlaybackContinuation(
        User $user,
        Lesson $lesson,
        ?string $playbackSessionId
    ): ?array {
        $playbackSessionId = trim((string) $playbackSessionId);
        if ($playbackSessionId === '') return null;

        $archiveCourse = $lesson->relationLoaded('course')
            ? $lesson->course
            : $lesson->course()->first();
        if (!$archiveCourse) return null;

        $revision = $this->activeArchiveForCourse($archiveCourse);
        if (!$revision || !$revision->published_at) return null;

        $session = PlaybackSession::query()
            ->whereKey($playbackSessionId)
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->whereNull('ended_at')
            ->where('started_at', '>=', now()->subHours(12))
            ->where('started_at', '<=', $revision->published_at)
            ->first();
        if (!$session) return null;

        $mappings = DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revision->id)
            ->whereIn('entity_type', [Lesson::class, CourseSection::class])
            ->whereIn('source_entity_id', array_filter([
                (int) $lesson->id,
                (int) ($lesson->courseSection?->id ?? 0),
            ]))
            ->get()
            ->keyBy(fn ($row): string => $row->entity_type . ':' . $row->source_entity_id);

        $currentLessonId = $mappings->get(Lesson::class . ':' . $lesson->id)?->revision_entity_id;
        $oldSectionId = (int) ($lesson->courseSection?->id ?? 0);
        $currentSectionId = $mappings->get(CourseSection::class . ':' . $oldSectionId)?->revision_entity_id;
        $currentLesson = $currentLessonId
            ? Lesson::query()->with(['courseSection', 'mediaState'])->find($currentLessonId)
            : null;
        $currentSection = $currentSectionId
            ? CourseSection::query()->find($currentSectionId)
            : null;
        if (
            $currentLesson
            && $currentSection
            && (
                (int) $currentLesson->list_id !== (int) $revision->canonical_course_id
                || (int) $currentSection->course_id !== (int) $revision->canonical_course_id
                || $currentSection->getSectionType() !== 'lesson'
                || (int) $currentSection->sectionable_id !== (int) $currentLesson->id
            )
        ) {
            $currentLesson = null;
            $currentSection = null;
        }

        return [
            'revision' => $revision,
            'session' => $session,
            'canonical_course' => Course::query()->findOrFail($revision->canonical_course_id),
            'current_lesson' => $currentLesson,
            'current_section' => $currentSection,
        ];
    }

    /**
     * Return every historical ID equivalent to a current entity, following
     * successive publish mappings backwards. The current ID is always first.
     *
     * @return list<int>
     */
    public function equivalentEntityIds(string $entityType, int $currentId): array
    {
        return $this->equivalentEntityMap($entityType, [$currentId])[$currentId] ?? [$currentId];
    }

    /**
     * @param iterable<int> $currentIds
     * @return array<int,list<int>>
     */
    public function equivalentEntityMap(string $entityType, iterable $currentIds): array
    {
        $currentIds = collect($currentIds)->map(fn ($id): int => (int) $id)
            ->filter()->unique()->values()->all();
        $aliases = array_fill_keys($currentIds, []);
        foreach ($currentIds as $id) $aliases[$id] = [$id];
        if ($currentIds === []) return $aliases;
        $rootsByCurrent = DB::table('course_authoring_revision_entities as entities')
            ->join('course_authoring_revisions as revisions', 'revisions.id', '=', 'entities.course_authoring_revision_id')
            ->where('entities.entity_type', $entityType)
            ->where('entities.carries_learner_state', true)
            ->where('revisions.status', CourseAuthoringRevision::ARCHIVED)
            ->whereIn('entities.revision_entity_id', $currentIds)
            ->pluck('entities.learner_root_entity_id', 'entities.revision_entity_id')
            ->mapWithKeys(fn ($root, $current): array => [(int) $current => (int) $root]);
        if ($rootsByCurrent->isEmpty()) return $aliases;
        $currentByRoot = $rootsByCurrent->flip();
        DB::table('course_authoring_revision_entities as entities')
            ->join('course_authoring_revisions as revisions', 'revisions.id', '=', 'entities.course_authoring_revision_id')
            ->where('entities.entity_type', $entityType)
            ->where('entities.carries_learner_state', true)
            ->where('revisions.status', CourseAuthoringRevision::ARCHIVED)
            ->whereIn('entities.learner_root_entity_id', $rootsByCurrent->values())
            ->get(['entities.learner_root_entity_id', 'entities.source_entity_id', 'entities.revision_entity_id'])
            ->each(function ($row) use (&$aliases, $currentByRoot): void {
                $current = (int) $currentByRoot->get((int) $row->learner_root_entity_id);
                if (!$current) return;
                $aliases[$current][] = (int) $row->source_entity_id;
                $aliases[$current][] = (int) $row->revision_entity_id;
            });
        foreach ($aliases as $current => $ids) {
            $aliases[$current] = array_values(array_unique($ids));
        }

        return $aliases;
    }

    public function currentEntityId(string $entityType, int $historicalId): ?int
    {
        $current = $historicalId;
        $visited = [$current => true];
        while (true) {
            $next = DB::table('course_authoring_revision_entities as entities')
                ->join('course_authoring_revisions as revisions', 'revisions.id', '=', 'entities.course_authoring_revision_id')
                ->where('entities.entity_type', $entityType)
                ->where('entities.survives_publish', true)
                ->where('entities.source_entity_id', $current)
                ->where('revisions.status', CourseAuthoringRevision::ARCHIVED)
                ->orderByDesc('revisions.id')
                ->value('entities.revision_entity_id');
            if (!$next || isset($visited[(int) $next])) break;
            $current = (int) $next;
            $visited[$current] = true;
        }

        return $current === $historicalId ? null : $current;
    }

    /** @param iterable<int> $historicalIds @return array<int,int> input => current */
    public function currentLearnerEntityMap(string $entityType, iterable $historicalIds): array
    {
        $origins = collect($historicalIds)->map(fn ($id): int => (int) $id)
            ->filter()->unique()->values()->all();
        $resolved = array_combine($origins, $origins) ?: [];
        if ($origins === []) return $resolved;
        $rows = DB::table('course_authoring_revision_entities as entities')
            ->join('course_authoring_revisions as revisions', 'revisions.id', '=', 'entities.course_authoring_revision_id')
            ->where('entities.entity_type', $entityType)
            ->where('entities.carries_learner_state', true)
            ->where('revisions.status', CourseAuthoringRevision::ARCHIVED)
            ->where(function ($ids) use ($origins): void {
                $ids->whereIn('entities.source_entity_id', $origins)
                    ->orWhereIn('entities.revision_entity_id', $origins);
            })
            ->get(['entities.learner_root_entity_id', 'entities.source_entity_id', 'entities.revision_entity_id']);
        $rootByInput = [];
        foreach ($rows as $row) {
            $root = (int) $row->learner_root_entity_id;
            if (in_array((int) $row->source_entity_id, $origins, true)) $rootByInput[(int) $row->source_entity_id] = $root;
            if (in_array((int) $row->revision_entity_id, $origins, true)) $rootByInput[(int) $row->revision_entity_id] = $root;
        }
        if ($rootByInput === []) return $resolved;
        $latestByRoot = DB::table('course_authoring_revision_entities as entities')
            ->join('course_authoring_revisions as revisions', 'revisions.id', '=', 'entities.course_authoring_revision_id')
            ->where('entities.entity_type', $entityType)
            ->where('entities.carries_learner_state', true)
            ->where('revisions.status', CourseAuthoringRevision::ARCHIVED)
            ->whereIn('entities.learner_root_entity_id', array_values(array_unique($rootByInput)))
            ->orderByDesc('revisions.published_authoring_version')
            ->get(['entities.learner_root_entity_id', 'entities.revision_entity_id'])
            ->unique('learner_root_entity_id')
            ->pluck('revision_entity_id', 'learner_root_entity_id');
        foreach ($rootByInput as $input => $root) {
            $resolved[$input] = (int) $latestByRoot->get($root, $input);
        }

        return $resolved;
    }

}
