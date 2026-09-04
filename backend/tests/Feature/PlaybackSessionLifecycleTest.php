<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlaybackSession;
use App\Models\User;
use App\Services\PlaybackCapabilityService;
use App\Services\PlaybackMetricsService;
use App\Services\PlaybackSessionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlaybackSessionLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createPlaybackSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('playback_metric_rollups');
        Schema::dropIfExists('playback_sessions');
        parent::tearDown();
    }

    public function test_sequence_is_monotonic_and_a_stop_closes_the_session(): void
    {
        $user = $this->user(41);
        $session = $this->playbackSession($user, 91);
        $service = new PlaybackSessionService(new PlaybackCapabilityService());

        $started = $service->accept($user, 91, [
            'playback_session_id' => $session->id,
            'sequence' => 1,
            'event_type' => 'start',
            'position_seconds' => 0,
            'startup_latency_ms' => 640,
            'buffer_count' => 1,
            'buffer_duration_ms' => 230,
            'effective_bitrate_kbps' => 1800,
            'client_capabilities' => [
                'app_version' => '1.4.0',
                'os' => 'android',
                'connection' => 'cellular',
                'device_id' => 'discard-me',
            ],
        ]);
        self::assertTrue($started['accepted']);
        self::assertSame(0, $started['previous_sample']['position_seconds']);
        self::assertNull($started['previous_sample']['recorded_at']);

        $duplicate = $service->accept($user, 91, [
            'playback_session_id' => $session->id,
            'sequence' => 1,
            'event_type' => 'heartbeat',
            'position_seconds' => 20,
        ]);
        self::assertFalse($duplicate['accepted']);
        self::assertSame('stale_sequence', $duplicate['reason']);

        $stopped = $service->accept($user, 91, [
            'playback_session_id' => $session->id,
            'sequence' => 2,
            'event_type' => 'stop',
            'end_reason' => 'lesson_changed',
            'position_seconds' => 18,
            'buffer_count' => 2,
            'buffer_duration_ms' => 410,
            'recovery_count' => 1,
        ]);
        self::assertTrue($stopped['accepted']);
        self::assertSame(0, $stopped['previous_sample']['position_seconds']);
        self::assertNotNull($stopped['previous_sample']['recorded_at']);

        $session->refresh();
        self::assertNotNull($session->ended_at);
        self::assertSame('lesson_changed', $session->end_reason);
        self::assertSame(2, $session->last_sequence);
        self::assertSame(18, $session->last_position_seconds);
        self::assertSame(640, $session->startup_latency_ms);
        self::assertSame(2, $session->buffer_count);
        self::assertSame('android', $session->os_family);
        self::assertArrayNotHasKey('device_id', $session->client_capabilities);

        $afterEnd = $service->accept($user, 91, [
            'playback_session_id' => $session->id,
            'sequence' => 3,
            'event_type' => 'heartbeat',
        ]);
        self::assertFalse($afterEnd['accepted']);
        self::assertSame('session_ended', $afterEnd['reason']);
    }

    public function test_stale_sessions_are_closed_then_rolled_up_without_a_student_identifier(): void
    {
        $session = $this->playbackSession($this->user(52), 73, [
            'last_heartbeat_at' => now()->subMinutes(20),
            'startup_latency_ms' => 800,
            'buffer_count' => 2,
            'buffer_duration_ms' => 900,
            'recovery_count' => 1,
            'effective_quality' => '720p',
            'effective_bitrate_kbps' => 2100,
            'last_error_code' => 'NETWORK_TIMEOUT',
            'os_family' => 'android',
            'connection_type' => 'cellular',
            'playback_reason' => 'adaptive_hls_cellular',
        ]);
        $metrics = new PlaybackMetricsService();

        self::assertSame(1, $metrics->finalizeStaleSessions(10));
        $session->refresh();
        self::assertSame('stale_timeout', $session->end_reason);
        self::assertNotNull($session->ended_at);

        self::assertSame(1, $metrics->rollupEndedSessions());
        self::assertTrue(Schema::hasTable('playback_metric_rollups'));
        self::assertFalse(Schema::hasColumn('playback_metric_rollups', 'user_id'));
        $this->assertDatabaseHas('playback_metric_rollups', [
            'lesson_id' => 73,
            'session_count' => 1,
            'error_session_count' => 1,
            'buffering_session_count' => 1,
            'buffer_event_count' => 2,
            'recovery_total' => 1,
        ]);

        $summary = $metrics->summary(24, 73);
        self::assertSame(1, $summary['overall']['sessions']);
        self::assertSame(100.0, $summary['overall']['error_rate']);
        self::assertSame(800, $summary['overall']['average_startup_latency_ms']);
        self::assertSame(2100, $summary['overall']['average_effective_bitrate_kbps']);
    }

    public function test_an_expired_session_cannot_be_replayed_with_a_higher_sequence(): void
    {
        $user = $this->user(61);
        $session = $this->playbackSession($user, 81, [
            'started_at' => now()->subHours(13),
        ]);
        $service = new PlaybackSessionService(new PlaybackCapabilityService());

        $expired = $service->accept($user, 81, [
            'playback_session_id' => $session->id,
            'sequence' => 1,
            'event_type' => 'heartbeat',
            'position_seconds' => 10,
        ]);
        self::assertFalse($expired['accepted']);
        self::assertSame('invalid_session', $expired['reason']);

        $session->refresh();
        self::assertSame('session_expired', $session->end_reason);
        self::assertNotNull($session->ended_at);
        self::assertSame(0, $session->last_sequence);

        $replay = $service->accept($user, 81, [
            'playback_session_id' => $session->id,
            'sequence' => 2,
            'event_type' => 'heartbeat',
            'position_seconds' => 20,
        ]);
        self::assertFalse($replay['accepted']);
        self::assertSame('session_ended', $replay['reason']);
        self::assertSame(0, $session->fresh()->last_sequence);
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->forceFill(['id' => $id]);
        $user->exists = true;

        return $user;
    }

    private function playbackSession(User $user, int $lessonId, array $attributes = []): PlaybackSession
    {
        return PlaybackSession::query()->create($attributes + [
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'lesson_id' => $lessonId,
            'started_at' => now()->subMinutes(30),
            'event_type' => 'play',
            'source_protocol' => 'hls',
        ]);
    }

    private function createPlaybackSchema(): void
    {
        Schema::create('playback_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('course_section_id')->nullable();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('started_playing_at')->nullable();
            $table->unsignedInteger('startup_latency_ms')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('metrics_rolled_up_at')->nullable();
            $table->string('event_type', 24)->default('play');
            $table->string('end_reason', 32)->nullable();
            $table->string('source_protocol', 16)->nullable();
            $table->string('effective_quality', 16)->nullable();
            $table->unsignedInteger('effective_bitrate_kbps')->nullable();
            $table->string('source_host', 190)->nullable();
            $table->string('client_name', 24)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('os_family', 12)->default('other');
            $table->string('os_version', 32)->nullable();
            $table->string('connection_type', 12)->default('unknown');
            $table->json('client_capabilities')->nullable();
            $table->string('playback_reason', 48)->nullable();
            $table->timestamp('source_expires_at')->nullable();
            $table->decimal('playback_rate', 4, 2)->default(1);
            $table->unsignedSmallInteger('recovery_count')->default(0);
            $table->unsignedSmallInteger('buffer_count')->default(0);
            $table->unsignedInteger('buffer_duration_ms')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamps();
        });

        Schema::create('playback_metric_rollups', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('bucket_start');
            $table->unsignedBigInteger('lesson_id')->default(0);
            $table->string('os_family', 12)->default('other');
            $table->string('connection_type', 12)->default('unknown');
            $table->string('effective_quality', 12)->default('unknown');
            $table->string('playback_reason', 48)->default('unknown');
            $table->string('error_code', 64)->default('none');
            $table->unsignedBigInteger('session_count')->default(0);
            $table->unsignedBigInteger('completed_count')->default(0);
            $table->unsignedBigInteger('error_session_count')->default(0);
            $table->unsignedBigInteger('buffering_session_count')->default(0);
            $table->unsignedBigInteger('startup_sample_count')->default(0);
            $table->unsignedBigInteger('startup_latency_total_ms')->default(0);
            $table->unsignedInteger('startup_latency_max_ms')->default(0);
            $table->unsignedBigInteger('buffer_event_count')->default(0);
            $table->unsignedBigInteger('buffer_duration_total_ms')->default(0);
            $table->unsignedBigInteger('recovery_total')->default(0);
            $table->unsignedBigInteger('bitrate_sample_count')->default(0);
            $table->unsignedBigInteger('bitrate_total_kbps')->default(0);
            $table->timestamps();
        });
    }
}
