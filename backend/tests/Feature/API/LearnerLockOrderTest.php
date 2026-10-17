<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\PlaybackSession;
use App\Services\BunnyDeliveryService;
use App\Services\CourseCompletionService;
use App\Services\PlaybackManifestService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;

/**
 * SQLite compiles no row locks, so these tests swap in a grammar that marks
 * each locking read with a trailing SQL comment and record the lock type and
 * table in execution order. MySQL/PostgreSQL compile the same builder calls
 * to FOR UPDATE and LOCK IN SHARE MODE / FOR SHARE.
 */
final class LearnerLockOrderTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
    }

    public function test_manifest_locks_the_learner_first_and_shares_course_and_media_rows(): void
    {
        (require database_path('migrations/2026_08_11_020000_add_integrity_state_to_lesson_media_states.php'))->up();
        DB::table('lessons')->where('id', 10)->update([
            'video_source_type' => 'bunny',
            'bunny_video_id' => 'video-guid-10',
        ]);
        DB::table('lesson_media_states')->insert([
            'lesson_id' => 10,
            'provider' => 'bunny',
            'provider_media_id' => 'video-guid-10',
            'status' => 'ready',
            'integrity_status' => 'healthy',
            'last_reconciled_at' => now(),
            'duration_seconds' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $delivery = Mockery::mock(BunnyDeliveryService::class);
        $delivery->shouldReceive('videoPlayback')->andReturn([
            'url' => 'https://vz-test.b-cdn.net/video-guid-10/playlist.m3u8?token=signed',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);
        $this->app->instance(BunnyDeliveryService::class, $delivery);

        $manifest = null;
        $locks = $this->recordLocks(function () use (&$manifest): void {
            $manifest = app(PlaybackManifestService::class)
                ->issue($this->user, Lesson::query()->findOrFail(10));
        });

        self::assertSame(
            ['users:update', 'courses:shared', 'lesson_media_states:shared'],
            $locks
        );
        self::assertSame(1, PlaybackSession::query()->count());
        self::assertSame(
            PlaybackSession::query()->value('id'),
            $manifest['playback_session_id']
        );
    }

    public function test_first_completion_locks_learner_before_sharing_the_course(): void
    {
        $session = $this->enrolledTwoLessonCourse();
        $this->watchUntilEligible($session);

        $response = null;
        $locks = $this->recordLocks(function () use ($session, &$response): void {
            $response = $this->sample($session, 3, 20);
        });

        $response->assertOk()->assertJsonPath(
            'data.section_completion.message',
            'Section marked as completed successfully'
        );
        $courseLocks = array_values(array_filter(
            $locks,
            fn (string $lock): bool => str_starts_with($lock, 'courses:')
        ));
        self::assertSame(['courses:shared'], $courseLocks);
        $courseLock = array_search('courses:shared', $locks, true);
        $completionUserLock = array_keys($locks, 'users:update', true);
        // The heartbeat transaction locks the learner, then completion locks
        // the learner again before it shares the course row.
        self::assertCount(2, array_filter(
            $completionUserLock,
            fn (int $index): bool => $index < $courseLock
        ));
    }

    public function test_rewatch_heartbeat_of_a_completed_section_takes_no_completion_locks(): void
    {
        $session = $this->enrolledTwoLessonCourse();
        $this->watchUntilEligible($session);
        $this->sample($session, 3, 20)->assertOk()->assertJsonPath(
            'data.section_completion.message',
            'Section marked as completed successfully'
        );
        $this->travel(5)->seconds();

        $response = null;
        $locks = $this->recordLocks(function () use ($session, &$response): void {
            $response = $this->sample($session, 4, 25);
        });

        $response->assertOk()
            ->assertJsonPath('data.section_completion.success', true)
            ->assertJsonPath('data.section_completion.message', 'Section already completed')
            ->assertJsonPath('data.section_completion.data.section.id', $this->sectionId)
            ->assertJsonPath('data.section_completion.data.section.is_completed', true)
            ->assertJsonPath('data.section_completion.data.course_progress.is_completed', false);
        // Only the heartbeat's own learner lock remains; completion takes none.
        self::assertSame(1, count(array_keys($locks, 'users:update', true)));
        self::assertSame([], array_values(array_filter(
            $locks,
            fn (string $lock): bool => str_starts_with($lock, 'courses:')
        )));
    }

    public function test_lock_free_already_completed_answer_matches_the_locked_path(): void
    {
        $session = $this->enrolledTwoLessonCourse();
        $this->watchUntilEligible($session);
        $this->sample($session, 3, 20)->assertOk();
        $service = app(CourseCompletionService::class);

        $fast = $service->complete($this->user, $this->courseId, $this->sectionId);
        $locked = DB::transaction(function () use ($service): array {
            $course = Course::query()->whereKey($this->courseId)->sharedLock()->firstOrFail();
            $method = new \ReflectionMethod($service, 'completeWithinCourseLock');

            return $method->invoke($service, $this->user, $course, $this->sectionId);
        });

        self::assertSame('Section already completed', $fast['message']);
        self::assertEquals($locked, $fast);
    }

    public function test_course_completion_never_upgrades_a_shared_course_lock(): void
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
            'duration_seconds' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $session = $this->playbackSession(10, $this->sectionId);
        $this->watchUntilEligible($session);

        $response = null;
        $locks = $this->recordLocks(function () use ($session, &$response): void {
            $response = $this->sample($session, 3, 20);
        });
        $response->assertOk()
            ->assertJsonPath('data.section_completion.data.course_progress.is_completed', true);
        self::assertNotContains('courses:update', $locks);
        self::assertSame(['courses:shared', 'courses:shared'], array_values(array_filter(
            $locks,
            fn (string $lock): bool => str_starts_with($lock, 'courses:')
        )));
        self::assertNotNull(DB::table('course_enrollments')
            ->where('user_id', $this->user->id)
            ->value('completed_curriculum_revision'));

        // A completed course still has an achievement to (idempotently)
        // record, so the explicit call keeps the learner-then-course locks.
        $again = $this->recordLocks(fn () => app(CourseCompletionService::class)
            ->complete($this->user, $this->courseId, $this->sectionId));
        self::assertSame('users:update', $again[0]);
        self::assertSame('courses:shared', $again[1]);
        self::assertNotContains('courses:update', $again);
    }

    private function enrolledTwoLessonCourse(): PlaybackSession
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lessons')->insert([
            'id' => 11,
            'list_id' => $this->courseId,
            'course_id' => $this->courseId,
            'title' => 'Lesson 11',
            'is_opened' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $this->courseId,
            'module_id' => $this->moduleId,
            'title_ar' => 'قسم 2',
            'title_en' => 'Section 2',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => 11,
            'order' => 2,
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([10, 11] as $lessonId) {
            DB::table('lesson_media_states')->insert([
                'lesson_id' => $lessonId,
                'provider' => 'bunny',
                'status' => 'ready',
                'duration_seconds' => 25,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->playbackSession(10, $this->sectionId);
    }

    private function watchUntilEligible(PlaybackSession $session): void
    {
        $this->actingAs($this->user, 'api');
        $this->sample($session, 1, 0)->assertOk();
        $this->travel(10)->seconds();
        $this->sample($session, 2, 10)->assertOk()
            ->assertJsonPath('data.section_completion', null);
        $this->travel(10)->seconds();
    }

    private function playbackSession(int $lessonId, int $sectionId): PlaybackSession
    {
        return PlaybackSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'lesson_id' => $lessonId,
            'course_section_id' => $sectionId,
            'started_at' => now(),
            'last_sequence' => 0,
            'last_position_seconds' => 0,
            'source_protocol' => 'hls',
        ]);
    }

    private function sample(PlaybackSession $session, int $sequence, int $position): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')->postJson('/api/v1/user/watch-history', [
            'lesson_id' => $session->lesson_id,
            'playback_session_id' => $session->id,
            'sequence' => $sequence,
            'position_seconds' => $position,
            'duration_seconds' => 25,
            'event_type' => 'heartbeat',
        ]);
    }

    /** @return list<string> "table:update" or "table:shared" in execution order */
    private function recordLocks(callable $callback): array
    {
        $connection = DB::connection();
        $original = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar {
            protected function compileLock(Builder $query, $value)
            {
                if (is_string($value)) {
                    return $value;
                }

                return $value ? '/* lock:update */' : '/* lock:shared */';
            }
        });
        $locks = [];
        $recording = true;
        DB::listen(function (QueryExecuted $query) use (&$locks, &$recording): void {
            if (
                $recording
                && preg_match('/\/\* lock:(update|shared) \*\/\s*$/', $query->sql, $lock)
                && preg_match('/\sfrom\s+"([a-z_]+)"/', $query->sql, $table)
            ) {
                $locks[] = $table[1] . ':' . $lock[1];
            }
        });

        try {
            $callback();
        } finally {
            $recording = false;
            $connection->setQueryGrammar($original);
        }

        return $locks;
    }
}
