<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Photo;
use App\Services\CourseCatalogueRevisionService;
use App\Services\CourseCoverStorageService;
use App\Services\StoredFileDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class GenerateCourseCoverPreviews extends Command
{
    protected $signature = 'courses:generate-cover-previews
        {--limit=100 : Maximum distinct original covers to process}
        {--dry-run : Count missing renditions without reading or changing storage}';

    protected $description = 'Create lightweight renditions of existing course covers while retaining originals';

    public function handle(
        CourseCoverStorageService $covers,
        StoredFileDeletionService $cleanup,
        CourseCatalogueRevisionService $revision
    ): int {
        $pending = Photo::query()->where('photoable_type', Course::class)
            ->where('type', 'featured')->whereNull('preview_path');
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $paths = (clone $pending)->select('path')->distinct()->orderBy('path')->limit($limit)->pluck('path');
        if ($this->option('dry-run')) {
            $this->info('Pending original covers in this batch: '.$paths->count());
            return self::SUCCESS;
        }
        $completed = 0;
        $failed = 0;
        foreach ($paths as $path) {
            $preview = null;
            try {
                $preview = $covers->stagePreviewForStoredPath((string) $path);
                // Cloned authoring revisions can share an immutable original.
                // Attach one rendition to all still-pending owners of that file.
                DB::transaction(function () use ($pending, $path, $preview, $revision): void {
                    $updated = (clone $pending)->where('path', $path)->update(['preview_path' => $preview]);
                    if ($updated > 0) $revision->invalidateAfterCommit();
                }, 3);
                $completed++;
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
            } finally {
                // No-op for committed owners. A race/rollback leaves a normal
                // orphan, retired by the existing reference-aware cleanup owner.
                if ($preview) $cleanup->deleteOrQueue('public', $preview);
            }
        }
        $this->info("Processed: {$completed}; failed: {$failed}. Originals retained.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
