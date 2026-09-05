<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\MediaReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Promote one newly attached upload only after Bunny finishes processing it. */
final class ProbeLessonMedia implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;
    public int $timeout = 45;
    // Coalesce duplicate dispatches only until a worker owns the job. Once
    // processing begins, a crashed/released attempt must not retain the lock
    // that the scheduled recovery command needs to redeliver stale media.
    public int $uniqueFor = 3600;
    public bool $failOnTimeout = true;

    /** @var list<string> */
    public const TRANSIENT_READINESS_ISSUES = [
        'quality_ladder_missing',
        'manifest_http_error',
        'manifest_invalid',
        'manifest_unreachable',
    ];

    public string $expectedVideoGuid;

    public function __construct(public int $lessonId, string $expectedVideoGuid)
    {
        // Capture the provider generation at dispatch time. A lesson id alone
        // would coalesce a replacement probe with an older job that is still
        // running, potentially leaving the new video unprobed for an hour.
        $this->expectedVideoGuid = strtolower(trim(
            $expectedVideoGuid
        ));
        $this->onQueue((string) config('queue.channels.media', 'media'));
    }

    public function uniqueId(): string
    {
        return 'lesson-media-probe:' . $this->lessonId . ':'
            . $this->expectedVideoGuid;
    }

    public function handle(MediaReconciliationService $reconciliation): void
    {
        $lesson = Lesson::query()->with('course')->find($this->lessonId);
        if (!$lesson || !$lesson->usesBunnyVideo() || !$lesson->course) {
            return;
        }
        if (!hash_equals(
            $this->expectedVideoGuid,
            strtolower(trim((string) $lesson->bunny_video_id))
        )) {
            // This job belongs to a superseded remote object. Its successor
            // has a distinct unique key and owns all provider observations.
            return;
        }

        // One reconciliation performs both the control-plane probe and the
        // signed data-plane check. Calling MediaHealthService first duplicated
        // Bunny requests and, more importantly, treated the first `ready`
        // status as terminal even when renditions or the HLS document had not
        // propagated yet.
        $result = $reconciliation->reconcileLesson($lesson, true, true);
        $playbackStatus = (string) ($result['playback_status'] ?? 'unknown');
        $hasTransientReadinessIssue = self::hasTransientReadinessIssue(
            (array) ($result['issues'] ?? [])
        );
        $providerError = (string) $lesson->mediaState()
            ->value('last_error_code');
        $hasTransientReadinessIssue = $hasTransientReadinessIssue
            || in_array($providerError, [
                'provider_unreachable',
                'provider_rate_limited',
            ], true);
        if (
            $playbackStatus === 'failed'
            || ($playbackStatus === 'ready' && !$hasTransientReadinessIssue)
            || $this->attempts() >= $this->tries
        ) {
            return;
        }

        $delays = [15, 30, 60, 120, 180, 300, 300, 300, 300, 300, 300];
        $this->release($delays[min(count($delays) - 1, max(0, $this->attempts() - 1))]);
    }

    /** @param array<int, mixed> $issues */
    public static function hasTransientReadinessIssue(array $issues): bool
    {
        return collect($issues)->contains(
            static fn (mixed $issue): bool => is_array($issue)
                && in_array(
                    (string) ($issue['code'] ?? ''),
                    self::TRANSIENT_READINESS_ISSUES,
                    true
                )
        );
    }
}
