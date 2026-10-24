<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\CourseAuthoringRevision;
use App\Models\InternalSignal;
use App\Models\Lesson;
use App\Models\PlaybackSession;
use App\Services\InternalSignalHandler;
use App\Services\WatchHistoryCanonicalizationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /user/watch-history is always a safe read (RFC 9110): superseded resume
 * rows are merged by the publish signal and by the heartbeat write path, and
 * an accepted heartbeat that changes no resume state does not rewrite the
 * resume row.
 */
final class WatchHistoryReadWritePathTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
    }

    public function test_history_read_never_merges_and_the_queued_signal_does(): void
    {
        $archiveCourseId = $this->archivePublishedLesson(oldLessonId: 20, currentLessonId: 10);
        DB::table('watching_logs')->insert($this->logRow(20, $archiveCourseId, [
            'position_seconds' => 42,
            'duration_seconds' => 120,
            'watched_at' => now()->subHour(),
        ]));

        // GET is safe (RFC 9110): before the merge the superseded row is
        // hidden and left in place, and nothing is written.
        $before = $this->recordQueries(fn () => $this->history()->assertOk()
            ->assertJsonCount(0, 'data.items'));
        self::assertSame([], $this->writes($before));
        $this->assertDatabaseHas('watching_logs', ['user_id' => $this->user->id, 'lesson_id' => 20]);

        // Courses published before the publish signal existed get the same
        // durable signal from the release migration.
        $migration = require database_path('migrations/2026_10_18_100100_queue_watch_history_canonicalization.php');
        $migration->up();
        $migration->up();
        $signals = InternalSignal::query()->where('type', 'course.watch_history.canonicalize')->get();
        self::assertCount(1, $signals);
        self::assertSame(
            ['after_user_id' => 0, 'course_id' => $this->courseId, 'published_revision' => 2],
            $signals->first()->payload
        );
        app(InternalSignalHandler::class)->handle($signals->first());
        $this->assertDatabaseMissing('watching_logs', ['user_id' => $this->user->id, 'lesson_id' => 20]);

        $after = $this->recordQueries(fn () => $this->history()->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.lesson_id', 10)
            ->assertJsonPath('data.items.0.course_id', $this->courseId)
            ->assertJsonPath('data.items.0.position_seconds', 42));
        self::assertSame([], $this->writes($after));
        self::assertSame([], $this->matching($after, 'course_authoring_revision_entities'));
    }

    public function test_a_new_resume_row_merges_a_superseded_row_that_landed_after_the_publish_merge(): void
    {
        $session = $this->enrolledSession();
        $archiveCourseId = $this->archivePublishedLesson(oldLessonId: 20, currentLessonId: 10);
        // A heartbeat that read lesson 20 before the publish committed and
        // inserted its row after the publish merge had passed this learner.
        DB::table('watching_logs')->insert($this->logRow(20, $archiveCourseId, [
            'position_seconds' => 42,
            'watched_at' => now()->subMinute(),
        ]));

        $this->sample($session, 1, 5)->assertOk()->assertJsonPath('data.recorded', true);

        $this->assertDatabaseMissing('watching_logs', ['user_id' => $this->user->id, 'lesson_id' => 20]);
        self::assertSame(1, DB::table('watching_logs')->where('user_id', $this->user->id)->count());
        $this->history()->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.lesson_id', 10);
    }

    public function test_the_publish_signal_merges_history_so_the_first_read_writes_nothing(): void
    {
        $archiveCourseId = $this->archivePublishedLesson(oldLessonId: 20, currentLessonId: 10);
        DB::table('watching_logs')->insert($this->logRow(20, $archiveCourseId, [
            'position_seconds' => 42,
            'duration_seconds' => 120,
            'watched_at' => now()->subHour(),
        ]));

        $signal = new InternalSignal([
            'type' => 'course.watch_history.canonicalize',
            'payload' => ['course_id' => $this->courseId, 'published_revision' => 2, 'after_user_id' => 0],
        ]);
        app(InternalSignalHandler::class)->handle($signal);
        $this->assertDatabaseMissing('watching_logs', ['user_id' => $this->user->id, 'lesson_id' => 20]);
        // One learner, fewer than a batch: no continuation signal.
        self::assertSame(0, InternalSignal::query()->where('type', 'course.watch_history.canonicalize')->count());

        $first = $this->recordQueries(fn () => $this->history()->assertOk()
            ->assertJsonPath('data.items.0.lesson_id', 10)
            ->assertJsonPath('data.items.0.position_seconds', 42));
        self::assertSame([], $this->writes($first));
    }

    public function test_the_publish_merge_continues_with_a_cursor_when_a_batch_is_full(): void
    {
        $archiveCourseId = $this->archivePublishedLesson(oldLessonId: 20, currentLessonId: 10);
        DB::table('watching_logs')->insert($this->logRow(20, $archiveCourseId));

        $service = app(WatchHistoryCanonicalizationService::class);
        self::assertSame((int) $this->user->id, $service->afterPublish($this->courseId, 0, 1));
        self::assertNull($service->afterPublish($this->courseId, (int) $this->user->id, 1));
        $this->assertDatabaseMissing('watching_logs', ['user_id' => $this->user->id, 'lesson_id' => 20]);
    }

    public function test_history_orders_by_watched_at_then_id_without_a_count(): void
    {
        $this->addLesson(11, 2);
        $this->addLesson(12, 3);
        // updated_at deliberately disagrees with watched_at: recency is the
        // explicit watch time, ties broken by the newest row.
        DB::table('watching_logs')->insert([
            $this->logRow(10, $this->courseId, ['watched_at' => now()->subHour(), 'updated_at' => now()]),
            $this->logRow(11, $this->courseId, ['watched_at' => now()->subMinutes(5), 'updated_at' => now()->subDays(2)]),
            $this->logRow(12, $this->courseId, ['watched_at' => now()->subMinutes(5), 'updated_at' => now()->subDays(3)]),
        ]);

        $queries = $this->recordQueries(fn () => $this->history(['per_page' => 2])->assertOk()
            ->assertJsonPath('data.items.0.lesson_id', 12)
            ->assertJsonPath('data.items.1.lesson_id', 11)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.has_more_pages', true)
            ->assertJsonMissingPath('data.pagination.total'));

        $page = $this->matching($queries, 'from "watching_logs" where "user_id"');
        $page = array_values(array_filter($page, fn (string $sql): bool => str_contains($sql, 'exists')));
        self::assertCount(1, $page);
        self::assertStringContainsString(
            'order by "watching_logs"."watched_at" desc, "watching_logs"."id" desc',
            $page[0]
        );
        self::assertStringNotContainsStringIgnoringCase('coalesce', $page[0]);
        self::assertSame([], $this->matching($queries, 'count(*) as aggregate from "watching_logs"'));

        $this->history(['per_page' => 2, 'page' => 2])->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.lesson_id', 10)
            ->assertJsonPath('data.pagination.has_more_pages', false);
    }

    public function test_recency_backfill_and_index_serve_the_history_order(): void
    {
        DB::table('watching_logs')->insert($this->logRow(10, $this->courseId, [
            'watched_at' => null,
            'updated_at' => '2026-09-30 08:15:00',
        ]));

        $migration = require database_path('migrations/2026_10_17_131500_backfill_watch_history_recency.php');
        $migration->up();
        $migration->up();

        self::assertSame(
            '2026-09-30 08:15:00',
            (string) DB::table('watching_logs')->where('lesson_id', 10)->value('watched_at')
        );
        $plan = collect(DB::select(
            'EXPLAIN QUERY PLAN SELECT id FROM watching_logs WHERE user_id = ? '
            .'ORDER BY watched_at DESC, id DESC LIMIT 7',
            [$this->user->id]
        ))->pluck('detail')->implode(' | ');
        self::assertStringContainsString('watching_logs_user_watched_index', $plan);
        self::assertStringNotContainsString('TEMP B-TREE', $plan);
    }

    public function test_heartbeat_without_new_resume_state_does_not_rewrite_the_resume_row(): void
    {
        $session = $this->enrolledSession();
        $this->sample($session, 1, 0)->assertOk();
        $this->travel(10)->seconds();
        $this->sample($session, 2, 10)->assertOk()->assertJsonPath('data.position_seconds', 10);
        $before = (array) DB::table('watching_logs')->where('lesson_id', 10)->first();
        $this->travel(10)->seconds();

        $unchanged = $this->recordQueries(fn () => $this->sample($session, 3, 10)->assertOk()
            ->assertJsonPath('data.recorded', true)
            ->assertJsonPath('data.position_seconds', 10));

        self::assertSame([], $this->matching($this->writes($unchanged), 'watching_logs'));
        // Evidence and the playback session keep their anti-replay clocks.
        self::assertNotSame([], $this->matching($this->writes($unchanged), 'update "playback_sessions"'));
        self::assertNotSame([], $this->matching($this->writes($unchanged), 'update "lesson_watch_evidence"'));
        // The lesson row is read once and existence is not probed separately.
        self::assertSame([], $this->matching($unchanged, 'count(*) as aggregate from "lessons"'));
        self::assertSame([], $this->matching($unchanged, 'insert or ignore into "watching_logs"'));
        self::assertSame($before, (array) DB::table('watching_logs')->where('lesson_id', 10)->first());
        self::assertSame(3, (int) PlaybackSession::query()->whereKey($session->id)->value('last_sequence'));
        self::assertSame(
            now()->toDateTimeString(),
            (string) DB::table('lesson_watch_evidence')->where('lesson_id', 10)->value('last_heartbeat_at')
        );

        // Moving the playhead is resume state and is written.
        $this->travel(10)->seconds();
        $this->sample($session, 4, 20)->assertOk()->assertJsonPath('data.position_seconds', 20);
        $this->assertDatabaseHas('watching_logs', [
            'lesson_id' => 10,
            'position_seconds' => 20,
            'last_playback_sequence' => 4,
            'watched_at' => now()->toDateTimeString(),
        ]);

        // Another device at the same position takes the resume pointer over.
        $this->travel(5)->seconds();
        $other = $this->playbackSession();
        $this->sample($other, 1, 20)->assertOk();
        $this->assertDatabaseHas('watching_logs', [
            'lesson_id' => 10,
            'position_seconds' => 20,
            'playback_session_id' => $other->id,
            'watched_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_first_heartbeat_creates_a_timestamped_resume_row_and_unknown_lessons_stay_422(): void
    {
        $session = $this->enrolledSession();
        $this->sample($session, 1, 0)->assertOk()->assertJsonPath('data.recorded', true);
        $this->assertDatabaseHas('watching_logs', [
            'user_id' => $this->user->id,
            'lesson_id' => 10,
            'playback_session_id' => $session->id,
            'last_playback_sequence' => 1,
        ]);
        self::assertNotNull(DB::table('watching_logs')->where('lesson_id', 10)->value('watched_at'));

        $this->actingAs($this->user, 'api')->postJson('/api/v1/user/watch-history', [
            'lesson_id' => 999,
            'playback_session_id' => (string) Str::uuid(),
            'sequence' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['lesson_id']);
    }

    public function test_seeking_to_the_end_then_rewatching_can_complete_in_the_same_session(): void
    {
        $session = $this->enrolledSession();
        DB::table('lesson_media_states')->where('lesson_id', 10)->update(['duration_seconds' => 60]);
        $this->sample($session, 1, 0)->assertOk();
        $this->travel(1)->seconds();
        $this->actingAs($this->user, 'api')->postJson('/api/v1/user/watch-history', [
            'lesson_id' => 10, 'playback_session_id' => $session->id, 'sequence' => 2,
            'position_seconds' => 57, 'duration_seconds' => 60,
            'is_completed' => true, 'event_type' => 'complete',
        ])->assertOk();
        self::assertNull($session->fresh()->ended_at);
        $complete = "/api/v1/courses/{$this->courseId}/sections/{$this->sectionId}/complete";
        $this->postJson($complete)->assertStatus(409)->assertJsonPath('code', 'verified_watch_required');

        $this->sample($session, 3, 0)->assertOk();
        $this->travel(25)->seconds();
        $this->sample($session, 4, 25)->assertOk();
        $this->travel(25)->seconds();
        $this->sample($session, 5, 50)->assertOk();
        $this->postJson($complete)->assertOk();
        self::assertNull($session->fresh()->ended_at);
        self::assertSame(5, $session->fresh()->last_sequence);
    }

    public static function normalPlaybackSpeeds(): array
    {
        return [[1.0], [1.25], [1.5], [2.0]];
    }

    #[DataProvider('normalPlaybackSpeeds')]
    public function test_a_single_watch_at_every_supported_speed_completes_with_thirty_second_heartbeats(float $speed): void
    {
        $session = $this->enrolledSession();
        DB::table('lesson_media_states')->where('lesson_id', 10)->update(['duration_seconds' => 60]);
        $this->sample($session, 1, 0)->assertOk();
        $sequence = 1;
        $elapsed = 0;
        $last = (int) ceil(57 / $speed);
        while ($elapsed < $last) {
            $gap = min(30, $last - $elapsed);
            $this->travel($gap)->seconds();
            $elapsed += $gap;
            $position = min(57, (int) floor($elapsed * $speed));
            $this->actingAs($this->user, 'api')->postJson('/api/v1/user/watch-history', [
                'lesson_id' => 10, 'playback_session_id' => $session->id, 'sequence' => ++$sequence,
                'position_seconds' => $position, 'duration_seconds' => 60, 'playback_rate' => $speed,
                'is_completed' => $position === 57, 'event_type' => $position === 57 ? 'complete' : 'heartbeat',
            ])->assertOk();
        }
        self::assertSame(57, (int) DB::table('lesson_watch_evidence')->where('user_id', $this->user->id)->value('verified_seconds'));
        $this->postJson("/api/v1/courses/{$this->courseId}/sections/{$this->sectionId}/complete")->assertOk();
    }

    private function history(array $query = []): TestResponse
    {
        return $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/user/watch-history'.($query ? '?'.http_build_query($query) : ''));
    }

    private function enrolledSession(): PlaybackSession
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lesson_media_states')->insert([
            'lesson_id' => 10,
            'provider' => 'bunny',
            'status' => 'ready',
            'duration_seconds' => 600,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->playbackSession();
    }

    private function playbackSession(): PlaybackSession
    {
        return PlaybackSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'lesson_id' => 10,
            'course_section_id' => $this->sectionId,
            'started_at' => now(),
            'last_sequence' => 0,
            'last_position_seconds' => 0,
            'source_protocol' => 'hls',
        ]);
    }

    private function sample(PlaybackSession $session, int $sequence, int $position): TestResponse
    {
        return $this->actingAs($this->user, 'api')->postJson('/api/v1/user/watch-history', [
            'lesson_id' => 10,
            'playback_session_id' => $session->id,
            'sequence' => $sequence,
            'position_seconds' => $position,
            'duration_seconds' => 600,
            'event_type' => 'heartbeat',
        ]);
    }

    private function addLesson(int $lessonId, int $order, ?int $courseId = null): int
    {
        $courseId ??= $this->courseId;
        DB::table('lessons')->insert([
            'id' => $lessonId,
            'list_id' => $courseId,
            'course_id' => $courseId,
            'title' => "Lesson {$lessonId}",
            'is_opened' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('course_sections')->insertGetId([
            'course_id' => $courseId,
            'module_id' => $this->moduleId,
            'title_ar' => "قسم {$order}",
            'title_en' => "Section {$order}",
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lessonId,
            'order' => $order,
            'sort_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Mirror a publish: the old lesson lives on an archive course row. */
    private function archivePublishedLesson(int $oldLessonId, int $currentLessonId): int
    {
        $archiveCourseId = $this->createArchiveCourse();
        $this->addLesson($oldLessonId, 1, $archiveCourseId);
        $revisionId = $this->archiveRevision($this->courseId, $archiveCourseId);
        DB::table('course_authoring_revision_entities')->insert([
            'course_authoring_revision_id' => $revisionId,
            'entity_type' => Lesson::class,
            'source_entity_id' => $oldLessonId,
            'revision_entity_id' => $currentLessonId,
            'survives_publish' => true,
            'carries_learner_state' => true,
            'learner_root_entity_id' => $oldLessonId,
        ]);

        return $archiveCourseId;
    }

    private function createArchiveCourse(): int
    {
        return (int) DB::table('courses')->insertGetId([
            'name_ar' => 'نسخة مؤرشفة',
            'name_en' => 'Archived revision',
            'grade_id' => $this->gradeId,
            'price' => 100.00,
            'active' => 1,
            'is_main_course' => 0,
            'is_coming_soon' => 1,
            'is_catalog_visible' => 0,
            'course_type' => 'online',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function archiveRevision(int $canonicalCourseId, int $archiveCourseId): int
    {
        $this->travel(1)->seconds();

        return (int) DB::table('course_authoring_revisions')->insertGetId([
            'canonical_course_id' => $canonicalCourseId,
            'revision_course_id' => $archiveCourseId,
            'base_authoring_version' => 1,
            'published_authoring_version' => 2,
            'status' => CourseAuthoringRevision::ARCHIVED,
            'clone_key' => (string) Str::uuid(),
            'published_at' => now(),
            'retain_until' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function logRow(int $lessonId, int $courseId, array $overrides = []): array
    {
        return $overrides + [
            'user_id' => $this->user->id,
            'lesson_id' => $lessonId,
            'lesson_name' => "Lesson {$lessonId}",
            'course_id' => $courseId,
            'course_section_id' => null,
            'course_name' => 'دورة تجريبية',
            'position_seconds' => 0,
            'duration_seconds' => null,
            'watched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return list<string> */
    private function recordQueries(callable $callback): array
    {
        $queries = [];
        $recording = true;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $query->sql;
            }
        });
        try {
            $callback();
        } finally {
            $recording = false;
        }

        return $queries;
    }

    /** @param list<string> $queries @return list<string> */
    private function writes(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1
        ));
    }

    /** @param list<string> $queries @return list<string> */
    private function matching(array $queries, string $needle): array
    {
        return array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, $needle)
        ));
    }
}
