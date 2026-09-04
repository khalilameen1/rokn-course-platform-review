<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PublicDiskUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Final guard against a delayed cleanup deleting a path reused by new data. */
final class StoredFileReferenceService
{
    /** A Stream GUID must not be retired while any live graph still owns it. */
    public function isBunnyStreamVideoReferenced(string $videoGuid): bool
    {
        $videoGuid = strtolower(trim($videoGuid));
        if ($videoGuid === '') {
            return true;
        }

        if (
            Schema::hasTable('course_sections')
            && Schema::hasTable('lessons')
            && DB::table('course_sections')
                ->join('lessons', function ($join): void {
                    $join->on('lessons.id', '=', 'course_sections.sectionable_id')
                        ->where('course_sections.sectionable_type', '=', \App\Models\Lesson::class);
                })
                ->whereNull('course_sections.deleted_at')
                ->whereRaw('LOWER(lessons.bunny_video_id) = ?', [$videoGuid])
                ->exists()
        ) {
            return true;
        }

        // Portfolio media can also use Bunny Stream. Exact GUID matches fail
        // closed even though legacy rows have no provider discriminator.
        return $this->exists('portfolio_media', 'file_path', $videoGuid);
    }

    /**
     * Account erasure retains its media row until the provider confirms the
     * delete. Exclude only that retiring row so a legacy/shared object can
     * never be removed while another portfolio item or lesson still owns it.
     */
    public function isBunnyStreamVideoReferencedElsewhere(
        string $videoGuid,
        int $portfolioMediaId
    ): bool {
        $videoGuid = strtolower(trim($videoGuid));
        if ($videoGuid === '' || $portfolioMediaId <= 0) {
            return true;
        }

        if (
            Schema::hasTable('course_sections')
            && Schema::hasTable('lessons')
            && DB::table('course_sections')
                ->join('lessons', function ($join): void {
                    $join->on('lessons.id', '=', 'course_sections.sectionable_id')
                        ->where('course_sections.sectionable_type', '=', \App\Models\Lesson::class);
                })
                ->whereNull('course_sections.deleted_at')
                ->whereRaw('LOWER(lessons.bunny_video_id) = ?', [$videoGuid])
                ->exists()
        ) {
            return true;
        }

        return Schema::hasTable('portfolio_media')
            && Schema::hasColumn('portfolio_media', 'file_path')
            && DB::table('portfolio_media')
                ->where('id', '<>', $portfolioMediaId)
                ->whereRaw('LOWER(file_path) = ?', [$videoGuid])
                ->exists();
    }

    /** Bunny Storage paths do not carry a Laravel disk column in legacy rows. */
    public function isBunnyStoragePathReferenced(string $path): bool
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return true;
        }

        foreach ([
            ['lessons', 'thumbnail_path'],
            ['portfolio_media', 'file_path'],
            ['portfolio_media', 'thumbnail_path'],
        ] as [$table, $column]) {
            if ($this->exists($table, $column, $path)) {
                return true;
            }
        }

        return false;
    }

    /** Same reference guard while one deleted-account media row is retained. */
    public function isBunnyStoragePathReferencedElsewhere(
        string $path,
        int $portfolioMediaId
    ): bool {
        $path = ltrim(trim($path), '/');
        if ($path === '' || $portfolioMediaId <= 0) {
            return true;
        }

        if ($this->exists('lessons', 'thumbnail_path', $path)) {
            return true;
        }
        if (!Schema::hasTable('portfolio_media')) {
            return false;
        }

        foreach (['file_path', 'thumbnail_path'] as $column) {
            if (
                Schema::hasColumn('portfolio_media', $column)
                && DB::table('portfolio_media')
                    ->where('id', '<>', $portfolioMediaId)
                    ->where($column, $path)
                    ->exists()
            ) {
                return true;
            }
        }

        return false;
    }

    public function isReferenced(string $disk, string $path): bool
    {
        $disk = trim($disk);
        $path = ltrim(trim($path), '/');
        if ($disk === '' || $path === '') {
            return true;
        }

        if ($disk === 'public') {
            foreach ([
                ['photos', 'path'],
                ['users', 'profile_image'],
                ['courses', 'image'],
            ] as [$table, $column]) {
                if ($this->exists($table, $column, $path)) {
                    return true;
                }
            }
            $publicUrl = PublicDiskUrl::from($path);
            foreach ([
                ['notification_campaigns', 'image_url'],
                ['student_notifications', 'image_url'],
                ['design_settings', 'logo_url'],
                ['design_settings', 'icon_url'],
                ['design_settings', 'home_background_url'],
            ] as [$table, $column]) {
                if ($this->exists($table, $column, $publicUrl)) {
                    return true;
                }
            }
        }

        if ($this->existsWithDisk('course_pdfs', 'file_path', 'storage_disk', $disk, $path, 'local')) {
            return true;
        }
        if ($this->existsWithDisk('feedback_attachments', 'path', 'disk', $disk, $path, 'feedback')) {
            return true;
        }
        if ($this->existsWithDisk('ai_input_attachments', 'storage_path', 'storage_disk', $disk, $path, 'local')) {
            return true;
        }
        if (
            $disk === (string) config('certificate.disk', 'public')
            && $this->exists('certificates', 'image_path', $path)
        ) {
            return true;
        }

        if ($this->exists('project_submissions', 'submission_file', $path)) {
            return true;
        }

        return false;
    }

    private function exists(string $table, string $column, string $path): bool
    {
        return Schema::hasTable($table)
            && Schema::hasColumn($table, $column)
            && DB::table($table)->where($column, $path)->exists();
    }

    private function existsWithDisk(
        string $table,
        string $pathColumn,
        string $diskColumn,
        string $disk,
        string $path,
        string $legacyDisk
    ): bool {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $pathColumn)) {
            return false;
        }

        $query = DB::table($table)->where($pathColumn, $path);
        if (!Schema::hasColumn($table, $diskColumn)) {
            return $disk === $legacyDisk && $query->exists();
        }

        return $query->where(function ($disks) use ($disk, $diskColumn, $legacyDisk): void {
            $disks->where($diskColumn, $disk);
            if ($disk === $legacyDisk) {
                $disks->orWhereNull($diskColumn)->orWhere($diskColumn, '');
            }
        })->exists();
    }
}
