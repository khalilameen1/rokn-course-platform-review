<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CoursePdf;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Photo;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns isolated content copies and their atomic exchange with the live graph.
 * The caller holds canonical -> revision -> draft locks and owns commit/rollback.
 * Commercial identity rules belong to CoursePlanPublicationService; learner
 * continuity is finalized separately after this graph becomes canonical.
 */
final readonly class CourseRevisionGraphService
{
    public function __construct(private CoursePlanPublicationService $plans)
    {
    }

    /** @return array{0:Course,1:list<array{0:string,1:int,2:int}>} */
    public function cloneWithinTransaction(Course $source): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Course revision writes must share the authoring transaction.');
        }

        $entityMappings = [];
        $draft = $source->replicate(['authoring_request_id', 'published_at']);
        $draft->forceFill([
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'is_main_course' => false,
            'published_at' => null,
            'authoring_request_id' => null,
        ])->saveQuietly();

        $source->classifications()->pluck('classifications.id')->each(
            function ($id) use ($draft, &$entityMappings): void {
                DB::table('classification_course')->insert([
                    'classification_id' => $id, 'course_id' => $draft->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                // The same relation is edited both from the course studio and
                // from home-row curation. Keep the clone-time state so publish
                // can merge both editors instead of silently replacing either.
                $entityMappings[] = [CourseAuthoringRevision::CLASSIFICATION_SNAPSHOT, (int) $id, (int) $id];
            }
        );
        // An empty base is meaningful and must remain distinguishable from a
        // legacy revision created before snapshots were recorded.
        $entityMappings[] = [
            CourseAuthoringRevision::CLASSIFICATION_SNAPSHOT_MARKER,
            (int) $source->id,
            (int) $draft->id,
        ];
        // Zero represents an explicitly empty path, not a missing snapshot.
        $entityMappings[] = [
            CourseAuthoringRevision::PATH_SNAPSHOT,
            (int) ($source->path_id ?? 0),
            (int) $draft->id,
        ];
        $source->teachers()->pluck('users.id')->each(
            fn ($id) => DB::table('course_teacher')->insert([
                'teacher_id' => $id, 'course_id' => $draft->id,
                'created_at' => now(), 'updated_at' => now(),
            ])
        );
        $source->allPhotos()->get()->each(function (Photo $photo) use ($draft): void {
            $copy = $photo->replicate();
            $copy->photoable_type = Course::class;
            $copy->photoable_id = $draft->id;
            $copy->saveQuietly();
        });
        $source->accessPlans()->get()->each(function ($plan) use ($draft): void {
            $copy = $plan->replicate();
            $copy->course_id = $draft->id;
            $copy->saveQuietly();
        });

        $pdfMap = [];
        $source->pdfs()->get()->each(function (CoursePdf $pdf) use ($draft, &$pdfMap): void {
            $copy = $pdf->replicate();
            $copy->course_id = $draft->id;
            $copy->saveQuietly();
            $pdfMap[(int) $pdf->id] = $copy;
        });
        foreach ($pdfMap as $sourceId => $copy) {
            $entityMappings[] = [CoursePdf::class, (int) $sourceId, (int) $copy->id];
        }

        $moduleMap = [];
        $source->modules()->get()->each(function ($module) use (
            $draft,
            &$moduleMap
        ): void {
            $copy = $module->replicate();
            $copy->course_id = $draft->id;
            $copy->saveQuietly();
            $moduleMap[(int) $module->id] = $copy;
        });
        foreach ($moduleMap as $sourceId => $copy) {
            $entityMappings[] = [$copy::class, (int) $sourceId, (int) $copy->id];
        }

        $source->sections()->with('sectionable')->get()->each(function (CourseSection $section) use (
            $draft,
            $moduleMap,
            &$entityMappings
        ): void {
            $content = $this->cloneSectionable($section->sectionable, $draft);
            $copy = $section->replicate();
            $copy->course_id = $draft->id;
            $copy->module_id = $section->module_id ? $moduleMap[(int) $section->module_id]->id : null;
            if ($content) {
                $copy->sectionable_type = $content::class;
                $copy->sectionable_id = $content->getKey();
            }
            $copy->saveQuietly();
            $entityMappings[] = [CourseSection::class, (int) $section->id, (int) $copy->id];
            if (
                $content
                && $section->sectionable
                && $content->getKey() !== $section->sectionable->getKey()
            ) {
                $entityMappings[] = [
                    $section->sectionable::class,
                    (int) $section->sectionable->getKey(),
                    (int) $content->getKey(),
                ];
            }
        });

        return [$draft, $entityMappings];
    }

    private function cloneSectionable(?Model $content, Course $draft): ?Model
    {
        if (!$content) return null;
        if (!$content instanceof Lesson && !$content instanceof Project) {
            throw ValidationException::withMessages([
                'course' => 'يحتوي الكورس عنصرًا قديمًا غير مدعوم. احذفه قبل إنشاء مسودة جديدة.',
            ]);
        }

        $copy = $content->replicate(['authoring_request_id']);
        if ($copy instanceof Lesson) $copy->list_id = $draft->id;
        $copy->saveQuietly();

        if ($content instanceof Lesson) {
            $state = LessonMediaState::query()->where('lesson_id', $content->id)->first();
            if ($state) {
                $stateCopy = $state->replicate();
                $stateCopy->lesson_id = $copy->id;
                $stateCopy->saveQuietly();
            }
        }
        return $copy;
    }

    public function swapWithinTransaction(
        CourseAuthoringRevision $revision,
        Course $canonical,
        Course $archive
    ): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Course revision writes must share the authoring transaction.');
        }

        $liveSections = CourseSection::query()->where('course_id', $canonical->id)->get(['sectionable_type', 'sectionable_id']);
        $draftSections = CourseSection::query()->where('course_id', $archive->id)->get(['sectionable_type', 'sectionable_id']);
        $this->moveOwnedContent($liveSections, (int) $archive->id);
        $this->moveOwnedContent($draftSections, (int) $canonical->id);

        // Plans are commercial identities referenced by immutable receipts and
        // AI ledgers. Publish their editable terms without moving either ID.
        $this->plans->publishWithinTransaction($canonical, $archive);

        // A real FK-backed buffer avoids sentinel IDs that unsigned columns
        // reject while exchanging the owned content graphs.
        $buffer = $canonical->replicate(['authoring_request_id', 'published_at']);
        $buffer->forceFill([
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'is_main_course' => false,
            'authoring_request_id' => null,
        ])->saveQuietly();
        foreach (['course_modules', 'course_sections', 'course_pdfs'] as $table) {
            DB::table($table)->where('course_id', $canonical->id)->update(['course_id' => $buffer->id]);
            DB::table($table)->where('course_id', $archive->id)->update(['course_id' => $canonical->id]);
            DB::table($table)->where('course_id', $buffer->id)->update(['course_id' => $archive->id]);
        }

        $this->mergeClassificationPivot($revision, $canonical, $archive);
        $this->swapPivot('course_teacher', 'teacher_id', $canonical, $archive);
        DB::table('photos')->where('photoable_type', Course::class)
            ->where('photoable_id', $canonical->id)->update(['photoable_id' => $buffer->id]);
        DB::table('photos')->where('photoable_type', Course::class)
            ->where('photoable_id', $archive->id)->update(['photoable_id' => $canonical->id]);
        DB::table('photos')->where('photoable_type', Course::class)
            ->where('photoable_id', $buffer->id)->update(['photoable_id' => $archive->id]);
        $buffer->forceDeleteQuietly();
    }

    private function moveOwnedContent(Collection $sections, int $courseId): void
    {
        $lessons = $sections->where('sectionable_type', Lesson::class)->pluck('sectionable_id');
        if ($lessons->isNotEmpty()) DB::table('lessons')->whereIn('id', $lessons)->update(['list_id' => $courseId]);
    }

    private function swapPivot(string $table, string $relatedColumn, Course $canonical, Course $archive): void
    {
        $live = DB::table($table)->where('course_id', $canonical->id)->pluck($relatedColumn);
        $draft = DB::table($table)->where('course_id', $archive->id)->pluck($relatedColumn);
        DB::table($table)->whereIn('course_id', [$canonical->id, $archive->id])->delete();
        foreach ($draft as $id) DB::table($table)->insert([
            'course_id' => $canonical->id, $relatedColumn => $id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($live as $id) DB::table($table)->insert([
            'course_id' => $archive->id, $relatedColumn => $id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Classification membership has two legitimate editors: the course draft
     * and home-row curation on the live course. Resolve their boolean changes
     * against the clone-time snapshot, while the archive keeps the exact live
     * membership that belonged to the previous published revision.
     */
    private function mergeClassificationPivot(
        CourseAuthoringRevision $revision,
        Course $canonical,
        Course $archive
    ): void {
        $live = DB::table('classification_course')->where('course_id', $canonical->id)
            ->pluck('classification_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $draft = DB::table('classification_course')->where('course_id', $archive->id)
            ->pluck('classification_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $base = DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revision->id)
            ->where('entity_type', CourseAuthoringRevision::CLASSIFICATION_SNAPSHOT)
            ->pluck('source_entity_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $hasSnapshot = DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revision->id)
            ->where('entity_type', CourseAuthoringRevision::CLASSIFICATION_SNAPSHOT_MARKER)
            ->exists();

        // A legacy draft with two different copies has no recoverable base.
        // Stop instead of silently discarding either editor's selection; new
        // drafts and unchanged legacy drafts continue without interruption.
        if (!$hasSnapshot && $live->sort()->values()->all() !== $draft->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'authoring_version' => ["تغيّرت صفوف الكورس منذ بدء هذه المسودة\nأعد فتح المسودة ثم راجع التصنيفات"],
            ])->status(409);
        }

        $merged = $live->merge($draft)->merge($base)->unique()
            ->filter(function (int $id) use ($base, $draft, $live): bool {
                $baseHas = $base->contains($id);
                $draftHas = $draft->contains($id);

                return $draftHas !== $baseHas ? $draftHas : $live->contains($id);
            })->values();

        DB::table('classification_course')->whereIn('course_id', [$canonical->id, $archive->id])->delete();
        $this->insertPivotIds('classification_course', 'classification_id', (int) $canonical->id, $merged);
        $this->insertPivotIds('classification_course', 'classification_id', (int) $archive->id, $live);
    }

    private function insertPivotIds(string $table, string $relatedColumn, int $courseId, Collection $ids): void
    {
        foreach ($ids as $id) DB::table($table)->insert([
            'course_id' => $courseId,
            $relatedColumn => (int) $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
