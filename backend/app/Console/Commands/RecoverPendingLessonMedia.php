<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProbeLessonMedia;
use App\Models\Lesson;
use App\Support\DurableJobDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Re-dispatch media work that could not reach the queue after a lesson upload.
 *
 * Draft lessons are intentional here: publishing is blocked until their media
 * becomes ready and reconciled, so a published-course-only audit cannot recover
 * a lost initial dispatch.
 */
final class RecoverPendingLessonMedia extends Command
{
    protected $signature = 'media:recover-pending
        {--limit=200 : Maximum lessons to inspect}
        {--stale-minutes=2 : Minimum age before a pending state is retried}';

    protected $description = 'Re-dispatch Bunny probes for stalled or unreconciled lesson media';

    public function handle(): int
    {
        if (!$this->schemaIsReady()) {
            $this->error('Lesson media tables are not ready. Run migrations first.');
            return self::FAILURE;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $staleMinutes = max(1, min(60, (int) $this->option('stale-minutes')));
        $cutoff = now()->subMinutes($staleMinutes);
        $dispatched = 0;
        $failed = 0;

        Lesson::query()
            ->select(['lessons.id', 'lessons.bunny_video_id'])
            ->where('video_source_type', 'bunny')
            ->whereNotNull('bunny_video_id')
            ->where('bunny_video_id', '!=', '')
            ->whereHas('mediaState', function ($state) use ($cutoff): void {
                $state->where(function ($pending) use ($cutoff): void {
                    $pending->whereIn('status', ['unknown', 'processing'])
                        ->where(function ($age) use ($cutoff): void {
                            $age->whereNull('last_probe_at')
                                ->orWhere('last_probe_at', '<=', $cutoff);
                        });
                })->orWhere(function ($unreconciled): void {
                    $unreconciled->where('status', 'ready')
                        ->whereNull('last_reconciled_at');
                });
            })
            ->orderBy('lessons.id')
            ->limit($limit)
            ->get()
            ->each(function (Lesson $lesson) use (&$dispatched, &$failed): void {
                try {
                    DurableJobDispatch::now(new ProbeLessonMedia(
                        (int) $lesson->id,
                        (string) $lesson->bunny_video_id
                    ));
                    $dispatched++;
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                }
            });

        $this->info("Media probes dispatched: {$dispatched}; dispatch failures: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function schemaIsReady(): bool
    {
        return Schema::hasTable('lessons')
            && Schema::hasTable('lesson_media_states')
            && Schema::hasColumn('lessons', 'video_source_type')
            && Schema::hasColumn('lessons', 'bunny_video_id')
            && Schema::hasColumn('lesson_media_states', 'status')
            && Schema::hasColumn('lesson_media_states', 'last_probe_at')
            && Schema::hasColumn('lesson_media_states', 'last_reconciled_at');
    }
}
