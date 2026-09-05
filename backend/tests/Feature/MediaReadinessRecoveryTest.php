<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Jobs\ProbeLessonMedia;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\User;
use App\Services\BunnyService;
use App\Services\MediaHealthService;
use App\Services\MediaReconciliationService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MediaReadinessRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_video_probe_completes_the_same_reconciliation_required_by_publish(): void
    {
        $administrator = new User();
        $administrator->forceFill([
            'name_ar' => 'مدير التشغيل',
            'email' => 'media-readiness@example.test',
            'role' => 'admin',
            'active' => true,
        ])->save();
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس فيديو',
            'image' => 'legacy-cover.jpg',
            'is_coming_soon' => true,
            'authoring_version' => 1,
        ])->save();
        $guid = 'a3cc17a0-4b61-4e59-a4dc-947eabf36790';
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'المقطع',
            'video_source_type' => 'bunny',
            'bunny_video_id' => $guid,
        ]);

        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->with($guid)->andReturn([
            'state' => 'ok',
            'details' => [
                'guid' => $guid,
                'videoLibraryId' => 123,
                'status' => 3,
                'length' => 75,
                'availableResolutions' => '720p,480p',
                'thumbnailFileName' => 'thumbnail.jpg',
            ],
            'http_status' => 200,
        ]);
        $bunny->shouldReceive('getVideo')->once()->with($guid)->andReturn([
            'url' => 'https://media.example/playlist.m3u8',
        ]);
        Http::fake([
            'https://media.example/playlist.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\nsegments/0001.ts?token=signed%2Fvalue\n#EXT-X-ENDLIST\n",
                200
            ),
        ]);
        $reconciliation = new MediaReconciliationService(
            $bunny,
            new MediaHealthService($bunny)
        );
        $this->app->instance(MediaReconciliationService::class, $reconciliation);
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($administrator, 'web')
            ->from('/dashboard/product-operations')
            ->post(route('admin.media-health.probe', $lesson))
            ->assertRedirect('/dashboard/product-operations')
            ->assertSessionHas('success', 'الفيديو جاهز للتشغيل');

        $state = $lesson->mediaState()->firstOrFail();
        self::assertSame('ready', $state->status);
        self::assertSame(75, $state->duration_seconds);
        self::assertSame('healthy', $state->integrity_status);
        self::assertNotNull($state->last_reconciled_at);
    }

    public function test_probe_job_retries_when_bunny_is_ready_before_the_hls_document(): void
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس تجهيز متدرج',
            'image' => 'legacy-cover.jpg',
            'is_coming_soon' => true,
            'authoring_version' => 1,
        ])->save();
        $guid = 'a3cc17a0-4b61-4e59-a4dc-947eabf36792';
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'المقطع',
            'video_source_type' => 'bunny',
            'bunny_video_id' => $guid,
        ]);

        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->with($guid)->andReturn([
            'state' => 'ok',
            'details' => [
                'guid' => $guid,
                'videoLibraryId' => 123,
                'status' => 3,
                'length' => 30,
                'availableResolutions' => '720p,480p',
                'thumbnailFileName' => 'thumbnail.jpg',
            ],
            'http_status' => 200,
        ]);
        $bunny->shouldReceive('getVideo')->once()->with($guid)->andReturn([
            'url' => 'https://media.example/playlist.m3u8',
        ]);
        Http::fake([
            'https://media.example/playlist.m3u8' => Http::response('', 403),
        ]);
        $job = (new ProbeLessonMedia((int) $lesson->id, $guid))
            ->withFakeQueueInteractions();

        $job->handle(new MediaReconciliationService(
            $bunny,
            new MediaHealthService($bunny)
        ));

        $job->assertReleased(15);
        $state = $lesson->mediaState()->firstOrFail();
        self::assertSame('ready', $state->status);
        self::assertSame('attention', $state->integrity_status);
        self::assertContains(
            'manifest_http_error',
            collect($state->integrity_issues)->pluck('code')->all()
        );

        $readyBunny = Mockery::mock(BunnyService::class);
        $readyBunny->shouldReceive('inspectRemoteVideo')->once()->with($guid)->andReturn([
            'state' => 'ok',
            'details' => [
                'guid' => $guid,
                'videoLibraryId' => 123,
                'status' => 3,
                'length' => 30,
                'availableResolutions' => '720p,480p',
                'thumbnailFileName' => 'thumbnail.jpg',
            ],
            'http_status' => 200,
        ]);
        $readyBunny->shouldReceive('getVideo')->once()->with($guid)->andReturn([
            'url' => 'https://media.example/ready-playlist.m3u8',
        ]);
        Http::fake([
            'https://media.example/ready-playlist.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\n720p/playlist.m3u8\n",
                200
            ),
        ]);
        $retry = (new ProbeLessonMedia((int) $lesson->id, $guid))
            ->withFakeQueueInteractions();

        $retry->handle(new MediaReconciliationService(
            $readyBunny,
            new MediaHealthService($readyBunny)
        ));

        $retry->assertNotReleased();
        self::assertSame(
            'healthy',
            $lesson->mediaState()->firstOrFail()->integrity_status
        );
    }

    public function test_pending_recovery_redelivers_only_recent_transient_readiness(): void
    {
        Bus::fake();
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس استرداد الوسائط',
            'image' => 'legacy-cover.jpg',
            'is_coming_soon' => true,
            'authoring_version' => 1,
        ])->save();

        $makeLesson = function (
            string $guid,
            array $issues,
            ?string $lastError = null,
            int $ageMinutes = 0,
            string $status = 'ready'
        ) use ($course): Lesson {
            $lesson = Lesson::query()->create([
                'list_id' => $course->id,
                'title_ar' => 'مقطع استرداد',
                'video_source_type' => 'bunny',
                'bunny_video_id' => $guid,
            ]);
            LessonMediaState::query()->create([
                'lesson_id' => $lesson->id,
                'provider' => 'bunny',
                'provider_media_id' => $guid,
                'status' => $status,
                'protocol' => 'hls',
                'duration_seconds' => 30,
                'available_qualities' => ['auto'],
                'last_probe_at' => now()->subMinutes(11),
                'last_error_code' => $lastError,
                'integrity_status' => 'attention',
                'integrity_issues' => $issues,
                'last_reconciled_at' => now()->subMinutes(11),
            ]);
            if ($ageMinutes > 0) {
                DB::table('lessons')->where('id', $lesson->id)->update([
                    'updated_at' => now()->subMinutes($ageMinutes),
                ]);
            }

            return $lesson;
        };

        $transient = $makeLesson(
            'a3cc17a0-4b61-4e59-a4dc-947eabf36793',
            [['code' => 'manifest_http_error', 'severity' => 'attention']]
        );
        $coverOnly = $makeLesson(
            'a3cc17a0-4b61-4e59-a4dc-947eabf36794',
            [['code' => 'course_cover_missing', 'severity' => 'attention']]
        );
        $oldTransient = $makeLesson(
            'a3cc17a0-4b61-4e59-a4dc-947eabf36795',
            [['code' => 'manifest_http_error', 'severity' => 'attention']],
            null,
            120
        );
        $permanentProviderFailure = $makeLesson(
            'a3cc17a0-4b61-4e59-a4dc-947eabf36796',
            [['code' => 'provider_unreachable', 'severity' => 'attention']],
            'provider_auth_failed',
            0,
            'processing'
        );
        $liveReleasedRetry = $makeLesson(
            'a3cc17a0-4b61-4e59-a4dc-947eabf36797',
            [['code' => 'manifest_http_error', 'severity' => 'attention']]
        );
        $liveReleasedRetry->mediaState()->update([
            'last_probe_at' => now()->subMinutes(4),
            'last_reconciled_at' => now()->subMinutes(4),
        ]);
        $livePendingRetry = $makeLesson(
            'a3cc17a0-4b61-4e59-a4dc-947eabf36799',
            [['code' => 'provider_still_processing', 'severity' => 'attention']],
            null,
            0,
            'processing'
        );
        $livePendingRetry->mediaState()->update([
            'last_probe_at' => now()->subMinutes(4),
            'last_reconciled_at' => now()->subMinutes(4),
        ]);

        $this->artisan('media:recover-pending', [
            '--stale-minutes' => 2,
            '--readiness-window-minutes' => 90,
        ])->assertExitCode(0);

        Bus::assertDispatchedTimes(ProbeLessonMedia::class, 1);
        Bus::assertDispatched(
            ProbeLessonMedia::class,
            fn (ProbeLessonMedia $job): bool => $job->lessonId === (int) $transient->id
        );
        Bus::assertNotDispatched(
            ProbeLessonMedia::class,
            fn (ProbeLessonMedia $job): bool => in_array($job->lessonId, [
                (int) $coverOnly->id,
                (int) $oldTransient->id,
                (int) $permanentProviderFailure->id,
                (int) $liveReleasedRetry->id,
                (int) $livePendingRetry->id,
            ], true)
        );
    }

    public function test_queued_unique_lock_blocks_recovery_then_crashed_worker_release_allows_it(): void
    {
        Bus::fake();
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس استرداد عامل متوقف',
            'image' => 'legacy-cover.jpg',
            'is_coming_soon' => true,
            'authoring_version' => 1,
        ])->save();
        $guid = 'a3cc17a0-4b61-4e59-a4dc-947eabf36798';
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'مقطع عامل متوقف',
            'video_source_type' => 'bunny',
            'bunny_video_id' => $guid,
        ]);
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_media_id' => $guid,
            'status' => 'processing',
            'protocol' => 'hls',
            'duration_seconds' => null,
            'available_qualities' => ['auto'],
            'last_probe_at' => now()->subMinutes(11),
            'integrity_status' => 'unknown',
            'integrity_issues' => null,
            'last_reconciled_at' => null,
        ]);
        $job = new ProbeLessonMedia((int) $lesson->id, $guid);
        $lock = new UniqueLock(app(CacheRepository::class));

        self::assertTrue($lock->acquire($job));
        $this->artisan('media:recover-pending', [
            '--stale-minutes' => 2,
            '--readiness-window-minutes' => 90,
        ])->assertExitCode(0);
        Bus::assertNotDispatched(ProbeLessonMedia::class);

        // ShouldBeUniqueUntilProcessing releases here, before provider work.
        // A crash after this point must remain recoverable without waiting for
        // the one-hour enqueue coalescing TTL.
        $lock->release($job);

        $this->artisan('media:recover-pending', [
            '--stale-minutes' => 2,
            '--readiness-window-minutes' => 90,
        ])->assertExitCode(0);

        Bus::assertDispatched(
            ProbeLessonMedia::class,
            fn (ProbeLessonMedia $candidate): bool => $candidate->lessonId === (int) $lesson->id
        );
        self::assertFalse($lock->acquire($job));
        $lock->release($job);
    }

    #[DataProvider('validPlaylistProvider')]
    public function test_reconciliation_accepts_a_playable_hls_entry(string $playlist): void
    {
        [$result, $lesson] = $this->reconcilePlaylist($playlist);

        self::assertSame('ready', $result['playback_status']);
        self::assertSame('healthy', $result['integrity_status']);
        self::assertSame('healthy', $lesson->mediaState()->firstOrFail()->integrity_status);
    }

    /** @return array<string, array{string}> */
    public static function validPlaylistProvider(): array
    {
        return [
            'master playlist with signed relative variant' => [
                "#EXTM3U\r\n#EXT-X-VERSION:3\r\n#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=720x1280\r\n720p/playlist.m3u8?token=signed%2Fvalue&expires=123\r\n",
            ],
            'media playlist with signed relative segment' => [
                "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\nsegments/0001.ts?token=signed%2Fvalue\n#EXT-X-ENDLIST\n",
            ],
        ];
    }

    #[DataProvider('invalidPlaylistProvider')]
    public function test_reconciliation_rejects_a_non_playable_manifest(string $playlist): void
    {
        [$result, $lesson] = $this->reconcilePlaylist($playlist);

        self::assertSame('ready', $result['playback_status']);
        self::assertSame('attention', $result['integrity_status']);
        self::assertContains(
            'manifest_invalid',
            collect($result['issues'])->pluck('code')->all()
        );
        self::assertSame('attention', $lesson->mediaState()->firstOrFail()->integrity_status);
    }

    /** @return array<string, array{string}> */
    public static function invalidPlaylistProvider(): array
    {
        return [
            'empty encoding skeleton' => [
                "#EXTM3U\n#EXT-X-VERSION:3\n",
            ],
            'truncated master variant' => [
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\n",
            ],
            'truncated media segment' => [
                "#EXTM3U\n#EXTINF:6.0,\n#EXT-X-ENDLIST\n",
            ],
            'html error containing the token' => [
                "<!doctype html><html><body>#EXTM3U token=signed</body></html>",
            ],
        ];
    }

    /** @return array{array<string, mixed>, Lesson} */
    private function reconcilePlaylist(string $playlist): array
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس تحقق HLS',
            'image' => 'legacy-cover.jpg',
            'is_coming_soon' => true,
            'authoring_version' => 1,
        ])->save();
        $guid = 'a3cc17a0-4b61-4e59-a4dc-947eabf36791';
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'المقطع',
            'video_source_type' => 'bunny',
            'bunny_video_id' => $guid,
        ]);

        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->with($guid)->andReturn([
            'state' => 'ok',
            'details' => [
                'guid' => $guid,
                'videoLibraryId' => 123,
                'status' => 3,
                'length' => 75,
                'availableResolutions' => '720p,480p',
                'thumbnailFileName' => 'thumbnail.jpg',
            ],
            'http_status' => 200,
        ]);
        $bunny->shouldReceive('getVideo')->once()->with($guid)->andReturn([
            'url' => 'https://media.example/playlist.m3u8',
        ]);
        Http::fake([
            'https://media.example/playlist.m3u8' => Http::response($playlist, 200),
        ]);

        $result = (new MediaReconciliationService(
            $bunny,
            new MediaHealthService($bunny)
        ))->reconcileLesson($lesson, true, true);

        return [$result, $lesson];
    }
}
