<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonWatchEvidence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class LearningEvidenceService
{
    public function __construct(private CourseRevisionLearnerReadService $revisionReads) {}

    /** Verified progress is bounded by elapsed server time and playback rate. */
    public function recordHeartbeat(
        User $user,
        Lesson $lesson,
        int $positionSeconds,
        ?int $clientDurationSeconds,
        ?array $previousPlaybackSample = null
    ): array {
        $sectionId = $lesson->courseSection?->id;
        if (!$sectionId) {
            return $this->emptyResult();
        }

        return DB::transaction(function () use (
            $user,
            $lesson,
            $sectionId,
            $positionSeconds,
            $clientDurationSeconds,
            $previousPlaybackSample
        ): array {
            $evidence = LessonWatchEvidence::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->first();
            $isNew = false;
            if (!$evidence) {
                $inserted = DB::table('lesson_watch_evidence')->insertOrIgnore([
                    'user_id' => $user->id,
                    'lesson_id' => $lesson->id,
                    'course_section_id' => $sectionId,
                    'duration_seconds' => null,
                    'verified_seconds' => 0,
                    'last_position_seconds' => max(0, $positionSeconds),
                    'last_heartbeat_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $isNew = $inserted > 0;
                $evidence = LessonWatchEvidence::query()
                    ->where('user_id', $user->id)
                    ->where('lesson_id', $lesson->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $trustedDuration = $this->trustedDurationSeconds($lesson);
            $observedDuration = max(
                0,
                (int) ($evidence?->duration_seconds ?? 0),
                (int) ($clientDurationSeconds ?? 0),
                (int) ($trustedDuration ?? 0)
            ) ?: null;
            $now = now();
            $credited = 0;

            if ($isNew) {
                $evidence->duration_seconds = $observedDuration;
                $evidence->last_position_seconds = max(0, $positionSeconds);
                $evidence->last_heartbeat_at = $now;
            } else {
                $previousPosition = $previousPlaybackSample !== null
                    ? (int) ($previousPlaybackSample['position_seconds'] ?? 0)
                    : (int) $evidence->last_position_seconds;
                $previousHeartbeat = $previousPlaybackSample !== null
                    ? ($previousPlaybackSample['recorded_at'] ?? null)
                    : $evidence->last_heartbeat_at;
                $sessionElapsed = $previousHeartbeat
                    ? (int) floor($previousHeartbeat->diffInSeconds($now, true))
                    : 0;
                // One learner may have the same reel open on two devices. A
                // session sequence prevents replay inside one player, while
                // this aggregate clock prevents overlapping players from both
                // crediting the same wall-clock interval and its rewards.
                $aggregateElapsed = $evidence->last_heartbeat_at
                    ? (int) floor($evidence->last_heartbeat_at->diffInSeconds($now, true))
                    : 0;
                $elapsed = $previousPlaybackSample !== null
                    ? min($sessionElapsed, $aggregateElapsed)
                    : $aggregateElapsed;
                $positionDelta = max(0, $positionSeconds - $previousPosition);
                $maxGap = max(10, (int) config('learning_evidence.maximum_heartbeat_gap_seconds', 45));
                $maxRate = max(1.0, min(2.5, (float) config('learning_evidence.maximum_playback_rate', 2.0)));
                $maxCredit = max(5, (int) config('learning_evidence.maximum_credit_per_heartbeat', 30));

                if ($elapsed >= 1 && $elapsed <= $maxGap && $positionDelta > 0) {
                    $credited = min(
                        $positionDelta,
                        (int) floor($elapsed * $maxRate),
                        $maxCredit
                    );
                }

                $evidence->duration_seconds = $observedDuration;
                $evidence->verified_seconds = (int) $evidence->verified_seconds + $credited;
                $evidence->last_position_seconds = max(0, $positionSeconds);
                $evidence->last_heartbeat_at = $now;
            }

            $required = $this->requiredSeconds($lesson, $observedDuration);
            if ($required !== null && (int) $evidence->verified_seconds >= $required && !$evidence->completed_at) {
                $evidence->completed_at = $now;
            }
            $evidence->save();

            return [
                'evidence_id' => $evidence->id,
                'verified_seconds' => (int) $evidence->verified_seconds,
                'required_seconds' => $required,
                'credited_seconds' => $credited,
                'eligible_for_completion' => $required !== null
                    && (int) $evidence->verified_seconds >= $required,
            ];
        });
    }

    public function evidenceFor(User $user, Lesson $lesson): array
    {
        $evidence = $this->revisionReads->lessonEvidence((int) $user->id, (int) $lesson->id);
        $required = $this->requiredSeconds($lesson, $evidence?->duration_seconds);

        return [
            'evidence_id' => $evidence?->id,
            'verified_seconds' => (int) ($evidence?->verified_seconds ?? 0),
            'required_seconds' => $required,
            'credited_seconds' => 0,
            'eligible_for_completion' => $required !== null && $evidence !== null
                && (int) $evidence->verified_seconds >= $required,
        ];
    }

    /**
     * A learner who finishes media allocated before an atomic course publish
     * keeps that verified result on the corresponding current lesson. This is
     * not a generic evidence copier: only server-qualified completed evidence
     * reaches this boundary.
     */
    public function carryCompletedRevisionForward(
        User $user,
        Lesson $sourceLesson,
        Lesson $currentLesson,
        array $sourceEvidence
    ): ?array {
        if (!(bool) ($sourceEvidence['eligible_for_completion'] ?? false)) return null;
        $currentSectionId = (int) ($currentLesson->courseSection?->id ?? 0);
        $required = $this->requiredSeconds($currentLesson);
        if ($currentSectionId < 1 || $required === null) return null;

        return DB::transaction(function () use (
            $user,
            $currentLesson,
            $currentSectionId,
            $required,
            $sourceEvidence
        ): array {
            DB::table('lesson_watch_evidence')->insertOrIgnore([
                'user_id' => $user->id,
                'lesson_id' => $currentLesson->id,
                'course_section_id' => $currentSectionId,
                'duration_seconds' => $this->trustedDurationSeconds($currentLesson),
                'verified_seconds' => 0,
                'last_position_seconds' => 0,
                'last_heartbeat_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $target = LessonWatchEvidence::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $currentLesson->id)
                ->lockForUpdate()
                ->firstOrFail();
            $target->forceFill([
                'course_section_id' => $currentSectionId,
                'duration_seconds' => max(
                    (int) ($target->duration_seconds ?? 0),
                    (int) ($this->trustedDurationSeconds($currentLesson) ?? 0)
                ) ?: null,
                // Meeting the old published lesson's verified threshold is a
                // grandfathered completion fact, not client-supplied time.
                'verified_seconds' => max((int) $target->verified_seconds, $required),
                'last_position_seconds' => max(
                    (int) $target->last_position_seconds,
                    (int) ($sourceEvidence['required_seconds'] ?? 0)
                ),
                'last_heartbeat_at' => now(),
                'completed_at' => $target->completed_at ?? now(),
            ])->save();

            return [
                'evidence_id' => $target->id,
                'verified_seconds' => (int) $target->verified_seconds,
                'required_seconds' => $required,
                'credited_seconds' => 0,
                'eligible_for_completion' => true,
            ];
        }, 3);
    }

    public function requiredSeconds(Lesson $lesson, ?int $observedDuration = null): ?int
    {
        $minimum = max(10, (int) config('learning_evidence.minimum_verified_seconds', 20));
        $trustedDuration = $this->trustedDurationSeconds($lesson);
        // Only server-owned duration can authorize completion.
        if ($trustedDuration === null) {
            return null;
        }
        $duration = $trustedDuration;
        $fraction = max(0.5, min(0.95, (float) config('learning_evidence.required_fraction', 0.80)));

        // Short videos use the same completion fraction without the floor.
        if ($duration > 0 && $duration < $minimum) {
            return max(1, (int) ceil($duration * $fraction));
        }

        return max($minimum, (int) ceil($duration * $fraction));
    }

    private function trustedDurationSeconds(Lesson $lesson): ?int
    {
        $mediaDuration = (int) (
            $lesson->relationLoaded('mediaState')
                ? $lesson->mediaState?->duration_seconds
                : $lesson->mediaState()->value('duration_seconds')
        );
        if ($mediaDuration > 0) {
            return $mediaDuration;
        }

        return null;
    }

    private function emptyResult(): array
    {
        return [
            'evidence_id' => null,
            'verified_seconds' => 0,
            'required_seconds' => 0,
            'credited_seconds' => 0,
            'eligible_for_completion' => false,
        ];
    }
}
