<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\BunnyService;
use App\Services\MediaHealthService;
use App\Services\MediaReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
