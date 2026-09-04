<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\MediaReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReconcileMedia extends Command
{
    protected $signature = 'media:reconcile
        {--dry-run : Inspect without changing media-state rows}
        {--course= : Inspect one published course ID}
        {--limit=0 : Maximum published courses to inspect; zero means all}
        {--batch= : Courses loaded per batch}
        {--skip-manifest-fetch : Verify manifest issuance only, without fetching the HLS document}';

    protected $description = 'Reconcile published Bunny videos and thumbnails without deleting content';

    public function handle(MediaReconciliationService $reconciler): int
    {
        if (!$this->schemaIsReady()) {
            $this->error('Media control-plane tables or integrity columns are missing. Run migrations first.');
            return self::FAILURE;
        }

        $lock = Cache::lock(
            (string) config('operations.media_reconcile_lock_key', 'operations:media-reconcile:lock:v1'),
            max(300, (int) config('operations.media_reconcile_lock_seconds', 10800))
        );
        $acquired = $this->acquire($lock);
        if ($acquired === null) {
            $this->error('The distributed cache lock is unavailable; reconciliation did not start.');
            return self::FAILURE;
        }
        if (!$acquired) {
            $this->warn('Another media reconciliation is already running.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $fetchManifest = !(bool) $this->option('skip-manifest-fetch')
            && (bool) config('operations.media_reconcile_fetch_manifest', true);
        $batch = $this->option('batch') !== null
            ? (int) $this->option('batch')
            : (int) config('operations.media_reconcile_batch_size', 25);
        $batch = max(1, min(200, $batch));
        $limit = max(0, (int) $this->option('limit'));
        $statusKey = (string) config(
            'operations.media_reconcile_status_key',
            'operations:media-reconcile:status:v1'
        );
        $startedAt = now()->toIso8601String();

        try {
            $query = Course::query()
                ->where('is_coming_soon', false)
                ->orderBy('id');
            if ($this->option('course') !== null) {
                $courseId = filter_var($this->option('course'), FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if ($courseId === false) {
                    $this->error('The --course option must be a positive integer.');
                    return self::INVALID;
                }
                $query->whereKey($courseId);
            }
            if ($limit > 0) {
                $query->limit($limit);
            }

            $courseIds = $query->pluck('id');
            $total = $courseIds->count();
            $summary = [
                'courses' => 0,
                'lessons' => 0,
                'healthy' => 0,
                'attention' => 0,
                'quarantined' => 0,
                'issues' => 0,
            ];
            $this->writeStatus($statusKey, [
                'state' => 'running',
                'mode' => $dryRun ? 'dry-run' : 'apply',
                'started_at' => $startedAt,
                'finished_at' => null,
                'processed_courses' => 0,
                'total_courses' => $total,
                'summary' => $summary,
            ]);

            if ($total === 0) {
                $this->info('No published courses matched the reconciliation scope.');
            }
            $progress = $this->output->createProgressBar($total);
            $progress->start();

            foreach ($courseIds->chunk($batch) as $idBatch) {
                $courses = Course::query()
                    ->whereIn('id', $idBatch->all())
                    ->orderBy('id')
                    ->get();
                foreach ($courses as $course) {
                    $result = $reconciler->reconcileCourse(
                        $course,
                        !$dryRun,
                        $fetchManifest
                    );
                    $summary['courses']++;
                    $summary['lessons'] += (int) $result['lessons'];
                    $summary['issues'] += (int) $result['issues'];
                    foreach (['healthy', 'attention', 'quarantined'] as $status) {
                        $summary[$status] += (int) ($result['counts'][$status] ?? 0);
                    }

                    $this->writeStatus($statusKey, [
                        'state' => 'running',
                        'mode' => $dryRun ? 'dry-run' : 'apply',
                        'started_at' => $startedAt,
                        'finished_at' => null,
                        'processed_courses' => $summary['courses'],
                        'total_courses' => $total,
                        'summary' => $summary,
                    ]);
                    $progress->advance();
                }
                unset($courses);
            }

            $progress->finish();
            $this->newLine(2);
            $this->table(
                ['Courses', 'Lessons', 'Healthy', 'Attention', 'Quarantined', 'Issues'],
                [[
                    $summary['courses'], $summary['lessons'], $summary['healthy'],
                    $summary['attention'], $summary['quarantined'], $summary['issues'],
                ]]
            );

            $this->writeStatus($statusKey, [
                'state' => ($summary['attention'] + $summary['quarantined']) > 0 ? 'attention' : 'completed',
                'mode' => $dryRun ? 'dry-run' : 'apply',
                'started_at' => $startedAt,
                'finished_at' => now()->toIso8601String(),
                'processed_courses' => $summary['courses'],
                'total_courses' => $total,
                'summary' => $summary,
            ]);

            // Findings are represented in the dashboard, not by destructive
            // automation. A successful command means the audit completed.
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->writeStatus($statusKey, [
                'state' => 'failed',
                'mode' => $dryRun ? 'dry-run' : 'apply',
                'finished_at' => now()->toIso8601String(),
                'error_code' => 'media_reconcile_failed',
            ]);
            $this->error('Media reconciliation failed. Check the application log using the request time above.');
            return self::FAILURE;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function schemaIsReady(): bool
    {
        foreach ([
            'courses', 'lessons', 'lesson_media_states', 'photos',
            'course_modules', 'course_sections',
        ] as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }
        foreach (['id', 'is_coming_soon'] as $column) {
            if (!Schema::hasColumn('courses', $column)) {
                return false;
            }
        }

        foreach ([
            'integrity_status', 'integrity_issues', 'last_reconciled_at',
            'quarantined_at', 'probe_generation',
        ] as $column) {
            if (!Schema::hasColumn('lesson_media_states', $column)) {
                return false;
            }
        }
        foreach ([
            'list_id', 'video_source_type', 'bunny_video_id',
            'thumbnail_path', 'duration_minutes',
        ] as $column) {
            if (!Schema::hasColumn('lessons', $column)) {
                return false;
            }
        }

        return true;
    }

    private function acquire(Lock $lock): ?bool
    {
        try {
            return $lock->get();
        } catch (Throwable $exception) {
            report($exception);
            return null;
        }
    }

    /** @param array<string, mixed> $status */
    private function writeStatus(string $key, array $status): void
    {
        try {
            Cache::put($key, $status, now()->addDays(14));
        } catch (Throwable $exception) {
            // The audit is still useful when the dashboard cache is briefly
            // unavailable; the error is observable without aborting probes.
            report($exception);
        }
    }
}
