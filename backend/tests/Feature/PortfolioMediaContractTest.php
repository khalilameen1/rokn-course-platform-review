<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\PortfolioMediaResource;
use App\Services\BunnyService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PortfolioMediaContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_portfolio_video_uses_the_shared_bunny_playability_contract(): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->andReturn([
            'state' => 'ok',
            'details' => [
                'status' => 4,
                'encodeProgress' => 0,
                'availableResolutions' => '',
            ],
            'http_status' => 200,
        ]);
        $bunny->shouldReceive('getSignedEmbedUrl')->once()->andReturn([
            'url' => 'https://video.example.test/embed/ready',
            'expires_at' => '2026-09-02T12:05:00+00:00',
        ]);
        $bunny->shouldReceive('getSignedPlayUrl')->once()->andReturn([
            'url' => 'https://video.example.test/play/ready.m3u8',
            'expires_at' => '2026-09-02T12:05:00+00:00',
        ]);
        $this->app->instance(BunnyService::class, $bunny);

        $payload = (new PortfolioMediaResource($this->media(81, 'ready-guid')))->resolve();

        self::assertSame('ready', $payload['status']);
        self::assertSame('https://video.example.test/embed/ready', $payload['video_url']);
        self::assertSame('https://video.example.test/play/ready.m3u8', $payload['playback_url']);
        self::assertSame('2026-09-02T12:05:00+00:00', $payload['url_expires_at']);
    }

    #[DataProvider('unfinishedVideoStatuses')]
    public function test_get_video_status_does_not_use_webhook_event_meanings(int $status, string $expected): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->andReturn([
            'state' => 'ok',
            'details' => [
                'status' => $status,
                'encodeProgress' => 100,
                'availableResolutions' => '720p,480p',
            ],
            'http_status' => 200,
        ]);
        $bunny->shouldNotReceive('getSignedEmbedUrl');
        $bunny->shouldNotReceive('getSignedPlayUrl');
        $this->app->instance(BunnyService::class, $bunny);

        $payload = (new PortfolioMediaResource($this->media(82, 'uploading-guid')))->resolve();

        self::assertSame($expected, $payload['status']);
        self::assertNull($payload['video_url']);
        self::assertNull($payload['playback_url']);
    }

    public static function unfinishedVideoStatuses(): array
    {
        return [
            'transcoding is not webhook finished' => [3, 'processing'],
            'upload failed is not webhook upload started' => [6, 'failed'],
            'JIT segmentation is still processing' => [7, 'processing'],
            'JIT playlists are not webhook upload failure' => [8, 'processing'],
            'captions event is not a GET status' => [9, 'processing'],
            'title event is not a GET status' => [10, 'processing'],
        ];
    }

    public function test_provider_confirmed_missing_portfolio_video_is_not_left_processing_forever(): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->andReturn([
            'state' => 'not_found',
            'details' => null,
            'http_status' => 404,
        ]);
        $bunny->shouldNotReceive('getSignedEmbedUrl');
        $this->app->instance(BunnyService::class, $bunny);

        $payload = (new PortfolioMediaResource($this->media(83, 'missing-guid')))->resolve();

        self::assertSame('failed', $payload['status']);
        self::assertNull($payload['video_url']);
    }

    private function media(int $id, string $path): object
    {
        return (object) [
            'id' => $id,
            'public_id' => '99999999-9999-4999-8999-' . str_pad((string) $id, 12, '0', STR_PAD_LEFT),
            'file_type' => 'video',
            'file_path' => $path,
            'sort_order' => 0,
            'caption' => null,
            'width' => null,
            'height' => null,
            'duration_seconds' => null,
        ];
    }
}
