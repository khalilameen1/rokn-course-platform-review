<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseAuthoringRevision;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\WatchingLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves resume rows written against a superseded course revision onto the
 * current lesson. Publishing records a durable signal that merges every
 * affected learner in bounded batches (afterPublish), outside the course
 * publish lock. The history read keeps ensureCanonical as a fallback for a
 * learner the job has not reached yet; once merged, a read is a pure read.
 */
final class WatchHistoryCanonicalizationService
{
    private const BATCH = 50;

    public function __construct(private readonly CourseRevisionResolver $revisionResolver) {}

    public function ensureCanonical(int $userId, int $maxBatches = 1): void
    {
        $fingerprint = $this->fingerprint($userId);
        if ($fingerprint === null) {
            return;
        }
        $key = 'watch-history:canonical:'.$userId;
        if ($this->remembered($key) === $fingerprint) {
            return;
        }

        // The fingerprint is taken before the merge. A publish or a new resume
        // row that commits while it runs changes the next fingerprint, so the
        // following read repeats the (idempotent) check instead of trusting a
        // marker that predates it.
        for ($batch = 0; $batch < max(1, $maxBatches); $batch++) {
            if ($this->materialize($userId)) {
                $this->remember($key, $fingerprint);

                return;
            }
        }
    }

    /**
     * One bounded step of the post-publish merge: learners with watch rows
     * on this course's lineage, in user-id order after $afterUserId.
     *
     * @return int|null the cursor for the next step, or null when done
     */
    public function afterPublish(int $canonicalCourseId, int $afterUserId, int $learners = 50): ?int
    {
        $courseIds = DB::table('course_authoring_revisions')
            ->where('canonical_course_id', $canonicalCourseId)
            ->where('status', CourseAuthoringRevision::ARCHIVED)
            ->pluck('revision_course_id')
            ->map(static fn ($id): int => (int) $id)
            ->push($canonicalCourseId)
            ->unique()
            ->values();
        $userIds = DB::table('watching_logs')
            ->whereIn('course_id', $courseIds)
            ->where('user_id', '>', $afterUserId)
            ->distinct()
            ->orderBy('user_id')
            ->limit($learners)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id);
        foreach ($userIds as $userId) {
            $this->ensureCanonical($userId, 20);
        }

        return $userIds->count() < $learners ? null : (int) $userIds->last();
    }

    /**
     * Archived revisions are never reverted to drafts, and publishing always
     * touches the archived row. A canonical-pointer change therefore moves the
     * archived count or the latest archive time. The learner's newest resume
     * row covers a pre-publish heartbeat that commits after the merge.
     */
    private function fingerprint(int $userId): ?string
    {
        $archived = fn () => DB::table('course_authoring_revisions')
            ->where('status', CourseAuthoringRevision::ARCHIVED);
        $row = DB::query()
            ->selectSub($archived()->selectRaw('COUNT(*)'), 'archived_revisions')
            ->selectSub($archived()->selectRaw('MAX(updated_at)'), 'archived_at')
            ->selectSub(
                DB::table('watching_logs')->where('user_id', $userId)->selectRaw('MAX(id)'),
                'newest_log_id'
            )
            ->first();
        if (! $row || $row->newest_log_id === null) {
            return null;
        }

        return implode('|', [
            (int) $row->archived_revisions,
            (string) $row->archived_at,
            (int) $row->newest_log_id,
        ]);
    }

    /** @return bool true when no superseded row that can be merged remains */
    private function materialize(int $userId): bool
    {
        $lessonIds = WatchingLog::query()
            ->where('user_id', $userId)
            ->select('lesson_id')
            ->distinct()
            ->pluck('lesson_id');
        if ($lessonIds->isEmpty()) {
            return true;
        }
        $current = $this->revisionResolver->currentLearnerEntityMap(
            Lesson::class,
            $lessonIds
        );
        $staleLessonIds = collect($current)
            ->filter(fn ($target, $source): bool => (int) $target !== (int) $source)
            ->keys();
        if ($staleLessonIds->isEmpty()) {
            return true;
        }
        $logs = WatchingLog::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $staleLessonIds)
            ->orderByDesc('id')
            ->limit(self::BATCH)
            ->get(['id', 'lesson_id']);
        $currentSections = CourseSection::query()
            ->where('sectionable_type', Lesson::class)
            ->whereIn(
                'sectionable_id',
                $staleLessonIds->map(fn ($id): int => (int) $current[(int) $id])
            )
            ->get(['id', 'course_id', 'sectionable_id'])
            ->keyBy('sectionable_id');
        foreach ($logs as $source) {
            $targetLessonId = (int) ($current[(int) $source->lesson_id] ?? $source->lesson_id);
            $targetSection = $currentSections->get($targetLessonId);
            if ($targetLessonId === (int) $source->lesson_id || ! $targetSection) {
                continue;
            }
            DB::transaction(function () use ($source, $targetLessonId, $targetSection, $userId): void {
                $locked = WatchingLog::query()->whereKey($source->id)->lockForUpdate()->first();
                if (! $locked) {
                    return;
                }

                // Another history request may have canonicalized this exact
                // row while we waited. Never treat that now-current row as
                // both source and target and delete it during the merge.
                if ((int) $locked->lesson_id !== (int) $source->lesson_id) {
                    return;
                }

                $sourceTime = $locked->watched_at ?? $locked->updated_at;
                // Recency is always explicit so history can order by an index.
                $sourceWatchedAt = $sourceTime ?? now();
                DB::table('watching_logs')->insertOrIgnore([
                    'user_id' => $userId,
                    'lesson_id' => $targetLessonId,
                    'lesson_name' => $locked->lesson_name,
                    'course_id' => (int) $targetSection->course_id,
                    'course_section_id' => (int) $targetSection->id,
                    'course_name' => $locked->course_name,
                    'position_seconds' => $locked->position_seconds,
                    'duration_seconds' => $locked->duration_seconds,
                    'playback_session_id' => $locked->playback_session_id,
                    'playback_session_started_at' => $locked->playback_session_started_at,
                    'last_playback_sequence' => $locked->last_playback_sequence,
                    'watched_at' => $sourceWatchedAt,
                    'completed_at' => $locked->completed_at,
                    'created_at' => $locked->created_at,
                    'updated_at' => $locked->updated_at,
                ]);
                $target = WatchingLog::query()->where('user_id', $userId)
                    ->where('lesson_id', $targetLessonId)->lockForUpdate()->first();
                if (! $target) {
                    throw new \RuntimeException('Canonical watch history row was not persisted.');
                }
                $targetTime = $target->watched_at ?? $target->updated_at;
                if ($sourceTime && (! $targetTime || $sourceTime->gt($targetTime))) {
                    $target->forceFill([
                        'position_seconds' => $locked->position_seconds,
                        'duration_seconds' => $locked->duration_seconds,
                        'playback_session_id' => $locked->playback_session_id,
                        'playback_session_started_at' => $locked->playback_session_started_at,
                        'last_playback_sequence' => $locked->last_playback_sequence,
                        'watched_at' => $sourceWatchedAt,
                    ]);
                }
                $target->forceFill([
                    'lesson_name' => (string) ($target->lesson_name ?: $locked->lesson_name),
                    'course_id' => (int) $targetSection->course_id,
                    'course_section_id' => (int) $targetSection->id,
                    'course_name' => (string) ($target->course_name ?: $locked->course_name),
                ]);
                if ($target->watched_at === null) {
                    $target->watched_at = $targetTime ?? $sourceWatchedAt;
                }
                if (
                    $locked->completed_at
                    && (! $target->completed_at || $locked->completed_at->lt($target->completed_at))
                ) {
                    $target->completed_at = $locked->completed_at;
                }
                $target->save();
                $locked->delete();
            }, 3);
        }

        return $logs->count() < self::BATCH;
    }

    private function remembered(string $key): ?string
    {
        try {
            $value = Cache::get($key);

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            // Without the marker the merge check simply runs again; it is
            // idempotent and writes nothing once every row is canonical.
            return null;
        }
    }

    private function remember(string $key, string $fingerprint): void
    {
        try {
            Cache::put($key, $fingerprint, now()->addDays(30));
        } catch (Throwable) {
            // Same fallback as remembered(): the next read re-checks.
        }
    }
}
