<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\MediaHealthService;
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

    public function handle(
        MediaHealthService $health,
        MediaReconciliationService $reconciliation
    ): void {
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

        $state = $health->probe($lesson);
        if ($state->status === 'ready') {
            // Verify the signed HLS document, poster, duration, renditions and
            // private attachments without rescanning every video in the course.
            $reconciliation->reconcileLesson($lesson, true, true);
            return;
        }
        if ($state->status === 'failed' || $this->attempts() >= $this->tries) {
            return;
        }

        $delays = [15, 30, 60, 120, 180, 300, 300, 300, 300, 300, 300];
        $this->release($delays[min(count($delays) - 1, max(0, $this->attempts() - 1))]);
    }
}
