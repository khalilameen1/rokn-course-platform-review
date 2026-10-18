<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\User;
use App\Models\WatchingLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The single write path for a playback heartbeat: the playback session
 * sequence, learning evidence, study reward, section completion and the
 * resume row. Lock order is the learner row first (shared with account
 * deletion and wallet rewards), then the playback session, then the resume
 * row; completion takes learner then shared course in its own transaction.
 */
final readonly class WatchHistoryRecorder
{
    public const REVISION_CHANGED = 'revision_changed';

    public const LESSON_UNAVAILABLE = 'lesson_unavailable';

    public const ACCESS_REQUIRED = 'access_required';

    public const VIDEO_METADATA_UNAVAILABLE = 'video_metadata_unavailable';

    public const INVALID_SESSION = 'invalid_session';

    public const PREVIEW = 'preview';

    public const UNTRACKED = 'untracked';

    public const RECORDED = 'recorded';

    public function __construct(
        private LearningEvidenceService $learningEvidence,
        private CourseEntitlementService $courseAccess,
        private CourseRevisionResolver $revisionResolver,
        private LearningRewardService $rewards,
        private PlaybackSessionService $sessions,
        private CourseCompletionService $completion
    ) {}

    /**
     * @param  array<string, mixed>  $sample  the validated heartbeat
     * @return array{state: string, data: array<string, mixed>|null}
     */
    public function record(User $user, array $sample): array
    {
        $lesson = Lesson::query()
            ->with('mediaState:id,lesson_id,duration_seconds')
            ->whereKey($sample['lesson_id'])
            ->first();
        if (! $lesson) {
            // The same 422 payload the exists rule produced, now evaluated
            // only on this failure path. Should the row appear meanwhile,
            // answer exactly as findOrFail did.
            Validator::make($sample, ['lesson_id' => 'exists:lessons,id'])->validate();
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$sample['lesson_id']]);
        }
        // sections_count lets isPublishedForLearning() answer from this row
        // instead of issuing its own EXISTS query.
        $course = $lesson->course()
            ->select(['id', 'name_ar', 'name_en', 'is_coming_soon'])
            ->withCount('sections')
            ->first();
        $section = $lesson->courseSection()->first();
        $lesson->setRelation('course', $course)->setRelation('courseSection', $section);
        $courseId = (int) $lesson->list_id;
        $archiveRevision = $course && $course->is_coming_soon
            ? $this->revisionResolver->activeArchiveForCourse($course)
            : null;
        $archiveContinuation = $archiveRevision
            ? $this->revisionResolver->archivedPlaybackContinuation(
                $user,
                $lesson,
                $sample['playback_session_id'] ?? null
            )
            : null;
        if ($archiveRevision && ! $archiveContinuation) {
            $canonical = $archiveRevision->canonicalCourse()->firstOrFail();

            return ['state' => self::REVISION_CHANGED, 'data' => [
                'course_id' => (int) $canonical->id,
                'published_revision' => (int) (
                    $canonical->last_published_authoring_version ?: $canonical->authoring_version
                ),
                'reload_endpoint' => "/api/v1/courses/{$canonical->id}/details",
            ]];
        }
        if ($archiveContinuation) {
            $courseId = (int) $archiveContinuation['canonical_course']->id;
        }

        if (
            ! $course
            || ! $section
            || (! $course->isPublishedForLearning() && ! $archiveContinuation)
            || (int) $section->course_id !== (int) $lesson->list_id
            || $section->getSectionType() !== 'lesson'
            || (int) $section->sectionable_id !== (int) $lesson->id
        ) {
            return ['state' => self::LESSON_UNAVAILABLE, 'data' => null];
        }

        $isPublicPreview = (bool) $lesson->is_opened;
        $hasLearningAccess = $this->courseAccess->hasLearningAccess(
            (int) $user->id,
            $courseId
        );
        // Publish grace preserves an already-open media generation, not a
        // revoked purchase. Re-check the canonical entitlement on every
        // heartbeat so an archived session cannot outlive access removal.
        if (! $isPublicPreview && ! $hasLearningAccess) {
            return ['state' => self::ACCESS_REQUIRED, 'data' => null];
        }

        $providerDuration = max(0, (int) ($lesson->mediaState->duration_seconds ?? 0));
        // The reconciled media row is the sole duration authority. Accepting
        // the player's duration when that row is incomplete creates a second
        // completion clock and lets two devices disagree about the same reel.
        if ($providerDuration <= 0) {
            return ['state' => self::VIDEO_METADATA_UNAVAILABLE, 'data' => null];
        }
        $duration = $providerDuration;
        $position = min((int) ($sample['position_seconds'] ?? 0), $duration);

        $playbackState = $this->acceptSample(
            $user,
            $lesson,
            $sample,
            $position,
            $duration,
            $courseId,
            $hasLearningAccess
        );
        $sessionResult = $playbackState['sessionResult'];
        $duplicate = false;
        if (! $sessionResult['accepted']) {
            if ($sessionResult['reason'] === 'invalid_session') {
                return ['state' => self::INVALID_SESSION, 'data' => [
                    'recorded' => false,
                    'duplicate' => false,
                    'reason' => $sessionResult['reason'],
                    'credited_seconds' => 0,
                ]];
            }
            $duplicate = true;
        }

        // Preview playback has its own operational session, but it is not an
        // enrollment fact. It must not create watch history, academic
        // evidence, study rewards, or a "continue course" card.
        if (! $hasLearningAccess) {
            return ['state' => self::PREVIEW, 'data' => [
                'recorded' => false,
                'duplicate' => $duplicate,
                'reason' => $duplicate ? $sessionResult['reason'] : null,
                'preview' => true,
                'tracking_enabled' => false,
                'credited_seconds' => 0,
                'learning_evidence' => null,
                'reward' => null,
            ]];
        }

        // Academic evidence is deliberately separate from optional watch
        // history. Disabling the user's resume list never disables progression,
        // and the server credits only time-compatible heartbeats.
        $evidence = $playbackState['evidence']
            ?? $this->learningEvidence->evidenceFor($user, $lesson);
        $reward = $playbackState['reward'];
        $currentLesson = $archiveContinuation['current_lesson'] ?? null;
        $currentEvidence = $currentLesson
            ? $this->learningEvidence->carryCompletedRevisionForward(
                $user,
                $lesson,
                $currentLesson,
                $evidence
            )
            : null;
        $completionResult = null;
        $completionEvidence = $currentEvidence ?? $evidence;
        $completionSectionId = (int) (
            $archiveContinuation['current_section']->id
            ?? $section->id
        );
        if ((bool) ($completionEvidence['eligible_for_completion'] ?? false)) {
            try {
                $completionResult = $this->completion->complete(
                    $user,
                    $courseId,
                    $completionSectionId
                );
            } catch (\Throwable $exception) {
                // Evidence is already durable and the explicit idempotent
                // completion endpoint remains a recovery path.
                report($exception);
            }
        }

        if (! $user->watch_history_enabled) {
            return ['state' => self::UNTRACKED, 'data' => [
                'recorded' => false,
                'duplicate' => $duplicate,
                'reason' => $duplicate ? $sessionResult['reason'] : null,
                'tracking_enabled' => false,
                'learning_evidence' => $evidence,
                'current_learning_evidence' => $currentEvidence,
                'course_revision_changed' => $archiveContinuation !== null,
                'course_id' => $courseId,
                'current_lesson_id' => $currentLesson?->id,
                'current_section_id' => $archiveContinuation['current_section']->id ?? null,
                'section_completion' => $completionResult,
                'reward' => $reward,
            ]];
        }

        $acceptedSession = $sessionResult['session'] ?? null;
        if ($duplicate && $acceptedSession) {
            $position = (int) $acceptedSession->last_position_seconds;
            $duration = max(1, (int) ($acceptedSession->duration_seconds ?? $duration));
        }
        $resumeLesson = $currentLesson ?? $lesson;
        $courseName = (string) (
            ($archiveContinuation['canonical_course'] ?? $course)->name_ar
            ?? ($archiveContinuation['canonical_course'] ?? $course)->name_en
            ?? ''
        );

        [$log, $resumeRecorded] = $this->writeResume(
            $user,
            $resumeLesson,
            $resumeLesson->getRelationValue('courseSection')?->id,
            $courseId,
            $courseName,
            $position,
            $duration,
            $acceptedSession?->id,
            $acceptedSession?->started_at,
            $acceptedSession->last_heartbeat_at ?? $acceptedSession?->started_at,
            $duplicate && $acceptedSession
                ? (int) $acceptedSession->last_sequence
                : (int) $sample['sequence'],
            $evidence
        );

        // The response exposes the exact server-qualified evidence and reward
        // committed for this accepted sample, or the durable snapshot when
        // this was a replay.
        return ['state' => self::RECORDED, 'data' => [
            'recorded' => $resumeRecorded,
            'duplicate' => $duplicate,
            'reason' => $duplicate ? $sessionResult['reason'] : null,
            'resume_ignored_as_stale_session' => ! $resumeRecorded,
            'tracking_enabled' => true,
            'history_id' => $log->id,
            'course_id' => $courseId,
            'lesson_id' => $lesson->id,
            'course_section_id' => $log->course_section_id,
            'position_seconds' => $log->position_seconds,
            'duration_seconds' => $log->duration_seconds,
            'watched_at' => $log->watched_at,
            'learning_evidence' => $evidence,
            'current_learning_evidence' => $currentEvidence,
            'course_revision_changed' => $archiveContinuation !== null,
            'current_lesson_id' => $currentLesson?->id,
            'current_section_id' => $archiveContinuation['current_section']->id ?? null,
            'section_completion' => $completionResult,
            'reward' => $reward,
        ]];
    }

    /**
     * Advancing the session sequence and crediting the same sample are one
     * durable transition. If evidence persistence fails, rolling both back
     * lets the player retry this sequence instead of losing watched time to a
     * session row that already consumed it.
     *
     * @param  array<string, mixed>  $sample
     * @return array{sessionResult: array<string, mixed>, evidence: array<string, mixed>|null, reward: array<string, mixed>|null}
     */
    private function acceptSample(
        User $user,
        Lesson $lesson,
        array $sample,
        int $position,
        int $duration,
        int $courseId,
        bool $hasLearningAccess
    ): array {
        return DB::transaction(function () use (
            $user,
            $lesson,
            $sample,
            $position,
            $duration,
            $courseId,
            $hasLearningAccess
        ): array {
            // Account deletion and wallet rewards own this same aggregate
            // lock. Taking it first prevents a heartbeat from recreating
            // progress or rewards while that account is being removed.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $sessionResult = $this->sessions->accept($user, (int) $lesson->id, $sample + [
                'position_seconds' => $position,
                'duration_seconds' => $duration,
            ]);
            $evidence = null;
            $reward = null;
            if ($sessionResult['accepted'] && $hasLearningAccess) {
                $evidence = ($sessionResult['trusted_evidence'] ?? false)
                    ? $this->learningEvidence->recordHeartbeat(
                        $user,
                        $lesson,
                        $position,
                        $duration,
                        $sessionResult['previous_sample'] ?? null,
                        $courseId
                    )
                    : [
                        'evidence_id' => null,
                        'verified_seconds' => 0,
                        'required_seconds' => null,
                        'credited_seconds' => 0,
                        'eligible_for_completion' => false,
                    ];
                $reward = $this->rewards->recordStudy(
                    $user,
                    (int) $evidence['credited_seconds']
                );
            }

            return compact('sessionResult', 'evidence', 'reward');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{0: WatchingLog, 1: bool}
     */
    private function writeResume(
        User $user,
        Lesson $resumeLesson,
        ?int $resumeSectionId,
        int $courseId,
        string $courseName,
        int $position,
        int $duration,
        mixed $incomingSessionId,
        mixed $incomingSessionStartedAt,
        mixed $incomingObservedAt,
        int $incomingSequence,
        array $evidence
    ): array {
        return DB::transaction(function () use (
            $user,
            $resumeLesson,
            $resumeSectionId,
            $courseId,
            $courseName,
            $position,
            $duration,
            $incomingSessionId,
            $incomingSessionStartedAt,
            $incomingObservedAt,
            $incomingSequence,
            $evidence
        ): array {
            $resumeRecorded = true;
            // Steady-state heartbeats find their row on the unique
            // (user_id, lesson_id) key, so the locking read takes a record
            // lock and no insert is attempted. Only a learner's first sample
            // for a lesson pays for insert-or-ignore, which still closes the
            // first-watch race (same shape as LearningEvidenceService).
            $created = false;
            $log = WatchingLog::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $resumeLesson->id)
                ->lockForUpdate()
                ->first();
            if (! $log) {
                $created = DB::table('watching_logs')->insertOrIgnore([
                    'user_id' => $user->id,
                    'lesson_id' => $resumeLesson->id,
                    'lesson_name' => (string) $resumeLesson->title,
                    'course_id' => $courseId,
                    'course_section_id' => $resumeSectionId,
                    'course_name' => $courseName,
                    'position_seconds' => 0,
                    'duration_seconds' => null,
                    'watched_at' => $incomingObservedAt ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]) > 0;

                $log = WatchingLog::query()
                    ->where('user_id', $user->id)
                    ->where('lesson_id', $resumeLesson->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $storedObservedAt = $log->watched_at ?? $log->updated_at;
            if (! $created && $incomingSessionId && $incomingObservedAt && $storedObservedAt) {
                $incomingTime = $incomingObservedAt->format('Y-m-d H:i:s.u');
                $storedTime = $storedObservedAt->format('Y-m-d H:i:s.u');
                $sameSession = (string) $log->playback_session_id === (string) $incomingSessionId;
                $timeOrder = strcmp($incomingTime, $storedTime);
                if ($timeOrder < 0) {
                    $resumeRecorded = false;
                } elseif (
                    $timeOrder === 0
                    && $sameSession
                    && $log->last_playback_sequence !== null
                    && $incomingSequence <= (int) $log->last_playback_sequence
                ) {
                    $resumeRecorded = false;
                } elseif (
                    $timeOrder === 0
                    && ! $sameSession
                    && strcmp((string) $incomingSessionId, (string) $log->playback_session_id) < 0
                ) {
                    // SQL timestamps may be second-precision. UUID order is
                    // only a deterministic tie-break for two genuinely
                    // simultaneous devices; it is never treated as time.
                    $resumeRecorded = false;
                }
            }

            if ($resumeRecorded) {
                $resumeAttributes = [
                    'lesson_name' => (string) $resumeLesson->title,
                    'course_id' => $courseId,
                    'course_section_id' => $resumeSectionId,
                    'course_name' => $courseName,
                    'position_seconds' => $position,
                    'duration_seconds' => $duration,
                    'watched_at' => $incomingObservedAt ?? now(),
                ];
                if ($incomingSessionId && $incomingSessionStartedAt) {
                    $resumeAttributes += [
                        'playback_session_id' => $incomingSessionId,
                        'playback_session_started_at' => $incomingSessionStartedAt,
                        'last_playback_sequence' => $incomingSequence,
                    ];
                }
                $ownedBySameSession = ! $created
                    && $incomingSessionId
                    && $log->watched_at !== null
                    && (string) $log->playback_session_id === (string) $incomingSessionId;
                $log->fill($resumeAttributes);

                // A newer sample from the player that already owns this row,
                // at the same position, only moves its clock and sequence.
                // No other device wrote in between (the session would differ),
                // so there is no resume state to persist. The playback session
                // and learning evidence still advance their own clocks; this
                // row is resume UX, not evidence.
                $clockOnly = array_diff(
                    array_keys($log->getDirty()),
                    ['watched_at', 'last_playback_sequence']
                ) === [];
                if ($ownedBySameSession && $clockOnly) {
                    $log->discardChanges();
                }
            }

            if (
                (bool) ($evidence['eligible_for_completion'] ?? false)
                && $log->completed_at === null
            ) {
                $log->completed_at = now();
            }

            $log->save();

            return [$log, $resumeRecorded];
        }, 3);
    }
}
