<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseAuthoringRevision;
use App\Models\CoursePdf;
use App\Models\CourseSection;
use App\Models\Lesson;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Carries learner identity aliases and lesson-scoped grants to a published graph.
 * Called after the graph swap, within the same authoring transaction and locks.
 * Does not copy learner progress rows or change purchased plan terms.
 */
final class CourseRevisionLineageService
{
    /**
     * Entity IDs change during an atomic graph swap; learner facts do not.
     * Carry only entities that survived in the validated draft. Deleted
     * sections remain immutable archive evidence and grant nothing new.
     */
    public function finalizeWithinTransaction(CourseAuthoringRevision $revision): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Course revision writes must share the authoring transaction.');
        }

        $mappings = DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revision->id)
            ->get()
            ->groupBy('entity_type');
        [$sectionMap, $contentMaps] = $this->survivingGraphMaps($revision, $mappings);
        $lessonMap = $contentMaps[Lesson::class] ?? [];

        // A cloned entity can be deleted or change semantic type before the
        // draft is published. Persist only the mappings that survived the
        // validated swap. Runtime reads follow these aliases, avoiding an
        // O(users x sections) copy while the canonical course is locked.
        DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revision->id)
            ->update(['survives_publish' => false, 'carries_learner_state' => false]);
        foreach ([CourseSection::class => $sectionMap] + $contentMaps as $entityType => $entityMap) {
            if ($entityMap === []) continue;
            $priorRoots = DB::table('course_authoring_revision_entities')
                ->where('entity_type', $entityType)
                ->where('carries_learner_state', true)
                ->whereIn('revision_entity_id', array_keys($entityMap))
                ->get(['revision_entity_id', 'learner_root_entity_id', 'source_entity_id'])
                ->mapWithKeys(fn ($row): array => [
                    (int) $row->revision_entity_id => (int) (
                        $row->learner_root_entity_id ?: $row->source_entity_id
                    ),
                ]);
            foreach ($entityMap as $sourceId => $targetId) {
                DB::table('course_authoring_revision_entities')
                    ->where('course_authoring_revision_id', $revision->id)
                    ->where('entity_type', $entityType)
                    ->where('source_entity_id', $sourceId)
                    ->update([
                        'survives_publish' => true,
                        'carries_learner_state' => true,
                        'learner_root_entity_id' => (int) $priorRoots->get($sourceId, $sourceId),
                    ]);
            }
        }
        foreach ([\App\Models\CourseModule::class => 'course_modules', CoursePdf::class => 'course_pdfs'] as $entityType => $table) {
            $currentIds = DB::table($table)
                ->where('course_id', $revision->canonical_course_id)
                ->when($entityType === CoursePdf::class, fn ($query) => $query->whereNull('deleted_at'))
                ->pluck('id');
            if ($currentIds->isEmpty()) continue;
            DB::table('course_authoring_revision_entities')
                ->where('course_authoring_revision_id', $revision->id)
                ->where('entity_type', $entityType)
                ->whereIn('revision_entity_id', $currentIds)
                ->update(['survives_publish' => true]);
        }

        // Legacy lesson-scoped codes are no longer created, but their stored
        // pointers still have to follow this publish even when every lesson
        // was removed. Leaving an archived lesson ID behind makes the admin
        // and redemption history describe content that is no longer in the
        // current course graph.
        $this->carryCourseCodeLessonPointersForward($revision, $lessonMap);

    }

    /** @param array<int,int> $lessonMap */
    private function carryCourseCodeLessonPointersForward(
        CourseAuthoringRevision $revision,
        array $lessonMap
    ): void {
        $sourceLessonIds = DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revision->id)
            ->where('entity_type', Lesson::class)
            ->pluck('source_entity_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($sourceLessonIds === []) return;

        DB::table('course_codes')->where('course_id', $revision->canonical_course_id)
            ->where('type', 'lesson')
            ->whereIn('lesson_id', $sourceLessonIds)
            ->orderBy('id')->chunkById(200, function ($codes) use ($lessonMap): void {
                foreach ($codes as $code) {
                    $target = $lessonMap[(int) $code->lesson_id] ?? null;
                    DB::table('course_codes')->where('id', $code->id)->update([
                        'lesson_id' => $target,
                        // An empty lesson scope must not silently become a
                        // whole-course grant. Keep the historical row, but
                        // make it unclaimable once its target is gone.
                        'is_active' => $target !== null && (bool) $code->is_active,
                    ]);
                }
            });
        DB::table('course_codes')->where('course_id', $revision->canonical_course_id)
            ->where('type', 'multiple_lessons')
            ->whereNotNull('lesson_ids')->orderBy('id')
            ->chunkById(200, function ($codes) use ($lessonMap, $sourceLessonIds): void {
                $sourceSet = array_fill_keys($sourceLessonIds, true);
                foreach ($codes as $code) {
                    $ids = json_decode((string) $code->lesson_ids, true);
                    if (!is_array($ids)) continue;
                    $mapped = [];
                    foreach ($ids as $id) {
                        $sourceId = (int) $id;
                        if (!isset($sourceSet[$sourceId])) {
                            // Do not rewrite an unrelated legacy pointer here;
                            // this publish owns only the source graph above.
                            $mapped[] = $sourceId;
                            continue;
                        }
                        if (isset($lessonMap[$sourceId])) {
                            $mapped[] = $lessonMap[$sourceId];
                        }
                    }
                    $mapped = array_values(array_unique($mapped));
                    if ($mapped !== array_values(array_map('intval', $ids))) {
                        DB::table('course_codes')->where('id', $code->id)->update([
                            'lesson_ids' => json_encode($mapped, JSON_THROW_ON_ERROR),
                            // An empty explicit list means no entitlement, not
                            // implicit access to every lesson in the course.
                            'is_active' => $mapped !== [] && (bool) $code->is_active,
                        ]);
                    }
                }
            });
    }

    /**
     * @return array{0:array<int,int>,1:array<string,array<int,int>>}
     */
    private function survivingGraphMaps(CourseAuthoringRevision $revision, Collection $mappings): array
    {
        $sectionRows = $mappings->get(CourseSection::class, collect());
        $sources = DB::table('course_sections')
            ->whereIn('id', $sectionRows->pluck('source_entity_id'))
            ->get()->keyBy('id');
        $targets = DB::table('course_sections')
            ->whereIn('id', $sectionRows->pluck('revision_entity_id'))
            ->where('course_id', $revision->canonical_course_id)
            ->whereNull('deleted_at')
            ->get()->keyBy('id');
        $candidateContentMaps = $mappings->except(CourseSection::class)
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row): array => [
                (int) $row->source_entity_id => (int) $row->revision_entity_id,
            ])->all());

        $sectionMap = [];
        $survivingContentMaps = [];
        foreach ($sectionRows as $row) {
            $source = $sources->get((int) $row->source_entity_id);
            $target = $targets->get((int) $row->revision_entity_id);
            if (!$source || !$target || $source->sectionable_type !== $target->sectionable_type) continue;
            $contentTarget = $candidateContentMaps->get($source->sectionable_type, [])[
                (int) $source->sectionable_id
            ] ?? null;
            if (!$contentTarget || (int) $contentTarget !== (int) $target->sectionable_id) continue;

            $sectionMap[(int) $source->id] = (int) $target->id;
            $survivingContentMaps[$source->sectionable_type][(int) $source->sectionable_id]
                = (int) $target->sectionable_id;
        }

        return [$sectionMap, $survivingContentMaps];
    }
}
