<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Watch history orders by watched_at so the (user_id[, course_id], watched_at)
 * indexes serve the read. Every write path now sets it; this backfills legacy
 * rows from the timestamp the old COALESCE(watched_at, updated_at) used, in
 * the same resumable chunked shape as
 * 2026_09_01_000029_snapshot_section_completion_time.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('watching_logs') || !Schema::hasColumn('watching_logs', 'watched_at')) {
            return;
        }

        DB::table('watching_logs')
            ->whereNull('watched_at')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                DB::table('watching_logs')
                    ->whereIn('id', $rows->pluck('id'))
                    ->whereNull('watched_at')
                    ->update([
                        'watched_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
                    ]);
            });

        // InnoDB appends the primary key to every secondary index, so these
        // also serve the "watched_at DESC, id DESC" tie-break. They were
        // created in 2026_08_06_000006; restore them if an environment lost them.
        if (!Schema::hasIndex('watching_logs', 'watching_logs_user_watched_index')) {
            Schema::table('watching_logs', fn (Blueprint $table) =>
                $table->index(['user_id', 'watched_at'], 'watching_logs_user_watched_index')
            );
        }
        if (!Schema::hasIndex('watching_logs', 'watching_logs_user_course_watched_index')) {
            Schema::table('watching_logs', fn (Blueprint $table) =>
                $table->index(
                    ['user_id', 'course_id', 'watched_at'],
                    'watching_logs_user_course_watched_index'
                )
            );
        }
    }

    public function down(): void
    {
        // Backfilled timestamps are valid data and the indexes predate this
        // migration; nothing to reverse.
    }
};
