<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PlaybackOperationsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RecordsRowLocks;
use Tests\TestCase;

final class MediaOperationsTest extends TestCase
{
    use RecordsRowLocks;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('list_id')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
        });
        Schema::create('playback_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('event_type', 24)->default('play');
            $table->string('end_reason', 32)->nullable();
            $table->string('source_protocol', 16)->nullable();
            $table->string('effective_quality', 16)->nullable();
            $table->unsignedSmallInteger('recovery_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
        });

        DB::table('courses')->insert(['id' => 1, 'name_ar' => 'كورس الاختبار']);
        DB::table('lessons')->insert(['id' => 10, 'list_id' => 1, 'title_ar' => 'الخطوة الأولى']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lesson_media_states');
        Schema::dropIfExists('playback_sessions');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('courses');
        parent::tearDown();
    }

    public function test_playback_snapshot_is_privacy_safe_and_classifies_live_and_stale_sessions(): void
    {
        config([
            'operations.playback_active_window_seconds' => 90,
            'operations.playback_stale_after_minutes' => 5,
            'operations.playback_metrics_days' => 7,
        ]);

        $active = (string) Str::uuid();
        $stale = (string) Str::uuid();
        $completed = (string) Str::uuid();
        DB::table('playback_sessions')->insert([
            [
                'id' => $active, 'user_id' => 91, 'lesson_id' => 10,
                'started_at' => now()->subMinutes(2), 'last_heartbeat_at' => now()->subSeconds(20),
                'ended_at' => null, 'event_type' => 'heartbeat', 'end_reason' => null,
                'source_protocol' => 'hls', 'effective_quality' => '720p',
                'recovery_count' => 1, 'last_error_code' => 'buffer_timeout',
                'last_position_seconds' => 24, 'duration_seconds' => 60,
            ],
            [
                'id' => $stale, 'user_id' => 92, 'lesson_id' => 10,
                'started_at' => now()->subMinutes(20), 'last_heartbeat_at' => now()->subMinutes(10),
                'ended_at' => null, 'event_type' => 'heartbeat', 'end_reason' => null,
                'source_protocol' => 'hls', 'effective_quality' => '480p',
                'recovery_count' => 0, 'last_error_code' => null,
                'last_position_seconds' => 10, 'duration_seconds' => 60,
            ],
            [
                'id' => $completed, 'user_id' => 93, 'lesson_id' => 10,
                'started_at' => now()->subHour(), 'last_heartbeat_at' => now()->subMinutes(50),
                'ended_at' => now()->subMinutes(50), 'event_type' => 'complete', 'end_reason' => 'completed',
                'source_protocol' => 'hls', 'effective_quality' => '720p',
                'recovery_count' => 0, 'last_error_code' => null,
                'last_position_seconds' => 60, 'duration_seconds' => 60,
            ],
        ]);

        $snapshot = app(PlaybackOperationsService::class)->snapshot();

        self::assertTrue($snapshot['available']);
        self::assertSame(1, $snapshot['summary']['active']);
        self::assertSame(1, $snapshot['summary']['stale']);
        self::assertSame(1, $snapshot['summary']['completed']);
        self::assertSame(1, $snapshot['summary']['errors']);
        self::assertSame(1, $snapshot['summary']['recovery_sessions']);
        self::assertSame('الخطوة الأولى', $snapshot['latest_errors']->first()['lesson_title']);

        $serialized = json_encode($snapshot['recent_sessions']->all(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('user_id', $serialized);
        self::assertStringNotContainsString('source_host', $serialized);
        self::assertStringNotContainsString('diagnostics', $serialized);
    }

    public function test_terminating_a_stale_session_ends_only_a_silent_open_session_under_its_row_lock(): void
    {
        config(['playback.stale_after_minutes' => 5]);
        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->timestamps();
        });
        $stale = (string) Str::uuid();
        $active = (string) Str::uuid();
        $ended = (string) Str::uuid();
        $row = fn (string $id, int $user, $heartbeat, $endedAt): array => [
            'id' => $id, 'user_id' => $user, 'lesson_id' => 10,
            'started_at' => now()->subMinutes(30), 'last_heartbeat_at' => $heartbeat,
            'ended_at' => $endedAt, 'event_type' => 'heartbeat',
        ];
        DB::table('playback_sessions')->insert([
            $row($stale, 91, now()->subMinutes(25), null),
            $row($active, 92, now()->subSeconds(20), null),
            $row($ended, 93, now()->subMinutes(20), now()->subMinutes(20)),
        ]);
        $operations = app(PlaybackOperationsService::class);

        $result = null;
        $locks = $this->recordLocks(function () use ($operations, $stale, &$result): void {
            $result = $operations->terminateStale($stale);
        });

        self::assertSame('ended', $result);
        self::assertSame(['playback_sessions:update'], $locks);
        $terminated = DB::table('playback_sessions')->where('id', $stale)->first();
        self::assertNotNull($terminated->ended_at);
        self::assertSame('stop', $terminated->event_type);
        self::assertSame('admin_stale_termination', $terminated->end_reason);

        self::assertSame('still_active', $operations->terminateStale($active));
        self::assertNull(DB::table('playback_sessions')->where('id', $active)->value('ended_at'));
        self::assertSame('already_ended', $operations->terminateStale($ended));
        self::assertSame('already_ended', $operations->terminateStale($stale));
        self::assertSame('missing', $operations->terminateStale((string) Str::uuid()));
    }

    public function test_playback_operations_routes_are_admin_only_and_mutations_are_audited(): void
    {
        $index = app('router')->getRoutes()->getByName('admin.playback-operations.index');
        $terminate = app('router')->getRoutes()->getByName('admin.playback-operations.terminate-stale');

        self::assertNotNull($index);
        self::assertNotNull($terminate);
        self::assertSame(['GET', 'HEAD'], $index->methods());
        self::assertSame(['POST'], $terminate->methods());
        self::assertContains('admin.only', $index->gatherMiddleware());
        self::assertContains('admin.only', $terminate->gatherMiddleware());
        self::assertContains('admin.audit', $terminate->gatherMiddleware());
    }

    public function test_media_integrity_migration_is_reversible(): void
    {
        Schema::create('lesson_media_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lesson_id')->unique();
            $table->string('status')->default('unknown');
        });

        $migration = require database_path('migrations/2026_08_11_020000_add_integrity_state_to_lesson_media_states.php');
        $migration->up();

        foreach (['integrity_status', 'integrity_issues', 'last_reconciled_at', 'quarantined_at'] as $column) {
            self::assertTrue(Schema::hasColumn('lesson_media_states', $column));
        }

        $migration->down();
        self::assertFalse(Schema::hasColumn('lesson_media_states', 'integrity_status'));
        Schema::drop('lesson_media_states');
    }
}
