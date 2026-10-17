<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonMediaState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class MediaHealthService
{
    public function __construct(private BunnyService $bunny) {}

    public function probe(Lesson $lesson): LessonMediaState
    {
        $lessonId = (int) $lesson->id;
        $observedGuid = strtolower(trim((string) $lesson->bunny_video_id));
        $observedUsesBunny = $lesson->usesBunnyVideo();
        $probeStartedAt = now();
        $probeGeneration = $this->claimProbeGeneration(
            $lessonId,
            $observedGuid,
            $observedUsesBunny
        );
        if ($probeGeneration === 0) {
            return LessonMediaState::query()->where('lesson_id', $lessonId)->first()
                ?: new LessonMediaState(['status' => 'failed']);
        }

        if (!$observedUsesBunny) {
            return $this->persistObservation($lessonId, $observedGuid, false, $probeStartedAt, $probeGeneration, [
                'status' => 'failed',
                'last_error_code' => 'missing_source',
                'last_error_message' => 'No Bunny media id is attached to this lesson.',
            ], true);
        }

        // Provider I/O deliberately happens without a row lock. The observed
        // GUID and start time below are the compare-and-swap generation that
        // prevents a slow old response from overwriting a replacement video.
        $inspection = $this->bunny->inspectRemoteVideo($observedGuid);
        $inspectionState = (string) ($inspection['state'] ?? 'unavailable');
        $details = is_array($inspection['details'] ?? null) ? $inspection['details'] : null;
        if ($inspectionState === 'not_found') {
            return $this->persistObservation($lessonId, $observedGuid, true, $probeStartedAt, $probeGeneration, [
                'status' => 'failed',
                'last_error_code' => 'provider_media_missing',
                'last_error_message' => 'The configured provider confirmed that this media object does not exist.',
            ], true);
        }
        if (in_array($inspectionState, ['provider_guid_mismatch', 'provider_library_mismatch'], true)) {
            return $this->persistObservation($lessonId, $observedGuid, true, $probeStartedAt, $probeGeneration, [
                'status' => 'failed',
                'last_error_code' => $inspectionState,
                'last_error_message' => 'The remote media identity does not match the configured lesson and library.',
            ], true);
        }
        if (!$details) {
            return $this->persistObservation($lessonId, $observedGuid, true, $probeStartedAt, $probeGeneration, [
                // A transient control-plane outage must not demote a
                // previously playable data-plane source.
                'status' => null,
                'last_error_code' => match ($inspectionState) {
                    'unauthorized' => 'provider_auth_failed',
                    'rate_limited' => 'provider_rate_limited',
                    'unconfigured' => 'provider_unconfigured',
                    default => 'provider_unreachable',
                },
                'last_error_message' => 'Bunny control plane did not return media details.',
            ], true);
        }

        $resolutions = collect(explode(',', (string) ($details['availableResolutions'] ?? '')))
            ->map(fn ($value) => trim((string) $value))->filter()->values();
        $providerStatus = (int) ($details['status'] ?? -1);
        $ready = BunnyService::providerVideoStatusIsPlayable($providerStatus);
        $failed = BunnyService::providerVideoStatusIsFailure($providerStatus);
        $qualities = $resolutions
            ->map(fn ($value) => str_ends_with($value, 'p') ? $value : $value . 'p')
            ->filter(fn ($value) => in_array($value, ['1080p', '720p', '480p', '360p'], true))
            ->prepend('auto')->unique()->values()->all();

        return $this->persistObservation($lessonId, $observedGuid, true, $probeStartedAt, $probeGeneration, [
            'status' => $failed ? 'failed' : ($ready ? 'ready' : 'processing'),
            'duration_seconds' => isset($details['length'])
                ? max(0, (int) round((float) $details['length']))
                : null,
            'available_qualities' => $qualities ?: ['auto'],
            'manifest' => [
                'status' => $details['status'] ?? null,
                'encode_progress' => $details['encodeProgress'] ?? null,
                'video_library_id' => $details['videoLibraryId'] ?? null,
                'width' => $details['width'] ?? null,
                'height' => $details['height'] ?? null,
                'available_resolutions' => $resolutions->all(),
                'thumbnail_file_name' => $details['thumbnailFileName'] ?? null,
                'captions' => BunnyService::captionTracks($details),
            ],
            'last_error_code' => $failed ? 'provider_encode_failed' : null,
            'last_error_message' => $failed ? 'Bunny reported a failed encode.' : null,
        ], $failed, !$failed);
    }

    /** Allocate a durable generation before provider I/O. */
    private function claimProbeGeneration(
        int $lessonId,
        string $observedGuid,
        bool $observedUsesBunny
    ): int {
        return DB::transaction(function () use ($lessonId, $observedGuid, $observedUsesBunny): int {
            $currentLesson = Lesson::query()->whereKey($lessonId)->lockForUpdate()->first();
            if (
                !$currentLesson
                || $currentLesson->usesBunnyVideo() !== $observedUsesBunny
                || !hash_equals(
                    strtolower(trim((string) $currentLesson->bunny_video_id)),
                    $observedGuid
                )
            ) {
                return 0;
            }
            LessonMediaState::query()->createOrFirst(
                ['lesson_id' => $lessonId],
                [
                    'provider' => 'bunny',
                    'provider_media_id' => $currentLesson->bunny_video_id,
                    'status' => 'unknown',
                    'protocol' => 'hls',
                    'available_qualities' => ['auto'],
                ]
            );
            $state = LessonMediaState::query()
                ->where('lesson_id', $lessonId)
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $state->provider_media_id !== (string) $currentLesson->bunny_video_id) {
                $state->forceFill(LessonMediaState::resetForGeneration(
                    (string) $currentLesson->bunny_video_id,
                    'unknown'
                ));
            }
            $generation = (int) $state->probe_generation + 1;
            $state->forceFill(['probe_generation' => $generation])->save();

            return $generation;
        }, 3);
    }

    /** Commit an external observation only while both generations still match. */
    private function persistObservation(
        int $lessonId,
        string $observedGuid,
        bool $observedUsesBunny,
        Carbon $probeStartedAt,
        int $probeGeneration,
        array $attributes,
        bool $incrementRetry = false,
        bool $resetRetry = false
    ): LessonMediaState {
        return DB::transaction(function () use (
            $lessonId,
            $observedGuid,
            $observedUsesBunny,
            $probeStartedAt,
            $probeGeneration,
            $attributes,
            $incrementRetry,
            $resetRetry
        ): LessonMediaState {
            $currentLesson = Lesson::query()->whereKey($lessonId)->lockForUpdate()->first();
            if (!$currentLesson) {
                return new LessonMediaState(['status' => 'failed']);
            }

            LessonMediaState::query()->createOrFirst(
                ['lesson_id' => $lessonId],
                [
                    'provider' => 'bunny',
                    'provider_media_id' => $currentLesson->bunny_video_id,
                    'status' => 'unknown',
                    'protocol' => 'hls',
                    'available_qualities' => ['auto'],
                ]
            );
            $state = LessonMediaState::query()
                ->where('lesson_id', $lessonId)
                ->lockForUpdate()
                ->firstOrFail();

            $currentGuid = strtolower(trim((string) $currentLesson->bunny_video_id));
            if (
                $currentLesson->usesBunnyVideo() !== $observedUsesBunny
                || !hash_equals($currentGuid, $observedGuid)
                || (int) $state->probe_generation !== $probeGeneration
            ) {
                return $state;
            }

            if (($attributes['status'] ?? null) === null) {
                $attributes['status'] = $state->status ?: 'unknown';
            }
            if (array_key_exists('duration_seconds', $attributes) && $attributes['duration_seconds'] === null) {
                $attributes['duration_seconds'] = $state->duration_seconds;
            }
            $attributes += [
                'provider' => 'bunny',
                'provider_media_id' => $observedGuid ?: null,
                'protocol' => 'hls',
                'last_probe_at' => $probeStartedAt,
            ];
            if ($incrementRetry) {
                $attributes['retry_count'] = (int) $state->retry_count + 1;
            } elseif ($resetRetry) {
                $attributes['retry_count'] = 0;
            }

            $state->forceFill($attributes)->save();
            return $state;
        }, 3);
    }
}
