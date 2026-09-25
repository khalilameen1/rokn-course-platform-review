<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns revision lifecycle, lock order and the single publication transaction.
 * Content copying/exchange, commercial offer identity and learner continuity
 * have separate owners; none may commit part of this operation independently.
 */
final class CourseStagedAuthoringService
{
    public function __construct(
        private readonly CoursePublishingService $publishing,
        private readonly CourseRevisionGraphService $graphs,
        private readonly CourseRevisionLineageService $lineage,
        private readonly InternalSignalService $signals,
        private readonly CourseCatalogueRevisionService $catalogueRevisions,
        private readonly CoursePathSelectionService $pathSelection
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
                ->where('active_slot', CourseAuthoringRevision::draftSlot((int) $canonical->id))
                ->lockForUpdate()->first();
            if ($active) return Course::query()->findOrFail($active->revision_course_id);

            [$draft, $entityMappings] = $this->graphs->cloneWithinTransaction($canonical);
            $revision = CourseAuthoringRevision::query()->create([
                'canonical_course_id' => $canonical->id,
                'revision_course_id' => $draft->id,
                'base_authoring_version' => (int) $canonical->authoring_version,
                'status' => CourseAuthoringRevision::DRAFT,
                'active_slot' => CourseAuthoringRevision::draftSlot((int) $canonical->id),
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
        if ((clone $entities)->where('entity_type', CourseAuthoringRevision::CLASSIFICATION_SNAPSHOT_MARKER)->exists()) {
            return;
        }

        (clone $entities)->whereIn('entity_type', [
            CourseAuthoringRevision::CLASSIFICATION_SNAPSHOT,
            CourseAuthoringRevision::CLASSIFICATION_SNAPSHOT_MARKER,
        ])->delete();
        DB::table('classification_course')
            ->where('course_id', $revision->canonical_course_id)
            ->pluck('classification_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->each(fn (int $id) => DB::table('course_authoring_revision_entities')->insert([
                'course_authoring_revision_id' => $revision->id,
                'entity_type' => CourseAuthoringRevision::CLASSIFICATION_SNAPSHOT,
                'source_entity_id' => $id,
                'revision_entity_id' => $id,
            ]));
        DB::table('course_authoring_revision_entities')->insert([
            'course_authoring_revision_id' => $revision->id,
            'entity_type' => CourseAuthoringRevision::CLASSIFICATION_SNAPSHOT_MARKER,
            'source_entity_id' => (int) $revision->canonical_course_id,
            'revision_entity_id' => (int) $revision->revision_course_id,
        ]);
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
            'entity_type' => CourseAuthoringRevision::HERO_SELECTION_MARKER,
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
                || !hash_equals(CourseAuthoringRevision::draftSlot((int) $canonical->id), (string) $revision->active_slot)
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

            $publishedPathId = $this->pathSelection->forPublication($revision, $canonical, $lockedDraft);
            $this->graphs->swapWithinTransaction($revision, $canonical, $lockedDraft);
            $oldCanonical = $this->editableAttributes($canonical);
            $newCanonical = $this->editableAttributes($lockedDraft);

            $canonical->forceFill(array_merge($newCanonical, [
                'path_id' => $publishedPathId,
                'is_coming_soon' => false,
                'is_catalog_visible' => $catalogVisible,
                'authoring_version' => $publishedRevision,
                'last_published_authoring_version' => $publishedRevision,
                'published_at' => now(),
                'authoring_request_id' => null,
            ]))->saveQuietly();
            $this->lineage->finalizeWithinTransaction($revision);
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
            CourseContentNotificationService::notifyCourseUpdate(
                course: $canonical->fresh(),
                deliveryKey: 'course-published:' . $canonical->id . ':v' . $publishedRevision
            );

            $canonicalId = (int) $canonical->id;
            if ($grantChatAttachments || $grantProjectAttachments) {
                $this->signals->record(
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
            $this->catalogueRevisions->invalidateAfterCommit(
                static function (\Throwable $exception): void { report($exception); }
            );

            return [
                'course' => $canonical->fresh(),
                'archive' => $lockedDraft->fresh(),
                'previous_revision' => $previousRevision,
                'published_revision' => $publishedRevision,
            ];
        }, 3);
    }

    /** @return array<string,mixed> */
    private function editableAttributes(Course $course): array
    {
        return collect($course->getAttributes())->except([
            'id', 'created_at', 'updated_at', 'deleted_at', 'authoring_request_id',
        ])->all();
    }

    private function isNeverPublishedDraft(Course $course): bool
    {
        return (bool) $course->is_coming_soon
            && !(bool) $course->is_catalog_visible
            && $course->published_at === null
            && (int) ($course->last_published_authoring_version ?? 0) < 1;
    }
}
