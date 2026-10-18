<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history GET no longer merges resume rows written against a superseded
 * revision (RFC 9110: GET is safe). Courses published before the publish
 * signal existed may still hold such rows, so queue the same durable signal
 * publishing records ('course.watch_history.canonicalize', transactional
 * outbox) once per course with an archived revision. The row matches what
 * InternalSignalService::record writes for a publish of that revision (same
 * key, payload and fingerprint), so a course whose latest publish already
 * queued the merge is not queued twice. Pending rows are dispatched by the
 * scheduled internal-signals:maintain command. Query layer only, so this
 * forward migration does not depend on mutable application code.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TYPE = 'course.watch_history.canonicalize';

    public function up(): void
    {
        if (! Schema::hasTable('course_authoring_revisions')
            || ! Schema::hasTable('courses')
            || ! Schema::hasTable('internal_signals')) {
            return;
        }

        $courses = DB::table('course_authoring_revisions')
            ->where('status', 'archived')
            ->whereNotNull('canonical_course_id')
            ->groupBy('canonical_course_id')
            ->orderBy('canonical_course_id')
            ->selectRaw('canonical_course_id, MAX(published_authoring_version) as published_revision')
            ->get();
        foreach ($courses as $course) {
            $courseId = (int) $course->canonical_course_id;
            $revision = (int) ($course->published_revision
                ?: DB::table('courses')->where('id', $courseId)->value('last_published_authoring_version'));
            if ($courseId <= 0 || $revision <= 0) {
                continue;
            }
            // Keys sorted as InternalSignalService::normalize() sorts them.
            $payload = ['after_user_id' => 0, 'course_id' => $courseId, 'published_revision' => $revision];
            $fingerprintSource = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            DB::table('internal_signals')->insertOrIgnore([
                'signal_key' => hash('sha256', self::TYPE.'|'."course:{$courseId}:revision:{$revision}:after:0"),
                'type' => self::TYPE,
                'aggregate_type' => 'App\\Models\\Course',
                'aggregate_id' => (string) $courseId,
                'payload_fingerprint' => hash('sha256', $fingerprintSource),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Queued merges are idempotent data repairs; nothing to reverse.
    }
};
