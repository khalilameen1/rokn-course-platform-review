<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CourseRevisionChangedException;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\PlaybackSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PlaybackManifestService
{
    public function __construct(
        private BunnyService $bunny,
        private CourseCompletionService $completion,
        private PlaybackCapabilityService $capabilities,
        private CourseStagedAuthoringService $stagedAuthoring,
        private CourseChatAccessService $courseAccess
    ) {
    }

    public function issue(User $user, Lesson $lesson, array $clientContext = []): array
    {
        [$lesson, $state] = $this->mediaGeneration((int) $lesson->id);

        $section = $lesson->courseSection;
        $course = $lesson->course;
        $graceRevision = $course && $course->is_coming_soon
            ? $this->stagedAuthoring->activeArchiveForCourse($course)
            : null;
        $graceContext = $graceRevision
            ? $this->stagedAuthoring->archivedPlaybackContinuation(
                $user,
                $lesson,
                $clientContext['playback_session_id'] ?? null
            )
            : null;
        $graceSession = $graceContext['session'] ?? null;
        if ($graceRevision && !$graceSession) {
            $canonical = $graceRevision->canonicalCourse()->firstOrFail();
            throw new CourseRevisionChangedException(
                (int) $canonical->id,
                (int) ($canonical->last_published_authoring_version ?: $canonical->authoring_version)
            );
        }
        $publishedOrGrace = $course
            && ($course->isPublishedForLearning() || $graceSession !== null);

        // Route model binding can still resolve a legacy Lesson after its
        // section/course was removed from the authored graph. Never let the
        // preview flag resurrect orphaned or unpublished media.
        if (
            !$course
            || !$section
            || !$publishedOrGrace
            || (int) $lesson->list_id !== (int) $course->id
            || (int) $section->course_id !== (int) $course->id
            || $section->getSectionType() !== 'lesson'
            || (int) $section->sectionable_id !== (int) $lesson->id
        ) {
            throw new AuthorizationException('This lesson is not published.');
        }

        // A session issued before an atomic publish may refresh its old signed
        // media during the short archive grace. It cannot be discovered or
        // opened as a new session, and no archived curriculum is resurrected.
        $isPublicPreview = (bool) $lesson->is_opened;
        $allowed = $isPublicPreview;
        if ($graceSession !== null && !$allowed) {
            $allowed = $this->courseAccess->hasLearningAccess(
                (int) $user->id,
                (int) $graceContext['canonical_course']->id
            );
        }
        if (!$allowed) {
            $accessState = $this->completion->sectionAccessState($user, $section);
            $allowed = $accessState['can_access'];
        }
        if (!$allowed) {
            throw new AuthorizationException(
                (string) ($accessState['lock_reason'] ?? 'lesson_locked')
            );
        }
        if (!$lesson->usesBunnyVideo()) {
            throw new RuntimeException('The lesson video is not ready for secure playback.');
        }

        // Bunny's control-plane API is deliberately kept out of the playback
        // request. The scheduled media reconciliation owns remote probes;
        // learners receive the last durable readiness result immediately.
        // A stale `ready` row is still playable because the signed delivery
        // URL is the data-plane source of truth, while unknown/processing rows
        // fail closed until reconciliation promotes them.
        if ($state->status === 'failed' || $state->integrity_status === 'quarantined') {
            throw new RuntimeException('The lesson video is unavailable.');
        }
        if ($state->status !== 'ready') {
            throw new RuntimeException('The lesson video is still being prepared.');
        }
        if ($state->last_reconciled_at === null) {
            throw new RuntimeException('The lesson video is still being prepared for secure delivery.');
        }
        if ((int) $state->duration_seconds <= 0) {
            throw new RuntimeException('The lesson video metadata is still being prepared.');
        }

        // Signing is stateless and remains outside the database transaction.
        // Superseded objects are retained for seven days, so a manifest issued
        // immediately before an authoring replacement stays valid for its TTL.
        $source = $this->bunny->getVideo((string) $lesson->bunny_video_id);
        if (!$source || empty($source['url'])) {
            throw new RuntimeException('A secure playback source could not be issued.');
        }
        $qualities = collect($state->available_qualities ?: ['auto'])
            ->filter(fn ($quality) => in_array($quality, ['auto', '1080p', '720p', '480p', '360p'], true))
            ->unique()->values()->all();
        if (!in_array('auto', $qualities, true)) {
            array_unshift($qualities, 'auto');
        }

        $clientCapabilities = $this->capabilities->normalize(
            isset($clientContext['client_capabilities']) && is_array($clientContext['client_capabilities'])
                ? $clientContext['client_capabilities']
                : null
        );
        $networkPolicy = $this->capabilities->networkPolicy($clientCapabilities);
        $playbackReason = $this->capabilities->playbackReason($clientCapabilities, $networkPolicy);
        $sessionAttributes = $this->capabilities->sessionAttributes(
            $clientCapabilities,
            isset($clientContext['client']) ? (string) $clientContext['client'] : null,
            $playbackReason
        );

        $sourceExpiresAt = null;
        if (!empty($source['expires_at'])) {
            try {
                $sourceExpiresAt = Carbon::parse((string) $source['expires_at']);
            } catch (\Throwable) {
                $sourceExpiresAt = null;
            }
        }
        $refreshAfter = $this->refreshAfter($sourceExpiresAt);
        $sessionAttributes['source_expires_at'] = $sourceExpiresAt;
        $sourceHost = parse_url((string) $source['url'], PHP_URL_HOST);
        $sessionAttributes['source_host'] = is_string($sourceHost) && $sourceHost !== ''
            ? $sourceHost
            : null;

        $session = DB::transaction(function () use (
            $user,
            $course,
            $lesson,
            $section,
            $sessionAttributes,
            $clientContext,
            $graceSession
        ): PlaybackSession {
            // Publishing takes the canonical course lock before swapping its
            // graph. Taking the same lock here makes the boundary exact: a
            // session either exists before the publish timestamp and receives
            // archive grace, or this request observes the new graph and asks
            // the client to reload. It can never issue an immediately stale
            // manifest in the gap between the initial read and allocation.
            $lockedCourse = Course::query()
                ->whereKey($course->id)
                ->lockForUpdate()
                ->first();
            $stillCurrent = $lockedCourse
                && ($graceSession !== null || $lockedCourse->isPublishedForLearning())
                && Lesson::query()
                    ->whereKey($lesson->id)
                    ->where('list_id', $lockedCourse->id)
                    ->whereHas('courseSection', fn ($sections) => $sections
                        ->whereKey($section->id)
                        ->where('course_id', $lockedCourse->id)
                        ->where('sectionable_type', Lesson::class)
                        ->where('sectionable_id', $lesson->id))
                    ->exists();
            if (!$stillCurrent) {
                throw new CourseRevisionChangedException(
                    (int) ($lockedCourse?->id ?? $course->id),
                    (int) (
                        $lockedCourse?->last_published_authoring_version
                        ?: $lockedCourse?->authoring_version
                        ?: 1
                    )
                );
            }
            $lockedState = LessonMediaState::query()
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->first();
            $lessonGuid = strtolower(trim((string) $lesson->bunny_video_id));
            $stateGuid = strtolower(trim((string) $lockedState?->provider_media_id));
            if (
                !$lockedState
                || $lessonGuid === ''
                || $stateGuid === ''
                || !hash_equals($lessonGuid, $stateGuid)
                || $lockedState->status !== 'ready'
                || $lockedState->integrity_status === 'quarantined'
                || $lockedState->last_reconciled_at === null
                || (int) $lockedState->duration_seconds < 1
            ) {
                throw new RuntimeException('The lesson video became unavailable during manifest allocation.');
            }

            // Serialize only the tiny session-allocation section. A refresh
            // reuses a session only when the client names it; two fresh
            // players must never share a sequence namespace merely because
            // they opened the same lesson within a short time window.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $requestedSessionId = (string) ($clientContext['playback_session_id'] ?? '');
            if ($requestedSessionId !== '') {
                $requested = PlaybackSession::query()
                    ->whereKey($requestedSessionId)
                    ->where('user_id', $user->id)
                    ->where('lesson_id', $lesson->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->first();
                if ($requested) {
                    // A screen can remain in the Android/iOS back stack for
                    // hours. Never renew the signed source onto a session that
                    // the evidence endpoint will reject as expired; close it
                    // and allocate one fresh sequence namespace instead.
                    if ($requested->started_at?->lt(now()->subHours(12))) {
                        $requested->forceFill([
                            'event_type' => 'stop',
                            'ended_at' => now(),
                            'end_reason' => 'session_expired',
                        ])->save();
                    } else {
                        $requested->forceFill($sessionAttributes)->save();

                        return $requested;
                    }
                }
            }

            return PlaybackSession::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'lesson_id' => $lesson->id,
                'course_section_id' => $section?->id,
                'started_at' => now(),
                'event_type' => 'play',
                'source_protocol' => 'hls',
            ] + $sessionAttributes);
        }, 3);

        $expiresInSeconds = $sourceExpiresAt
            ? (int) max(0, now()->diffInSeconds($sourceExpiresAt, false))
            : null;
        $refreshInSeconds = $refreshAfter
            ? (int) max(0, now()->diffInSeconds($refreshAfter, false))
            : null;
        $poster = null;
        if (trim((string) $lesson->thumbnail_path) !== '') {
            $poster = $this->bunny->generateBunnySignedUrl(
                (string) $lesson->thumbnail_path,
                max(600, (int) ($expiresInSeconds ?: 3600))
            );
        } else {
            $providerThumbnail = trim((string) data_get($state->manifest, 'thumbnail_file_name'));
            if ($providerThumbnail !== '') {
                $poster = $this->bunny->getVideoThumbnail(
                    (string) $lesson->bunny_video_id,
                    $providerThumbnail
                )['url'] ?? null;
            }
        }

        return [
            'playback_session_id' => $session->id,
            'lesson_id' => $lesson->id,
            'source_url' => $source['url'],
            'protocol' => 'hls',
            'expires_at' => $source['expires_at'] ?? null,
            'expires_in_seconds' => $expiresInSeconds,
            'refresh_after' => $refreshAfter?->toIso8601String(),
            'refresh_in_seconds' => $refreshInSeconds,
            'poster_url' => $poster,
            'poster_expires_at' => $poster ? ($source['expires_at'] ?? null) : null,
            'duration_seconds' => $state->duration_seconds,
            'available_qualities' => $qualities,
            'quality_sources' => (object) [],
            'quality_source_reason' => 'adaptive_master_manifest',
            'playback_reason' => $playbackReason,
            'network_policy' => $networkPolicy,
            'media_status' => $state->status,
        ];
    }

    /** @return array{0:Lesson,1:LessonMediaState} */
    private function mediaGeneration(int $lessonId): array
    {
        // Publishing and the media probe own this row. Learner playback is a
        // read-only consumer: a missing or mismatched generation must fail
        // closed instead of reconstructing state from a legacy lesson pointer.
        $lesson = Lesson::query()
            ->with(['courseSection.module', 'course', 'mediaState'])
            ->whereKey($lessonId)
            ->firstOrFail();
        $state = $lesson->mediaState;
        if (!$state) {
            throw new RuntimeException('The lesson video has no published media state.');
        }
        $lessonGuid = strtolower(trim((string) $lesson->bunny_video_id));
        $stateGuid = strtolower(trim((string) $state->provider_media_id));
        if ($lessonGuid === '' || $stateGuid === '' || !hash_equals($lessonGuid, $stateGuid)) {
            throw new RuntimeException('The lesson media generation is not published.');
        }

        return [$lesson, $state];
    }

    private function refreshAfter(?Carbon $expiresAt): ?Carbon
    {
        if (!$expiresAt || $expiresAt->isPast()) {
            return null;
        }

        $margin = max(60, min(3600, (int) config('playback.manifest_refresh_margin_seconds', 900)));
        $refreshAt = $expiresAt->copy()->subSeconds($margin);

        return $refreshAt->isAfter(now()->addMinute())
            ? $refreshAt
            : now()->addMinute();
    }
}
