<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CoursePdf;
use App\Support\StorageWriteOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class MigrateCoursePdfsToSharedStorage extends Command
{
    protected $signature = 'course-pdfs:migrate-storage
        {--execute : Copy, verify and update records; otherwise audit only}
        {--delete-source : Delete a source only after verification and after its last database reference moves}
        {--limit=0 : Maximum rows to inspect; zero means all}';

    protected $description = 'Audit or safely move legacy course PDFs to the configured private shared disk';

    public function handle(): int
    {
        $targetName = trim((string) config('course_pdfs.disk'));
        $targetConfig = config("filesystems.disks.{$targetName}");
        if ($targetName === '' || in_array($targetName, ['local', 'public'], true) || !is_array($targetConfig)) {
            $this->error('COURSE_PDF_DISK must name a configured private shared disk.');
            return self::FAILURE;
        }
        if (
            strtolower((string) ($targetConfig['driver'] ?? '')) !== 's3'
            && ($targetConfig['visibility'] ?? null) === 'public'
        ) {
            $this->error('COURSE_PDF_DISK must not have public visibility.');
            return self::FAILURE;
        }

        $query = CoursePdf::withTrashed()
            ->where('source_type', 'upload')
            ->where(function ($query) use ($targetName): void {
                $query->whereNull('storage_disk')->orWhere('storage_disk', '<>', $targetName);
            })
            ->orderBy('id');
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $pdfs = $query->get();
        if ($pdfs->isEmpty()) {
            $this->info('No legacy course PDFs require migration.');
            return self::SUCCESS;
        }

        if (!(bool) $this->option('execute')) {
            $this->warn("{$pdfs->count()} course PDF(s) require migration to {$targetName}.");
            $this->line('Run with --execute after confirming shared private storage; add --delete-source only after a backup.');
            return self::SUCCESS;
        }

        $target = Storage::disk($targetName);
        $migrated = 0;
        $failed = 0;

        foreach ($pdfs as $pdf) {
            $sourceName = trim((string) $pdf->getRawOriginal('storage_disk')) ?: 'local';
            $sourcePath = ltrim((string) $pdf->file_path, '/');
            $extension = (string) $pdf->file_extension;
            $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : 'bin';
            $targetPath = 'courses/' . (int) $pdf->course_id . '/' . Str::uuid() . '.' . $extension;
            $metadataUpdated = false;

            try {
                if ($sourcePath === '') {
                    throw new \RuntimeException('source path is empty');
                }

                $source = Storage::disk($sourceName);
                if (!$source->exists($sourcePath)) {
                    throw new \RuntimeException("source object is missing on {$sourceName}");
                }

                $stream = $source->readStream($sourcePath);
                if (!is_resource($stream)) {
                    throw new \RuntimeException('could not open source stream');
                }
                try {
                    if (!$target->put(
                        $targetPath,
                        $stream,
                        StorageWriteOptions::forDisk($targetName, 'private')
                    )) {
                        throw new \RuntimeException('shared-storage write failed');
                    }
                } finally {
                    fclose($stream);
                }

                if (!$target->exists($targetPath) || (int) $target->size($targetPath) !== (int) $source->size($sourcePath)) {
                    throw new \RuntimeException('shared-storage copy verification failed');
                }

                $updated = CoursePdf::withTrashed()
                    ->whereKey($pdf->id)
                    ->where('file_path', $sourcePath)
                    ->where(function ($query) use ($sourceName): void {
                        if ($sourceName === 'local') {
                            $query->whereNull('storage_disk')->orWhere('storage_disk', 'local');
                        } else {
                            $query->where('storage_disk', $sourceName);
                        }
                    })
                    ->update([
                        'file_path' => $targetPath,
                        'storage_disk' => $targetName,
                    ]);
                if ($updated !== 1) {
                    $target->delete($targetPath);
                    throw new \RuntimeException('record changed concurrently; copied object was rolled back');
                }
                $metadataUpdated = true;

                if ((bool) $this->option('delete-source')) {
                    if ($this->remainingReferences($sourceName, $sourcePath) === 0 && !$source->delete($sourcePath)) {
                        throw new \RuntimeException('verified copy is active, but source cleanup failed');
                    }
                }

                $migrated++;
            } catch (Throwable $exception) {
                $failed++;
                if (!$metadataUpdated) {
                    try {
                        $target->delete($targetPath);
                    } catch (Throwable) {
                        // Report the original migration failure below.
                    }
                }
                $this->error("#{$pdf->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Migrated {$migrated}; failed {$failed}.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function remainingReferences(string $disk, string $path): int
    {
        return CoursePdf::withTrashed()
            ->where('file_path', $path)
            ->where(function ($query) use ($disk): void {
                if ($disk === 'local') {
                    $query->whereNull('storage_disk')->orWhere('storage_disk', 'local');
                } else {
                    $query->where('storage_disk', $disk);
                }
            })
            ->count();
    }
}
