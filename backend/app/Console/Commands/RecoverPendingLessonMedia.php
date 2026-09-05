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
        {--stale-minutes=2 : Minimum age before a pending state is retried}
        {--readiness-window-minutes=90 : Maximum age of a new media generation eligible for automatic recovery}';

    protected $description = 'Re-dispatch Bunny probes for stalled or unreconciled lesson media';

    public function handle(): int
    {
        if (!$this->schemaIsReady()) {
            $this->error('Lesson media tables are not ready. Run migrations first.');
            return self::FAILURE;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $staleMinutes = max(1, min(60, (int) $this->option('stale-minutes')));
        // A released job waits at most five minutes. Ten minutes avoids
        // dispatching a second job while that bounded retry is still alive,
        // for processing and ready-but-not-yet-playable states alike.
        $recoveryCutoff = now()->subMinutes(max(10, $staleMinutes));
        $readinessWindow = max(
            15,
            min(360, (int) $this->option('readiness-window-minutes'))
        );
        $newMediaCutoff = now()->subMinutes($readinessWindow);
        $dispatched = 0;
        $failed = 0;

        Lesson::query()
            ->select(['lessons.id', 'lessons.bunny_video_id'])
            ->where('video_source_type', 'bunny')
            ->whereNotNull('bunny_video_id')
            ->where('bunny_video_id', '!=', '')
            ->where('lessons.updated_at', '>=', $newMediaCutoff)
            ->whereHas('mediaState', function ($state) use (
                $recoveryCutoff
            ): void {
                $state->where(function ($recoverable) use (
                    $recoveryCutoff
                ): void {
                    $recoverable->where(function ($pending) use ($recoveryCutoff): void {
                        $pending->whereIn('status', ['unknown', 'processing'])
                            ->where(function ($errors): void {
                                $errors->whereNull('last_error_code')
                                    ->orWhereIn('last_error_code', [
                                        'provider_unreachable',
                                        'provider_rate_limited',
                                    ]);
                            })
                            ->where(function ($age) use ($recoveryCutoff): void {
                                $age->whereNull('last_probe_at')
                                    ->where('created_at', '<=', $recoveryCutoff)
                                    ->orWhere('last_probe_at', '<=', $recoveryCutoff);
                            });
                    })->orWhere(function ($unreconciled) use ($recoveryCutoff): void {
                        $unreconciled->where('status', 'ready')
                            ->whereNull('last_reconciled_at')
                            ->where(function ($age) use ($recoveryCutoff): void {
                                $age->whereNull('last_probe_at')
                                    ->where('created_at', '<=', $recoveryCutoff)
                                    ->orWhere('last_probe_at', '<=', $recoveryCutoff);
                            });
                    })->orWhere(function ($transient) use ($recoveryCutoff): void {
                        $transient->where('status', 'ready')
                            ->where('integrity_status', 'attention')
                            ->where(function ($errors): void {
                                $errors->whereNull('last_error_code')
                                    ->orWhereIn('last_error_code', [
                                        'provider_unreachable',
                                        'provider_rate_limited',
                                    ]);
                            })
                            ->whereNotNull('last_reconciled_at')
                            ->where('last_reconciled_at', '<=', $recoveryCutoff)
                            ->where(function ($issues): void {
                                foreach (ProbeLessonMedia::TRANSIENT_READINESS_ISSUES as $code) {
                                    $issues->orWhere('integrity_issues', 'like', "%{$code}%");
                                }
                            });
                    });
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
