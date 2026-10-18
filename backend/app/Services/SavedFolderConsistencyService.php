<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SavedFolder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SavedFolderConsistencyService
{
    public function repairLegacyFolders(int $batch): int
    {
        if (! $this->schemaExists()) {
            return 0;
        }

        $repaired = 0;
        DB::table('saved_folders')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById($batch, function ($folders) use (&$repaired): void {
                foreach ($folders as $folder) {
                    $repaired += $this->repairFolder((int) $folder->id);
                }
            });

        return $repaired;
    }

    public function repairableCount(): int
    {
        if (! $this->schemaExists()) {
            return 0;
        }

        return DB::table('saved_folders')
            ->whereNull('normalized_name')
            ->count();
    }

    private function repairFolder(int $folderId): int
    {
        return DB::transaction(function () use ($folderId): int {
            $folder = DB::table('saved_folders')->where('id', $folderId)->lockForUpdate()->first();
            if (! $folder) {
                return 0;
            }

            DB::table('users')->where('id', $folder->user_id)->lockForUpdate()->first();
            $cleanName = SavedFolder::cleanName($folder->name);
            $normalized = SavedFolder::normalizeName($cleanName);
            $keeper = DB::table('saved_folders')
                ->where('user_id', $folder->user_id)
                ->where('normalized_name', $normalized)
                ->where('id', '<>', $folderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id']);

            if (! $keeper) {
                $displayName = $cleanName !== '' ? $cleanName : 'قائمة محفوظة';
                if (
                    hash_equals((string) $folder->name, $displayName)
                    && hash_equals((string) ($folder->normalized_name ?? ''), $normalized)
                ) {
                    return 0;
                }

                return DB::table('saved_folders')->where('id', $folderId)->update([
                    'name' => $displayName,
                    'normalized_name' => $normalized,
                ]);
            }

            DB::table('saved_folder_lessons')
                ->where('saved_folder_id', $folderId)
                ->select(['id', 'lesson_id', 'created_at', 'updated_at'])
                ->orderBy('id')
                ->chunkById(500, function ($memberships) use ($keeper): void {
                    foreach ($memberships as $membership) {
                        DB::table('saved_folder_lessons')->insertOrIgnore([
                            'saved_folder_id' => (int) $keeper->id,
                            'lesson_id' => $membership->lesson_id,
                            'created_at' => $membership->created_at,
                            'updated_at' => $membership->updated_at,
                        ]);
                    }
                });

            DB::table('saved_folders')->where('id', $folderId)->delete();

            return 1;
        }, 3);
    }

    private function schemaExists(): bool
    {
        return Schema::hasTable('saved_folders')
            && Schema::hasTable('saved_folder_lessons')
            && Schema::hasTable('users')
            && Schema::hasColumn('saved_folders', 'normalized_name');
    }
}
