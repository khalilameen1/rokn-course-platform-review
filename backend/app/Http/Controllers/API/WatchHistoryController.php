<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\User;
use App\Models\WatchingLog;
use App\Services\CourseChatAccessService;
use App\Services\CourseCompletionService;
use App\Services\CourseStagedAuthoringService;
use App\Services\BunnyService;
use App\Services\LearningEvidenceService;
use App\Services\LearningRewardService;
use App\Services\PlaybackCapabilityService;
use App\Services\PlaybackSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WatchHistoryController extends Controller
{
    public function __construct(
        private readonly LearningEvidenceService $learningEvidence,
        private readonly CourseChatAccessService $courseAccess,
        private readonly CourseStagedAuthoringService $stagedAuthoring,
        private readonly BunnyService $bunny
    ) {
    }

    /**
     * Return a bounded, resume-oriented history. Video URLs are deliberately
     * excluded so this endpoint cannot bypass the normal lesson access path.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        $user = auth('api')->user();
        $this->materializeWatchHistory((int) $user->id);
        $history = WatchingLog::query()
            ->where('user_id', $user->id)
            ->whereHas('course', function ($courses): void {
                $courses->where('is_coming_soon', false)
                    ->whereHas('sections');
            })
            ->whereHas('lesson.courseSection', function ($sections): void {
                $sections->whereColumn('course_sections.course_id', 'watching_logs.course_id');
            })
            ->when(isset($validated['course_id']), function ($query) use ($validated) {
                $query->where('course_id', $validated['course_id']);
            })
            ->with([
                'lesson:id,list_id,title,title_ar,title_en,thumbnail_path,duration_minutes',
                'lesson.mediaState:id,lesson_id,duration_seconds',
                'course:id,name_ar,name_en,image',
                'courseSection:id,course_id,module_id,sectionable_type,sectionable_id,order,title,title_ar,title_en',
            ])
            ->orderByRaw('COALESCE(watched_at, updated_at) DESC')
            ->orderByDesc('watching_logs.id')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل سجل المشاهدة',
            'data' => [
                'tracking_enabled' => (bool) $user->watch_history_enabled,
                'items' => collect($history->items())->map(function (WatchingLog $log) {
                    $providerDuration = max(
                        0,
                        (int) ($log->lesson?->mediaState?->duration_seconds ?? 0)
                    );
                    $duration = $providerDuration > 0
                        ? $providerDuration
                        : max(0, (int) ($log->duration_seconds ?? 0));
                    $progress = $duration && $duration > 0
                        ? min(100, round(($log->position_seconds / $duration) * 100, 2))
                        : null;
                    $thumbnailPath = trim((string) $log->lesson?->thumbnail_path);
                    $thumbnail = $thumbnailPath !== ''
                        ? $this->bunny->generateBunnySignedUrl($thumbnailPath)
                        : null;

                    return [
                        'id' => $log->id,
                        'course_id' => $log->course_id,
                        'course_title' => $log->course?->name_ar ?? $log->course_name,
                        'course_title_en' => $log->course?->name_en,
                        'course_image' => $log->course?->image,
                        'lesson_id' => $log->lesson_id,
                        'lesson_title' => $log->lesson?->title ?? $log->lesson_name,
                        'lesson_thumbnail' => $thumbnail ?: $log->course?->image,
                        'course_section_id' => $log->course_section_id,
                        'section_order' => $log->courseSection?->order,
                        'position_seconds' => $log->position_seconds,
                        'duration_seconds' => $duration,
                        'progress_percentage' => $progress,
                        'is_completed' => $log->completed_at !== null,
                        'watched_at' => $log->watched_at ?? $log->updated_at,
                    ];
                })->values(),
                'pagination' => [
                    'current_page' => $history->currentPage(),
                    'last_page' => $history->lastPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total(),
                ],
            ],
        ]);
    }

    /** Canonicalize resume pointers lazily, outside the course publish lock. */
    private function materializeWatchHistory(int $userId): void
    {
        $lessonIds = WatchingLog::query()
            ->where('user_id', $userId)
            ->select('lesson_id')
            ->distinct()
            ->pluck('lesson_id');
        if ($lessonIds->isEmpty()) return;
        $current = $this->stagedAuthoring->currentLearnerEntityMap(
            Lesson::class,
            $lessonIds
        );
        $staleLessonIds = collect($current)
            ->filter(fn ($target, $source): bool => (int) $target !== (int) $source)
            ->keys();
        if ($staleLessonIds->isEmpty()) return;
        $logs = WatchingLog::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $staleLessonIds)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'lesson_id', 'course_id', 'course_section_id', 'position_seconds',
                'duration_seconds', 'playback_session_id', 'playback_session_started_at',
                'last_playback_sequence', 'watched_at', 'completed_at', 'created_at', 'updated_at']);
        $currentSections = \App\Models\CourseSection::query()
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
            if ($targetLessonId === (int) $source->lesson_id || !$targetSection) continue;
            DB::transaction(function () use ($source, $targetLessonId, $targetSection, $userId): void {
                $locked = WatchingLog::query()->whereKey($source->id)->lockForUpdate()->first();
                if (!$locked) return;

                // Another history request may have canonicalized this exact
                // row while we waited. Never treat that now-current row as
                // both source and target and delete it during the merge.
                if ((int) $locked->lesson_id !== (int) $source->lesson_id) return;

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
                    'watched_at' => $locked->watched_at,
                    'completed_at' => $locked->completed_at,
                    'created_at' => $locked->created_at,
                    'updated_at' => $locked->updated_at,
                ]);
                $target = WatchingLog::query()->where('user_id', $userId)
                    ->where('lesson_id', $targetLessonId)->lockForUpdate()->first();
                if (!$target) throw new \RuntimeException('Canonical watch history row was not persisted.');
                $sourceTime = $locked->watched_at ?? $locked->updated_at;
                $targetTime = $target->watched_at ?? $target->updated_at;
                if ($sourceTime && (!$targetTime || $sourceTime->gt($targetTime))) {
                    $target->forceFill([
                        'position_seconds' => $locked->position_seconds,
                        'duration_seconds' => $locked->duration_seconds,
                        'playback_session_id' => $locked->playback_session_id,
                        'playback_session_started_at' => $locked->playback_session_started_at,
                        'last_playback_sequence' => $locked->last_playback_sequence,
                        'watched_at' => $locked->watched_at,
                    ]);
                }
                $target->forceFill([
                    'lesson_name' => (string) ($target->lesson_name ?: $locked->lesson_name),
                    'course_id' => (int) $targetSection->course_id,
                    'course_section_id' => (int) $targetSection->id,
                    'course_name' => (string) ($target->course_name ?: $locked->course_name),
                ]);
                if (
                    $locked->completed_at
                    && (!$target->completed_at || $locked->completed_at->lt($target->completed_at))
                ) {
                    $target->completed_at = $locked->completed_at;
                }
                $target->save();
                $locked->delete();
            }, 3);
        }
    }

    /** Record a verified playback sample and advance durable learning state. */
    public function store(
        Request $request,
        LearningRewardService $rewards,
        PlaybackSessionService $sessions,
        CourseCompletionService $completion
    ): JsonResponse
    {
        $validated = $request->validate([
            'lesson_id' => 'required|integer|exists:lessons,id',
            'position_seconds' => 'nullable|integer|min:0|max:86400',
            'duration_seconds' => 'nullable|integer|min:1|max:86400',
            'is_completed' => 'nullable|boolean',
            'playback_session_id' => 'required|uuid',
            'sequence' => 'required|integer|min:1|max:2147483647',
            'event_type' => 'nullable|string|in:play,start,heartbeat,pause,background,stop,complete,error',
            'end_reason' => 'nullable|string|in:user_exit,navigation,lesson_changed,'
                . 'app_closed,playback_error,source_expired,replaced,unknown',
            'is_terminal' => 'nullable|boolean',
            'effective_quality' => 'nullable|string|in:auto,1080p,720p,480p,360p',
            'effective_bitrate_kbps' => 'nullable|integer|min:0|max:100000',
            'playback_rate' => 'nullable|numeric|min:0.5|max:2',
            'recovery_count' => 'nullable|integer|min:0|max:20',
            'startup_latency_ms' => 'nullable|integer|min:0|max:120000',
            'buffer_count' => 'nullable|integer|min:0|max:500',
            'buffer_duration_ms' => 'nullable|integer|min:0|max:3600000',
            'error_code' => 'nullable|string|max:64',
            'diagnostics' => 'nullable|array|max:12',
        ] + PlaybackCapabilityService::validationRules());

        $user = auth('api')->user();

        $lesson = Lesson::with([
            'course:id,name_ar,name_en,is_coming_soon',
            'courseSection',
            'mediaState:id,lesson_id,duration_seconds',
        ])->findOrFail($validated['lesson_id']);
        $courseId = (int) $lesson->list_id;
        $course = $lesson->course;
        $section = $lesson->courseSection;
        $archiveRevision = $course && $course->is_coming_soon
            ? $this->stagedAuthoring->activeArchiveForCourse($course)
            : null;
        $archiveContinuation = $archiveRevision
            ? $this->stagedAuthoring->archivedPlaybackContinuation(
                $user,
                $lesson,
                $validated['playback_session_id'] ?? null
            )
            : null;
        if ($archiveRevision && !$archiveContinuation) {
            $canonical = $archiveRevision->canonicalCourse()->firstOrFail();
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'course_revision_changed',
                'message' => "تم تحديث الكورس\nنعيد تحميل أحدث نسخة",
                'data' => [
                    'course_id' => (int) $canonical->id,
                    'published_revision' => (int) (
                        $canonical->last_published_authoring_version ?: $canonical->authoring_version
                    ),
                    'reload_endpoint' => "/api/v1/courses/{$canonical->id}/details",
                ],
            ], 409);
        }
        if ($archiveContinuation) {
            $courseId = (int) $archiveContinuation['canonical_course']->id;
        }

        if (
            !$course
            || !$section
            || (!$course->isPublishedForLearning() && !$archiveContinuation)
            || (int) $section->course_id !== (int) $lesson->list_id
            || $section->getSectionType() !== 'lesson'
            || (int) $section->sectionable_id !== (int) $lesson->id
        ) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'المقطع غير متاح',
                'data' => null,
            ], 404);
        }

        $isPublicPreview = (bool) $lesson->is_opened;
        $hasLearningAccess = $this->courseAccess->hasLearningAccess(
            (int) $user->id,
            $courseId
        );
        // Publish grace preserves an already-open media generation, not a
        // revoked purchase. Re-check the canonical entitlement on every
        // heartbeat so an archived session cannot outlive access removal.
        if (
            !$isPublicPreview
            && !$hasLearningAccess
        ) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'message' => 'افتح الكورس أولًا لحفظ تقدمك',
                'data' => null,
            ], 403);
        }

        $providerDuration = max(0, (int) ($lesson->mediaState?->duration_seconds ?? 0));
        // The reconciled media row is the sole duration authority. Accepting
        // the player's duration when that row is incomplete creates a second
        // completion clock and lets two devices disagree about the same reel.
        if ($providerDuration <= 0) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'video_metadata_unavailable',
                'message' => "بيانات الفيديو قيد التجهيز\nحاول بعد قليل",
                'data' => null,
            ], 409);
        }
        $duration = $providerDuration;
        $position = min((int) ($validated['position_seconds'] ?? 0), $duration);

        // Advancing the session sequence and crediting the same sample are one
        // durable transition. If evidence persistence fails, rolling both
        // back lets the player retry this sequence instead of losing watched
        // time to a session row that already consumed it.
        $playbackState = DB::transaction(function () use (
            $sessions,
            $user,
            $lesson,
            $validated,
            $position,
            $duration,
            $hasLearningAccess,
            $rewards
        ): array {
            // Account deletion and wallet rewards own this same aggregate
            // lock. Taking it first prevents a heartbeat from recreating
            // progress or rewards while that account is being removed.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $sessionResult = $sessions->accept($user, (int) $lesson->id, $validated + [
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
                        $sessionResult['previous_sample'] ?? null
                    )
                    : [
                        'evidence_id' => null,
                        'verified_seconds' => 0,
                        'required_seconds' => null,
                        'credited_seconds' => 0,
                        'eligible_for_completion' => false,
                    ];
                $reward = $rewards->recordStudy(
                    $user,
                    (int) $evidence['credited_seconds']
                );
            }

            return compact('sessionResult', 'evidence', 'reward');
        }, 3);
        $sessionResult = $playbackState['sessionResult'];
        $duplicate = false;
        if (!$sessionResult['accepted']) {
            $invalid = $sessionResult['reason'] === 'invalid_session';
            if ($invalid) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'message' => 'تعذّر حفظ تقدم هذا المقطع',
                    'data' => [
                        'recorded' => false,
                        'duplicate' => false,
                        'reason' => $sessionResult['reason'],
                        'credited_seconds' => 0,
                    ],
                ], 422);
            }
            $duplicate = true;
        }

        // Preview playback has its own operational session, but it is not an
        // enrollment fact. It must not create watch history, academic
        // evidence, study rewards, or a "continue course" card.
        if (!$hasLearningAccess) {
            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم حفظ حالة التشغيل',
                'data' => [
                    'recorded' => false,
                    'duplicate' => $duplicate,
                    'reason' => $duplicate ? $sessionResult['reason'] : null,
                    'preview' => true,
                    'tracking_enabled' => false,
                    'credited_seconds' => 0,
                    'learning_evidence' => null,
                    'reward' => null,
                ],
            ]);
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
            $archiveContinuation['current_section']?->id
            ?? $section->id
        );
        if ((bool) ($completionEvidence['eligible_for_completion'] ?? false)) {
            try {
                $completionResult = $completion->complete(
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

        if (!$user->watch_history_enabled) {
            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم حفظ تقدمك',
                'data' => [
                    'recorded' => false,
                    'duplicate' => $duplicate,
                    'reason' => $duplicate ? $sessionResult['reason'] : null,
                    'tracking_enabled' => false,
                    'learning_evidence' => $evidence,
                    'current_learning_evidence' => $currentEvidence,
                    'course_revision_changed' => $archiveContinuation !== null,
                    'course_id' => $courseId,
                    'current_lesson_id' => $currentLesson?->id,
                    'current_section_id' => $archiveContinuation['current_section']?->id ?? null,
                    'section_completion' => $completionResult,
                    'reward' => $reward,
                ],
            ]);
        }

        $acceptedSession = $sessionResult['session'] ?? null;
        if ($duplicate && $acceptedSession) {
            $position = (int) $acceptedSession->last_position_seconds;
            $duration = max(1, (int) ($acceptedSession->duration_seconds ?? $duration));
        }
        $incomingSessionId = $acceptedSession?->id;
        $incomingSessionStartedAt = $acceptedSession?->started_at;
        $incomingObservedAt = $acceptedSession?->last_heartbeat_at
            ?? $incomingSessionStartedAt;
        $incomingSequence = $duplicate && $acceptedSession
            ? (int) $acceptedSession->last_sequence
            : (int) $validated['sequence'];
        $resumeRecorded = true;
        $resumeLesson = $currentLesson ?? $lesson;
        $courseName = (string) (
            ($archiveContinuation['canonical_course'] ?? $lesson->course)?->name_ar
            ?? ($archiveContinuation['canonical_course'] ?? $lesson->course)?->name_en
            ?? ''
        );

        $log = DB::transaction(function () use (
            $user,
            $resumeLesson,
            $courseId,
            $courseName,
            $position,
            $duration,
            $incomingSessionId,
            $incomingSessionStartedAt,
            $incomingObservedAt,
            $incomingSequence,
            $evidence,
            &$resumeRecorded
        ): WatchingLog {
            // The unique pair plus insert-or-ignore closes the first-watch race
            // without holding a coarse lock on every action from this user.
            $created = DB::table('watching_logs')->insertOrIgnore([
                'user_id' => $user->id,
                'lesson_id' => $resumeLesson->id,
                'lesson_name' => (string) $resumeLesson->title,
                'course_id' => $courseId,
                'course_section_id' => $resumeLesson->courseSection?->id,
                'course_name' => $courseName,
                'position_seconds' => 0,
                'duration_seconds' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $log = WatchingLog::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $resumeLesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $storedObservedAt = $log->watched_at ?? $log->updated_at;
            if (!$created && $incomingSessionId && $incomingObservedAt && $storedObservedAt) {
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
                    && !$sameSession
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
                    'course_section_id' => $resumeLesson->courseSection?->id,
                    'course_name' => $courseName,
                    'position_seconds' => $position,
                    'duration_seconds' => $duration ?? $log->duration_seconds,
                    'watched_at' => $incomingObservedAt ?? now(),
                ];
                if ($incomingSessionId && $incomingSessionStartedAt) {
                    $resumeAttributes += [
                        'playback_session_id' => $incomingSessionId,
                        'playback_session_started_at' => $incomingSessionStartedAt,
                        'last_playback_sequence' => $incomingSequence,
                    ];
                }
                $log->fill($resumeAttributes);
            }

            if (
                (bool) ($evidence['eligible_for_completion'] ?? false)
                && $log->completed_at === null
            ) {
                $log->completed_at = now();
            }

            $log->save();
            return $log;
        });

        // The response exposes the exact server-qualified evidence and reward
        // committed for this accepted sample, or the durable snapshot when
        // this was a replay.
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حفظ موضع المشاهدة',
            'data' => [
                'recorded' => $resumeRecorded,
                'duplicate' => $duplicate,
                'reason' => $duplicate ? $sessionResult['reason'] : null,
                'resume_ignored_as_stale_session' => !$resumeRecorded,
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
                'current_section_id' => $archiveContinuation['current_section']?->id ?? null,
                'section_completion' => $completionResult,
                'reward' => $reward,
            ],
        ]);
    }

}
