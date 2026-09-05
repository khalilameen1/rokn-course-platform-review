<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CoursePdf;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\PlaybackSession;
use App\Models\Photo;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Isolates moderator work from the immutable learner-facing revision. */
final class CourseStagedAuthoringService
{
    private const CLASSIFICATION_SNAPSHOT = 'authoring:classification';
    private const CLASSIFICATION_SNAPSHOT_MARKER = 'authoring:classification-snapshot';
    private const HERO_SELECTION_MARKER = 'authoring:hero-selection';

    public function __construct(
        private readonly CoursePublishingService $publishing
    ) {}

    public function draftFor(Course $course): Course
    {
        $owned = CourseAuthoringRevision::query()
            ->where('revision_course_id', $course->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->first();
        if ($owned) return $course;

        // A never-published course is already an isolated draft; cloning it
        // would add identity without protecting any learner-facing state.
        if ($this->isNeverPublishedDraft($course)) return $course;

        return DB::transaction(function () use ($course): Course {
            $canonical = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $active = CourseAuthoringRevision::query()
                ->where('active_slot', $this->slot((int) $canonical->id))
                ->lockForUpdate()->first();
            if ($active) return Course::query()->findOrFail($active->revision_course_id);

            [$draft, $entityMappings] = $this->cloneAggregate($canonical);
            $revision = CourseAuthoringRevision::query()->create([
                'canonical_course_id' => $canonical->id,
                'revision_course_id' => $draft->id,
                'base_authoring_version' => (int) $canonical->authoring_version,
                'status' => CourseAuthoringRevision::DRAFT,
                'active_slot' => $this->slot((int) $canonical->id),
                'clone_key' => (string) Str::uuid(),
            ]);
            foreach ($entityMappings as $mapping) {
                DB::table('course_authoring_revision_entities')->insert([
                    'course_authoring_revision_id' => $revision->id,
                    'entity_type' => $mapping[0],
                    'source_entity_id' => $mapping[1],
                    'revision_entity_id' => $mapping[2],
                ]);
            }

            return $draft;
        }, 3);
    }

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
            ->where('active_slot', $this->slot((int) $canonical->id))
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

    /**
     * Upgrade a legacy draft once its editor explicitly reviews the complete
     * classification selection. The concurrency service already holds the
     * canonical, revision and draft locks when this is called.
     */
    public function confirmClassificationSelection(Course $draft): void
    {
        $revision = CourseAuthoringRevision::query()
            ->where('revision_course_id', $draft->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->first();
        if (!$revision) return;

        $entities = DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revision->id);
        if ((clone $entities)->where('entity_type', self::CLASSIFICATION_SNAPSHOT_MARKER)->exists()) {
            return;
        }

        (clone $entities)->whereIn('entity_type', [
            self::CLASSIFICATION_SNAPSHOT,
            self::CLASSIFICATION_SNAPSHOT_MARKER,
        ])->delete();
        DB::table('classification_course')
            ->where('course_id', $revision->canonical_course_id)
            ->pluck('classification_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->each(fn (int $id) => DB::table('course_authoring_revision_entities')->insert([
                'course_authoring_revision_id' => $revision->id,
                'entity_type' => self::CLASSIFICATION_SNAPSHOT,
                'source_entity_id' => $id,
                'revision_entity_id' => $id,
            ]));
        DB::table('course_authoring_revision_entities')->insert([
            'course_authoring_revision_id' => $revision->id,
            'entity_type' => self::CLASSIFICATION_SNAPSHOT_MARKER,
            'source_entity_id' => (int) $revision->canonical_course_id,
            'revision_entity_id' => (int) $revision->revision_course_id,
        ]);
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
            ->where('entity_type', self::HERO_SELECTION_MARKER)
            ->exists();

        return $explicit ? (bool) $draft->is_main_course : null;
    }

    /** Called under the canonical -> revision -> draft authoring locks. */
    public function confirmHeroSelection(Course $draft): void
    {
        $revision = CourseAuthoringRevision::query()
            ->where('revision_course_id', $draft->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->first(['id', 'canonical_course_id', 'revision_course_id']);
        if (!$revision) return;

        DB::table('course_authoring_revision_entities')->insertOrIgnore([
            'course_authoring_revision_id' => $revision->id,
            'entity_type' => self::HERO_SELECTION_MARKER,
            'source_entity_id' => (int) $revision->canonical_course_id,
            'revision_entity_id' => (int) $revision->revision_course_id,
        ]);
    }

    /** @return array{course:Course,archive:Course,previous_revision:int,published_revision:int} */
    public function publish(
        Course $draft,
        int $expectedVersion,
        bool $catalogVisible,
        bool $grantChatAttachments = false,
        bool $grantProjectAttachments = false
    ): array
    {
        $revisionIdentity = CourseAuthoringRevision::query()
            ->where('revision_course_id', $draft->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->firstOrFail(['id', 'canonical_course_id']);

        return DB::transaction(function () use (
            $draft,
            $revisionIdentity,
            $expectedVersion,
            $catalogVisible,
            $grantChatAttachments,
            $grantProjectAttachments
        ): array {
            // Every staged-authoring path takes the same order:
            // canonical course -> revision slot -> working draft.
            $canonical = Course::query()->whereKey($revisionIdentity->canonical_course_id)
                ->lockForUpdate()->firstOrFail();
            $revision = CourseAuthoringRevision::query()
                ->whereKey($revisionIdentity->id)
                ->lockForUpdate()->firstOrFail();
            if (
                $revision->status !== CourseAuthoringRevision::DRAFT
                || !hash_equals($this->slot((int) $canonical->id), (string) $revision->active_slot)
                || (int) $revision->revision_course_id !== (int) $draft->id
            ) {
                throw ValidationException::withMessages([
                    'authoring_version' => ["نُشرت هذه المسودة بالفعل\nأعد فتح استوديو الكورس"],
                ])->status(409);
            }
            $lockedDraft = Course::query()->whereKey($revision->revision_course_id)
                ->lockForUpdate()->firstOrFail();

            if ((int) $lockedDraft->authoring_version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'authoring_version' => ["تغيّرت المسودة أثناء النشر\nأعد تحميلها ثم راجع آخر تعديل"],
                ])->status(409);
            }
            if ((int) $canonical->authoring_version !== (int) $revision->base_authoring_version) {
                throw ValidationException::withMessages([
                    'authoring_version' => ["تغيّرت النسخة المنشورة منذ بدء المسودة\nابدأ مسودة جديدة ثم راجع التعديلات"],
                ])->status(409);
            }

            $audit = $this->publishing->audit($lockedDraft->fresh());
            if (!$audit['ready']) {
                throw ValidationException::withMessages(['course' => $audit['issues']]);
            }

            $previousRevision = (int) ($canonical->last_published_authoring_version ?? 0);
            $publishedRevision = max(
                (int) $canonical->authoring_version,
                (int) $lockedDraft->authoring_version
            ) + 1;

            $this->swapGraphs($revision, $canonical, $lockedDraft);
            $oldCanonical = $this->editableAttributes($canonical);
            $newCanonical = $this->editableAttributes($lockedDraft);

            $canonical->forceFill(array_merge($newCanonical, [
                'is_coming_soon' => false,
                'is_catalog_visible' => $catalogVisible,
                'authoring_version' => $publishedRevision,
                'last_published_authoring_version' => $publishedRevision,
                'published_at' => now(),
                'authoring_request_id' => null,
            ]))->saveQuietly();
            $this->finalizeLearnerLineage($revision);
            if ($canonical->is_main_course) {
                Course::query()->where('id', '<>', $canonical->id)
                    ->whereNotIn('id', CourseAuthoringRevision::query()->select('revision_course_id'))
                    ->update(['is_main_course' => false]);
            }
            $lockedDraft->forceFill(array_merge($oldCanonical, [
                'is_coming_soon' => true,
                'is_catalog_visible' => false,
                'is_main_course' => false,
                'authoring_request_id' => null,
            ]))->saveQuietly();

            $revision->forceFill([
                'status' => CourseAuthoringRevision::ARCHIVED,
                'active_slot' => null,
                'published_authoring_version' => $publishedRevision,
                'published_at' => now(),
                'retain_until' => now()->addDays(max(7, (int) config('playback.revision_grace_days', 7))),
            ])->save();

            // Persist the notification campaign in the same transaction as
            // the published graph. NotificationCampaignService dispatches its
            // durable row after commit and contains broker failures itself.
            // Preparing it after commit could report this request as failed
            // even though the learner-facing revision was already permanent.
            NotificationService::notifyCourseUpdate(
                $canonical->fresh(),
                'published_changes',
                'course-published:' . $canonical->id . ':v' . $publishedRevision
            );

            $canonicalId = (int) $canonical->id;
            if ($grantChatAttachments || $grantProjectAttachments) {
                app(InternalSignalService::class)->record(
                    'course.attachments.grant',
                    implode(':', [
                        'course', $canonicalId,
                        'revision', $publishedRevision,
                        'chat', (int) $grantChatAttachments,
                        'project', (int) $grantProjectAttachments,
                    ]),
                    [
                        'course_id' => $canonicalId,
                        'published_revision' => $publishedRevision,
                        'chat' => $grantChatAttachments,
                        'project' => $grantProjectAttachments,
                    ],
                    Course::class,
                    $canonicalId
                );
            }
            DB::afterCommit(function (): void {
                try {
                    cache()->add(
                        'courses:catalog-revision',
                        max(1, (int) floor(microtime(true) * 1000)),
                        now()->addYears(10)
                    );
                    cache()->increment('courses:catalog-revision');
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return [
                'course' => $canonical->fresh(),
                'archive' => $lockedDraft->fresh(),
                'previous_revision' => $previousRevision,
                'published_revision' => $publishedRevision,
            ];
        }, 3);
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

    /** @return array{0:Course,1:list<array{0:string,1:int,2:int}>} */
    private function cloneAggregate(Course $source): array
    {
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
                $entityMappings[] = [self::CLASSIFICATION_SNAPSHOT, (int) $id, (int) $id];
            }
        );
        // An empty base is meaningful and must remain distinguishable from a
        // legacy revision created before snapshots were recorded.
        $entityMappings[] = [
            self::CLASSIFICATION_SNAPSHOT_MARKER,
            (int) $source->id,
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
            &$moduleMap,
            &$entityMappings
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

    private function swapGraphs(
        CourseAuthoringRevision $revision,
        Course $canonical,
        Course $archive
    ): void
    {
        $liveSections = CourseSection::query()->where('course_id', $canonical->id)->get(['sectionable_type', 'sectionable_id']);
        $draftSections = CourseSection::query()->where('course_id', $archive->id)->get(['sectionable_type', 'sectionable_id']);
        $this->moveOwnedContent($liveSections, (int) $archive->id);
        $this->moveOwnedContent($draftSections, (int) $canonical->id);

        // A real FK-backed buffer avoids sentinel IDs that unsigned columns
        // reject and releases course+code uniqueness before the draft moves.
        $buffer = $canonical->replicate(['authoring_request_id', 'published_at']);
        $buffer->forceFill([
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'is_main_course' => false,
            'authoring_request_id' => null,
        ])->saveQuietly();
        foreach (['course_modules', 'course_sections', 'course_pdfs', 'course_access_plans'] as $table) {
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

    private function moveOwnedContent($sections, int $courseId): void
    {
        $lessons = $sections->where('sectionable_type', Lesson::class)->pluck('sectionable_id');
        if ($lessons->isNotEmpty()) DB::table('lessons')->whereIn('id', $lessons)->update(['list_id' => $courseId]);
    }

    /**
     * Entity IDs change during an atomic graph swap; learner facts do not.
     * Carry only entities that survived in the validated draft. Deleted
     * sections remain immutable archive evidence and grant nothing new.
     */
    private function finalizeLearnerLineage(CourseAuthoringRevision $revision): void
    {
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
    private function survivingGraphMaps(CourseAuthoringRevision $revision, $mappings): array
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
            ->where('entity_type', self::CLASSIFICATION_SNAPSHOT)
            ->pluck('source_entity_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $hasSnapshot = DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revision->id)
            ->where('entity_type', self::CLASSIFICATION_SNAPSHOT_MARKER)
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

    private function insertPivotIds(string $table, string $relatedColumn, int $courseId, $ids): void
    {
        foreach ($ids as $id) DB::table($table)->insert([
            'course_id' => $courseId,
            $relatedColumn => (int) $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function editableAttributes(Course $course): array
    {
        return collect($course->getAttributes())->except([
            'id', 'created_at', 'updated_at', 'deleted_at', 'authoring_request_id',
        ])->all();
    }

    private function slot(int $courseId): string { return 'course-draft:' . $courseId; }

    private function isNeverPublishedDraft(Course $course): bool
    {
        return (bool) $course->is_coming_soon
            && !(bool) $course->is_catalog_visible
            && $course->published_at === null
            && (int) ($course->last_published_authoring_version ?? 0) < 1;
    }
}
