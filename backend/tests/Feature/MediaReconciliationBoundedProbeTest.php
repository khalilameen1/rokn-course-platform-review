<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\BunnyService;
use App\Services\BunnyDeliveryService;
use App\Services\MediaHealthService;
use App\Services\MediaReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LocalHttpServer;
use Tests\TestCase;

final class MediaReconciliationBoundedProbeTest extends TestCase
{
    use RefreshDatabase;

    private ?LocalHttpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    #[DataProvider('fallbackStatuses')]
    public function test_image_fallback_ignoring_range_does_not_download_the_stalled_body(int $status): void
    {
        [$result, $requests] = $this->reconcile('/image-fallback-'.$status, '/manifest-short');

        self::assertSame('healthy', $result['integrity_status']);
        self::assertSame(['HEAD', 'GET', 'GET'], array_column($requests, 'method'));
        self::assertSame($status, $requests[0]['status']);
        self::assertSame(200, $requests[1]['status']); // The server deliberately ignores Range.
        self::assertSame('bytes=0-0', $requests[1]['range']);
        self::assertSame(0, $requests[1]['tail_bytes'], 'The image metadata probe consumed the delayed body.');
    }

    public static function fallbackStatuses(): array
    {
        return [[405], [501]];
    }

    public function test_manifest_reads_its_playable_prefix_without_downloading_the_stalled_tail(): void
    {
        [$result, $requests] = $this->reconcile('/image-head', '/manifest-large');

        self::assertSame('healthy', $result['integrity_status']);
        self::assertSame(0, $requests[1]['tail_bytes'], 'The manifest probe consumed bytes beyond its prefix.');
    }

    public function test_bad_manifest_prefix_is_rejected_without_downloading_the_stalled_tail(): void
    {
        [$result, $requests] = $this->reconcile('/image-head', '/manifest-invalid-large');

        self::assertSame('attention', $result['integrity_status']);
        self::assertContains('manifest_invalid', array_column($result['issues'], 'code'));
        self::assertSame(0, $requests[1]['tail_bytes']);
    }

    #[DataProvider('completeManifestPaths')]
    public function test_normal_head_and_complete_manifest_remain_ready(string $path): void
    {
        [$result, $requests] = $this->reconcile('/image-head', $path);

        self::assertSame('healthy', $result['integrity_status']);
        self::assertSame(['HEAD', 'GET'], array_column($requests, 'method'));
        self::assertSame(['/image-head', $path], array_column($requests, 'path'));
    }

    public static function completeManifestPaths(): array
    {
        return [['/manifest-short'], ['/manifest-fragmented'], ['/manifest-exact-limit'], ['/manifest-gzip']];
    }

    public function test_partial_manifest_trickle_does_not_renew_the_read_deadline(): void
    {
        $started = microtime(true);
        [$result] = $this->reconcile('/image-head', '/manifest-stalled-prefix');

        self::assertSame('attention', $result['integrity_status']);
        self::assertContains('manifest_unreachable', array_column($result['issues'], 'code'));
        self::assertLessThan(11.5, microtime(true) - $started);
    }

    /** @return array{array<string, mixed>, array<int, array<string, mixed>>} */
    private function reconcile(string $imagePath, string $manifestPath): array
    {
        $expectedRequests = str_contains($imagePath, 'fallback') ? 3 : 2;
        $this->server = new LocalHttpServer(
            base_path('tests/Fixtures/media_probe_server.php'), [(string) $expectedRequests]
        );
        $address = $this->server->address();
        $origin = 'http://'.$address;
        Http::preventStrayRequests();
        Http::allowStrayRequests([$origin.'/*']);

        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'Local media readiness',
            'image' => 'legacy-cover.jpg',
            'is_coming_soon' => true,
            'authoring_version' => 1,
        ])->save();
        $guid = 'a3cc17a0-4b61-4e59-a4dc-947eabf36791';
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'Local lesson',
            'video_source_type' => 'bunny',
            'bunny_video_id' => $guid,
            'thumbnail_path' => 'thumbnail.jpg',
        ]);
        $delivery = Mockery::mock(BunnyDeliveryService::class);
        $this->app->instance(BunnyDeliveryService::class, $delivery);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->with($guid)->andReturn([
            'state' => 'ok',
            'details' => [
                'guid' => $guid,
                'videoLibraryId' => 123,
                'status' => 4,
                'length' => 75,
                'availableResolutions' => '720p,480p',
                'thumbnailFileName' => 'thumbnail.jpg',
            ],
            'http_status' => 200,
        ]);
        $delivery->shouldReceive('storageUrl')->once()->with('thumbnail.jpg', 600)
            ->andReturn($origin.$imagePath);
        $delivery->shouldReceive('videoPlayback')->once()->with($guid)
            ->andReturn(['url' => $origin.$manifestPath]);

        $result = (new MediaReconciliationService(
            $bunny,
            new MediaHealthService($bunny),
            $delivery
        ))
            ->reconcileLesson($lesson, false, true);

        self::assertSame(0, $this->server->process->wait(), $this->server->process->getErrorOutput());
        $lines = explode("\n", trim($this->server->process->getOutput()));
        array_shift($lines);
        $requests = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        self::assertCount($expectedRequests, $requests);

        return [$result, $requests];
    }
}
