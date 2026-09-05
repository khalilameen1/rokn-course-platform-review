<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Audits published learning media without deleting or unpublishing anything.
 * Playback readiness and operational integrity are deliberately separate: a
 * missing thumbnail must be visible to operators without blocking a playable
 * lesson for learners.
 */
final class MediaReconciliationService
{
    private ?bool $integritySchemaReady = null;

    public function __construct(
        private BunnyService $bunny,
        private MediaHealthService $health
    ) {
    }

    /** @return array<string, mixed> */
    public function reconcileCourse(
        Course $course,
        bool $persist = true,
        bool $fetchManifest = true
    ): array {
        $course->loadMissing([
            'photo',
            'lessons.mediaState',
        ]);

        $courseIssues = $this->courseIssues($course);
        $results = [];
        foreach ($course->lessons as $lesson) {
            $results[] = $this->inspectLesson(
                $lesson,
                $courseIssues,
                $persist,
                $fetchManifest
            );
        }

        $counts = ['healthy' => 0, 'attention' => 0, 'quarantined' => 0];
        $issueCount = count($courseIssues);
        foreach ($results as $result) {
            $status = (string) ($result['integrity_status'] ?? 'attention');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $issueCount += collect((array) ($result['issues'] ?? []))
                ->where('scope', 'lesson')
                ->count();
        }

        // A published course with no lesson is operationally incomplete even
        // though there is no media-state row on which to persist that fact.
        if ($course->lessons->isEmpty()) {
            $courseIssues[] = $this->issue('course_has_no_lessons', 'attention', 'course', (int) $course->id);
            $issueCount++;
        }

        return [
            'course_id' => (int) $course->id,
            'lessons' => count($results),
            'counts' => $counts,
            'issues' => $issueCount,
            'course_issues' => $courseIssues,
            'results' => $results,
        ];
    }

    /** Reconcile one freshly uploaded lesson without rescanning every video. */
    public function reconcileLesson(
        Lesson $lesson,
        bool $persist = true,
        bool $fetchManifest = true
    ): array {
        $lesson->loadMissing(['course.photo', 'mediaState']);
        $course = $lesson->course;
        if (!$course) {
            return [
                'lesson_id' => (int) $lesson->id,
                'playback_status' => 'failed',
                'integrity_status' => 'quarantined',
                'issues' => [$this->issue('course_missing', 'quarantined', 'lesson', (int) $lesson->id)],
            ];
        }

        return $this->inspectLesson(
            $lesson,
            $this->courseIssues($course),
            $persist,
            $fetchManifest
        );
    }

    /**
     * @param array<int, array<string, mixed>> $courseIssues
     * @return array<string, mixed>
     */
    private function inspectLesson(
        Lesson $lesson,
        array $courseIssues,
        bool $persist,
        bool $fetchManifest
    ): array {
        $issues = $courseIssues;
        $state = $lesson->mediaState;

        if (!$lesson->usesBunnyVideo()) {
            $issues[] = $this->issue('missing_secure_source', 'quarantined', 'lesson', (int) $lesson->id);
            if ($persist) {
                $state = $this->health->probe($lesson);
            }
            return $this->completeLessonResult($lesson, $state, $issues, $persist);
        }

        if ($persist) {
            // The existing probe remains the single writer of playback health.
            // Integrity fields below never overwrite its ready/failed state.
            $state = $this->health->probe($lesson);
            if (
                strtolower(trim((string) $state->provider_media_id))
                !== strtolower(trim((string) $lesson->bunny_video_id))
            ) {
                $issues[] = $this->issue(
                    'media_generation_changed_during_probe',
                    'attention',
                    'lesson',
                    (int) $lesson->id
                );

                // A replacement won the race while the provider request was
                // in flight. Its own probe owns the new generation.
                return $this->completeLessonResult($lesson, $state, $issues, false);
            }
        } else {
            $state = $this->readOnlyState($lesson);
        }

        $playbackStatus = (string) ($state?->status ?: 'unknown');
        $providerError = (string) ($state?->last_error_code ?? '');
        if (in_array($providerError, [
            'provider_media_missing',
            'provider_guid_mismatch',
            'provider_library_mismatch',
            'provider_encode_failed',
        ], true)) {
            $issues[] = $this->issue($providerError, 'quarantined', 'lesson', (int) $lesson->id);
        } elseif (str_starts_with($providerError, 'provider_')) {
            $issues[] = $this->issue('provider_unreachable', 'attention', 'lesson', (int) $lesson->id);
        } elseif ($playbackStatus === 'failed') {
            $issues[] = $this->issue('provider_encode_failed', 'quarantined', 'lesson', (int) $lesson->id);
        } elseif ($playbackStatus !== 'ready') {
            $issues[] = $this->issue(
                $playbackStatus === 'processing' ? 'provider_still_processing' : 'provider_unreachable',
                'attention',
                'lesson',
                (int) $lesson->id
            );
        }

        if ((int) ($state?->duration_seconds ?? 0) <= 0) {
            $issues[] = $this->issue('duration_missing', 'attention', 'lesson', (int) $lesson->id);
        } else {
            $declaredDuration = max(0, (int) $lesson->duration_minutes * 60);
            $providerDuration = (int) $state->duration_seconds;
            $tolerance = max(15, (int) round($providerDuration * 0.20));
            if ($declaredDuration > 0 && abs($declaredDuration - $providerDuration) > $tolerance) {
                $issues[] = $this->issue('duration_mismatch', 'attention', 'lesson', (int) $lesson->id);
            }
        }

        $qualities = collect((array) ($state?->available_qualities ?? []))
            ->filter(fn ($value) => in_array($value, ['1080p', '720p', '480p', '360p'], true));
        if ($qualities->isEmpty()) {
            $issues[] = $this->issue('quality_ladder_missing', 'attention', 'lesson', (int) $lesson->id);
        }

        $thumbnail = trim((string) $lesson->thumbnail_path);
        $providerThumbnail = trim((string) data_get($state?->manifest, 'thumbnail_file_name'));
        if ($thumbnail === '' && $providerThumbnail === '') {
            $issues[] = $this->issue('thumbnail_unverified', 'attention', 'lesson', (int) $lesson->id);
        } elseif ($fetchManifest && $thumbnail !== '') {
            $signedThumbnail = $this->bunny->generateBunnySignedUrl($thumbnail, 600);
            if (!$signedThumbnail || !$this->imageIsReadable($signedThumbnail)) {
                $issues[] = $this->issue('thumbnail_delivery_unavailable', 'attention', 'lesson', (int) $lesson->id);
            }
        }

        $source = $this->bunny->getVideo((string) $lesson->bunny_video_id);
        if (!$source || trim((string) ($source['url'] ?? '')) === '') {
            $issues[] = $this->issue('signed_manifest_unavailable', 'quarantined', 'lesson', (int) $lesson->id);
        } elseif ($fetchManifest) {
            $manifestResult = $this->manifestIsReadable((string) $source['url']);
            if (!$manifestResult['ready']) {
                $issues[] = $this->issue(
                    (string) $manifestResult['code'],
                    $playbackStatus === 'ready' ? 'attention' : 'quarantined',
                    'lesson',
                    (int) $lesson->id
                );
            }
        }

        return $this->completeLessonResult($lesson, $state, $issues, $persist);
    }

    private function readOnlyState(Lesson $lesson): LessonMediaState
    {
        $state = $lesson->mediaState ?: new LessonMediaState([
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_media_id' => $lesson->bunny_video_id,
            'protocol' => 'hls',
        ]);
        $inspection = $this->bunny->inspectRemoteVideo((string) $lesson->bunny_video_id);
        $inspectionState = (string) ($inspection['state'] ?? 'unavailable');
        $details = is_array($inspection['details'] ?? null) ? $inspection['details'] : null;
        if (in_array($inspectionState, [
            'not_found',
            'provider_guid_mismatch',
            'provider_library_mismatch',
        ], true)) {
            $state->forceFill([
                'status' => 'failed',
                'last_error_code' => $inspectionState === 'not_found'
                    ? 'provider_media_missing'
                    : $inspectionState,
            ]);
            return $state;
        }
        if (!$details) {
            $state->forceFill([
                'status' => $state->status ?: 'unknown',
                'last_error_code' => match ($inspectionState) {
                    'unauthorized' => 'provider_auth_failed',
                    'rate_limited' => 'provider_rate_limited',
                    'unconfigured' => 'provider_unconfigured',
                    default => 'provider_unreachable',
                },
            ]);
            return $state;
        }

        $resolutions = collect(explode(',', (string) ($details['availableResolutions'] ?? '')))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();
        $providerStatus = (int) ($details['status'] ?? -1);
        $ready = BunnyService::providerVideoStatusIsPlayable($providerStatus);
        $failed = BunnyService::providerVideoStatusIsFailure($providerStatus);
        $qualities = $resolutions
            ->map(fn ($value) => str_ends_with($value, 'p') ? $value : $value . 'p')
            ->filter(fn ($value) => in_array($value, ['1080p', '720p', '480p', '360p'], true))
            ->prepend('auto')
            ->unique()
            ->values()
            ->all();

        $state->forceFill([
            'status' => $failed ? 'failed' : ($ready ? 'ready' : 'processing'),
            'duration_seconds' => isset($details['length'])
                ? max(0, (int) round((float) $details['length']))
                : $state->duration_seconds,
            'available_qualities' => $qualities ?: ['auto'],
            'manifest' => [
                'status' => $details['status'] ?? null,
                'encode_progress' => $details['encodeProgress'] ?? null,
                'video_library_id' => $details['videoLibraryId'] ?? null,
                'width' => $details['width'] ?? null,
                'height' => $details['height'] ?? null,
                'available_resolutions' => $resolutions->all(),
                'thumbnail_file_name' => $details['thumbnailFileName'] ?? null,
            ],
            'last_error_code' => $failed ? 'provider_encode_failed' : null,
        ]);

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function completeLessonResult(
        Lesson $lesson,
        ?LessonMediaState $state,
        array $issues,
        bool $persist
    ): array {
        $issues = collect($issues)
            ->unique(fn (array $issue) => implode(':', [
                $issue['code'] ?? '',
                $issue['scope'] ?? '',
                $issue['reference'] ?? '',
            ]))
            ->values()
            ->take(50)
            ->all();

        $integrityStatus = 'healthy';
        if (collect($issues)->contains(fn (array $issue) => ($issue['severity'] ?? '') === 'quarantined')) {
            $integrityStatus = 'quarantined';
        } elseif ($issues !== []) {
            $integrityStatus = 'attention';
        }

        if (
            $persist
            && $state
            && $this->integritySchemaReady()
        ) {
            $observedGuid = strtolower(trim((string) $lesson->bunny_video_id));
            $observedProbeGeneration = (int) $state->probe_generation;
            DB::transaction(function () use (
                $lesson,
                $state,
                $observedGuid,
                $observedProbeGeneration,
                $integrityStatus,
                $issues
            ): void {
                $currentLesson = Lesson::query()->whereKey($lesson->id)->lockForUpdate()->first();
                $currentState = LessonMediaState::query()
                    ->where('lesson_id', $lesson->id)
                    ->lockForUpdate()
                    ->first();
                if (
                    !$currentLesson
                    || !$currentState
                    || strtolower(trim((string) $currentLesson->bunny_video_id)) !== $observedGuid
                    || strtolower(trim((string) $currentState->provider_media_id)) !== $observedGuid
                    || (int) $currentState->probe_generation !== $observedProbeGeneration
                ) {
                    return;
                }

                $currentState->forceFill([
                    'integrity_status' => $integrityStatus,
                    'integrity_issues' => $issues ?: null,
                    'last_reconciled_at' => now(),
                    'quarantined_at' => $integrityStatus === 'quarantined'
                        ? ($currentState->quarantined_at ?: now())
                        : null,
                ])->save();
                $state->setRawAttributes($currentState->getAttributes(), true);
            }, 3);
        }

        return [
            'lesson_id' => (int) $lesson->id,
            'playback_status' => (string) ($state?->status ?: 'unknown'),
            'integrity_status' => $integrityStatus,
            'issues' => $issues,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function courseIssues(Course $course): array
    {
        $issues = [];
        if (!$course->photo && trim((string) $course->getRawOriginal('image')) === '') {
            $issues[] = $this->issue('course_cover_missing', 'attention', 'course', (int) $course->id);
        }

        return collect($issues)
            ->unique(fn (array $issue) => implode(':', [$issue['code'], $issue['scope'], $issue['reference']]))
            ->values()
            ->all();
    }

    private function integritySchemaReady(): bool
    {
        if ($this->integritySchemaReady === null) {
            $this->integritySchemaReady = Schema::hasTable('lesson_media_states')
                && Schema::hasColumn('lesson_media_states', 'integrity_status');
        }

        return $this->integritySchemaReady;
    }

    /** @return array{ready:bool,code:string} */
    private function manifestIsReadable(string $signedUrl): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.apple.mpegurl'])
                ->get($signedUrl);

            if (!$response->successful()) {
                return ['ready' => false, 'code' => 'manifest_http_error'];
            }

            $body = (string) $response->body();
            $prefix = substr($body, 0, 8192);
            if (strlen($body) > strlen($prefix) && !str_ends_with($prefix, "\n")) {
                $lastCompleteLine = strrpos($prefix, "\n");
                $prefix = $lastCompleteLine === false
                    ? ''
                    : substr($prefix, 0, $lastCompleteLine + 1);
            }

            return $this->hasPlayablePlaylistEntry($prefix)
                ? ['ready' => true, 'code' => 'ok']
                : ['ready' => false, 'code' => 'manifest_invalid'];
        } catch (Throwable $exception) {
            // Never log the signed URL or its token.
            return ['ready' => false, 'code' => 'manifest_unreachable'];
        }
    }

    /**
     * This is deliberately a bounded readiness check, not an HLS parser. A
     * provider error page and an empty encoding skeleton must not become
     * learner-ready merely because they contain the EXTM3U token.
     */
    private function hasPlayablePlaylistEntry(string $playlist): bool
    {
        $lines = explode("\n", str_replace("\r", '', $playlist));
        if (($lines[0] ?? null) !== '#EXTM3U') {
            return false;
        }

        $awaitingUri = false;
        foreach (array_slice($lines, 1) as $rawLine) {
            $line = $rawLine;
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $awaitingUri = trim(substr($line, strlen('#EXT-X-STREAM-INF:'))) !== '';
                continue;
            }
            if (str_starts_with($line, '#EXTINF:')) {
                $duration = trim(explode(',', substr($line, strlen('#EXTINF:')), 2)[0]);
                $awaitingUri = $duration !== ''
                    && is_numeric($duration)
                    && (float) $duration > 0;
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }

            if ($awaitingUri && $this->isValidPlaylistUri($line)) {
                return true;
            }
            $awaitingUri = false;
        }

        return false;
    }

    private function isValidPlaylistUri(string $uri): bool
    {
        return $uri !== ''
            && preg_match('/[\x00-\x20\x7F<>"\\\\]/', $uri) !== 1;
    }

    private function imageIsReadable(string $signedUrl): bool
    {
        try {
            $response = Http::connectTimeout(5)->timeout(10)->head($signedUrl);
            if (in_array($response->status(), [405, 501], true)) {
                $response = Http::connectTimeout(5)
                    ->timeout(10)
                    ->withHeaders(['Range' => 'bytes=0-0'])
                    ->get($signedUrl);
            }

            return $response->successful()
                && str_starts_with(strtolower((string) $response->header('Content-Type')), 'image/');
        } catch (Throwable) {
            // Signed URLs are deliberately omitted from operational logs.
            return false;
        }
    }

    /** @return array{code:string,severity:string,scope:string,reference:int} */
    private function issue(string $code, string $severity, string $scope, int $reference): array
    {
        return compact('code', 'severity', 'scope', 'reference');
    }
}
