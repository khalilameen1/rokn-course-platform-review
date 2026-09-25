<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminClassificationAuthoringService
{
    public function __construct(private readonly AdminClassificationReadService $read)
    {
    }

    /** @param array<string, mixed> $data Validated row fields with normalized show_on_home.
     *  @param list<int> $courseIds
     *  @param callable(Classification):void $complete Creation receipt, in the same transaction.
     */
    public function create(array $data, array $courseIds, callable $complete): Classification
    {
        $courseIds = $this->validatedHomeCourseIds($courseIds);
        return DB::transaction(function () use ($data, $courseIds, $complete): Classification {
            $this->lockCoursesForHomeMembership($courseIds);
            $this->validatedHomeCourseIds($courseIds);
            $classification = Classification::query()->create($data);
            $this->syncCanonicalHomeMembership($classification, $courseIds);
            $complete($classification);

            return $classification;
        }, 3);
    }

    /** @param array<string, mixed> $data Validated row fields with normalized show_on_home.
     *  @param list<int> $courseIds
     */
    public function update(Classification $classification, array $data, array $courseIds, string $editorVersion): void
    {
        $courseIds = $this->validatedHomeCourseIds($courseIds);
        // Publishing owns the canonical Course row before it replaces this
        // pivot. Read the current membership first, then take every affected
        // Course lock before the Classification lock. The subsequent version
        // check rejects a membership change that landed between this snapshot
        // and the locks without ever reversing the publish lock order.
        $affectedCourseIds = collect($this->read->canonicalCourseIds($classification))
            ->merge($courseIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        DB::transaction(function () use (
            $classification,
            $data,
            $courseIds,
            $affectedCourseIds,
            $editorVersion
        ): void {
            $this->lockCoursesForHomeMembership($affectedCourseIds);
            // Eligibility may change after request validation while this save
            // waits for a concurrent publish. Never attach a now-hidden course.
            $this->validatedHomeCourseIds($courseIds);
            $locked = Classification::query()->whereKey($classification->id)->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->read->editorVersion($locked), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => "عدّل شخص آخر هذا التصنيف\nأعد تحميل الصفحة قبل الحفظ",
                ]);
            }
            $locked->update($data);
            $this->syncCanonicalHomeMembership($locked, $courseIds);
        }, 3);
    }

    /** Returns false while a canonical course still belongs to this classification. */
    public function delete(Classification $classification): bool
    {
        return DB::transaction(function () use ($classification): bool {
            $courseIds = $classification->courses()->pluck('courses.id')->all();
            $lockedCourseIds = $this->lockCoursesForHomeMembership($courseIds);
            $locked = Classification::query()->whereKey($classification->id)->lockForUpdate()->firstOrFail();
            if ($locked->courses()->whereNotIn('courses.id', $lockedCourseIds)->exists()) {
                throw ValidationException::withMessages([
                    'classification' => "تغيّرت كورسات هذا الصف\nأعد المحاولة",
                ]);
            }
            // Revision snapshots are not editable home membership. Once the
            // real courses are moved, their old snapshots cannot trap this row.
            if ($this->read->canonicalCourseIds($locked) !== []) return false;
            $locked->delete();
            return true;
        }, 3);
    }

    /** @param array<int, mixed> $courseIds @return array<int, int> */
    private function validatedHomeCourseIds(array $courseIds): array
    {
        $ids = collect($courseIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $eligibleIds = $this->read->selectableCourses()->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values();
        if ($eligibleIds->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'course_ids' => 'اختر كورسات ظاهرة في الكتالوج فقط',
            ]);
        }

        return $eligibleIds->all();
    }

    /** @param array<int, int> $courseIds */
    private function syncCanonicalHomeMembership(
        Classification $classification,
        array $courseIds
    ): void {
        $requested = collect($courseIds)->map(fn ($id): int => (int) $id)
            ->unique()->sort()->values();
        // The editor deliberately lists visible catalogue courses only. Sync
        // that visible subset and retain hidden taxonomy membership verbatim.
        $current = collect($this->read->visibleCanonicalCourseIds($classification));
        $detach = $current->diff($requested)->values()->all();
        $attach = $requested->diff($current)->values()->all();

        if ($detach !== []) {
            $classification->courses()->detach($detach);
        }
        if ($attach !== []) {
            $classification->courses()->attach($attach);
        }
    }

    /**
     * Course publishing takes the canonical course lock before it reads and
     * replaces classification_course. Curation takes the same affected course
     * locks first, in a stable order, before it locks the Classification and
     * syncs. This also matches the parent-lock order imposed by pivot FKs, so
     * one operation completes before the other without a deadlock cycle.
     *
     * @param array<int, int> $courseIds
     * @return array<int, int>
     */
    private function lockCoursesForHomeMembership(array $courseIds): array
    {
        $ids = collect($courseIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($ids === []) {
            return [];
        }

        // Deleting an empty row can also detach revision snapshots. Their
        // canonical owners publish first, so take that same parent lock order.
        $ids = collect($ids)->merge(CourseAuthoringRevision::query()
            ->whereIn('revision_course_id', $ids)->pluck('canonical_course_id'))
            ->unique()->sort()->values()->all();

        return Course::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
