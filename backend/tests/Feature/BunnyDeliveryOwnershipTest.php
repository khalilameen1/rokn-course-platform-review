<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Services\BunnyConfiguration;
use App\Services\BunnyDeliveryService;
use App\Services\BunnyMediaRegistry;
use App\Services\BunnyService;
use App\Services\LessonMediaDeliveryService;
use App\Services\MediaHealthService;
use App\Services\MediaReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BunnyDeliveryOwnershipTest extends TestCase
{
    private const GUID = '11111111-2222-4333-8444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([BunnyService::class, BunnyMediaRegistry::class,
            MediaHealthService::class, MediaReconciliationService::class] as $service) {
            $this->app->bind($service, static function () use ($service): never {
                throw new \LogicException('Delivery must not resolve ' . $service);
            });
        }
        Http::preventStrayRequests();
        Http::fake(static fn () => throw new \LogicException('Delivery must not contact a provider.'));
        DB::connection()->enableQueryLog();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            self::assertSame([], DB::getQueryLog(), 'Loaded delivery evidence must not query or reconstruct state.');
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('lifetimes')]
    public function test_hls_signing_has_one_bounded_lifetime_and_covers_relative_segments(
        int $configured, ?int $requested, int $expected
    ): void {
        $this->configure();
        config(['playback.signed_url_ttl_seconds' => $configured]);
        $before = time();
        $result = app(BunnyDeliveryService::class)->videoPlayback(self::GUID, $requested);
        $after = time();

        self::assertNotNull($result);
        self::assertSame('hls', $result['type']);
        $expires = strtotime($result['expires_at']);
        self::assertGreaterThanOrEqual($before + $expected, $expires);
        self::assertLessThanOrEqual($after + $expected, $expires);
        $path = '/' . self::GUID . '/';
        $token = BunnyDeliveryService::advancedToken('stream-key', $path, $expires, 'token_path=' . $path);
        self::assertSame(
            'https://stream.example.test/bcdn_token=' . $token . '&token_path=' . rawurlencode($path)
                . '&expires=' . $expires . $path . 'playlist.m3u8',
            $result['url']
        );
        self::assertStringNotContainsString('stream-key', $result['url']);
    }

    public static function lifetimes(): array
    {
        return [
            'configured' => [1200, null, 1200],
            'low configuration' => [0, null, 600],
            'high configuration' => [99999, null, 7200],
            'portfolio override floor' => [1200, 300, 600],
            'explicit' => [1200, 1800, 1800],
            'explicit ceiling' => [1200, 99999, 7200],
        ];
    }

    public function test_embed_and_thumbnail_use_their_own_wire_formats_without_provider_access(): void
    {
        $this->configure();
        config(['playback.signed_url_ttl_seconds' => 1200]);
        $delivery = app(BunnyDeliveryService::class);
        $before = time();
        $embed = $delivery->videoEmbed(self::GUID, 300);
        self::assertNotNull($embed);
        parse_str((string) parse_url($embed['url'], PHP_URL_QUERY), $query);
        self::assertGreaterThanOrEqual($before + 300, (int) $query['expires']);
        self::assertLessThanOrEqual(time() + 300, (int) $query['expires']);
        self::assertSame(
            hash('sha256', 'stream-key' . self::GUID . $query['expires']),
            $query['token']
        );
        self::assertSame('/embed/123/' . self::GUID, parse_url($embed['url'], PHP_URL_PATH));

        $thumbnail = $delivery->videoThumbnail(self::GUID, 'thumbnail.jpg');
        self::assertNotNull($thumbnail);
        self::assertStringEndsWith('/' . self::GUID . '/thumbnail.jpg', $thumbnail['url']);
        self::assertStringContainsString('token_path=' . rawurlencode('/' . self::GUID . '/'), $thumbnail['url']);
        self::assertNull($delivery->videoThumbnail(self::GUID, 'invalid?.jpg'));
    }

    #[DataProvider('incompleteStreamConfiguration')]
    public function test_incomplete_stream_configuration_never_generates_an_unsigned_fallback(array $overrides): void
    {
        $this->configure($overrides);
        $delivery = app(BunnyDeliveryService::class);
        self::assertNull($delivery->videoPlayback(self::GUID));
        self::assertNull($delivery->videoEmbed(self::GUID));
        self::assertNull($delivery->videoThumbnail(self::GUID, 'thumbnail.jpg'));
    }

    public static function incompleteStreamConfiguration(): array
    {
        return [
            'disabled' => [['isEnabled' => false]],
            'no stream host' => [['getCdnHostname' => null]],
            'no stream key' => [['getSecurityKey' => null]],
        ];
    }

    public function test_storage_signing_is_independent_of_stream_availability_and_rejects_foreign_paths(): void
    {
        $this->configure(['isEnabled' => false, 'getSecurityKey' => null]);
        $delivery = app(BunnyDeliveryService::class);
        $url = $delivery->storageUrl('https://assets.example.test/portfolio/image.webp', 300);
        self::assertNotNull($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('assets.example.test', parse_url($url, PHP_URL_HOST));
        self::assertSame(
            BunnyDeliveryService::advancedToken('storage-key', '/portfolio/image.webp', (int) $query['expires']),
            $query['token']
        );
        self::assertNull($delivery->storageUrl('https://foreign.example.test/portfolio/image.webp'));
        self::assertNull($delivery->storageUrl('../private/file.jpg'));
        self::assertNull($delivery->storageUrl('%2e%2e/private/file.jpg'));
    }

    public function test_storage_never_borrows_stream_credentials_when_its_own_configuration_is_missing(): void
    {
        foreach (['getStorageCdnHostname', 'getStorageSecurityKey'] as $missing) {
            $this->configure([$missing => null]);
            self::assertNull(app(BunnyDeliveryService::class)->storageUrl('portfolio/image.webp'), $missing);
        }
    }

    #[DataProvider('previewStates')]
    public function test_preview_reads_only_the_existing_published_generation(
        ?array $overrides, string $source, bool $ready
    ): void {
        $this->configure();
        $lesson = new Lesson();
        $lesson->forceFill(['id' => 11, 'video_source_type' => $source,
            'bunny_video_id' => self::GUID, 'video_link' => 'https://legacy.example.test/public.mp4']);
        $state = $overrides === null ? null : new LessonMediaState(array_replace([
            'lesson_id' => 11, 'provider_media_id' => self::GUID,
            'status' => 'ready', 'last_reconciled_at' => now(),
            'integrity_status' => 'healthy',
        ], $overrides));
        $lesson->setRelation('mediaState', $state);
        $beforeLesson = $lesson->getAttributes();
        $beforeState = $state?->getAttributes();

        $result = app(LessonMediaDeliveryService::class)->forLesson($lesson);
        self::assertSame('bunny', $result['video_source_type']);
        self::assertNull($result['video_link']);
        if ($ready) {
            self::assertStringContainsString('/playlist.m3u8', $result['bunny_video_url']);
            self::assertNotNull($result['bunny_video_expires_at']);
        } else {
            self::assertNull($result['bunny_video_url']);
            self::assertNull($result['bunny_video_expires_at']);
        }
        self::assertSame($beforeLesson, $lesson->getAttributes());
        self::assertSame($beforeState, $state?->getAttributes());
    }

    public static function previewStates(): array
    {
        return [
            'ready' => [[], 'bunny', true],
            'missing' => [null, 'bunny', false],
            'processing' => [['status' => 'processing'], 'bunny', false],
            'failed' => [['status' => 'failed'], 'bunny', false],
            'unreconciled' => [['last_reconciled_at' => null], 'bunny', false],
            'old generation' => [['provider_media_id' => 'old'], 'bunny', false],
            'quarantined' => [['integrity_status' => 'quarantined'], 'bunny', false],
            'legacy public source' => [[], 'youtube', false],
        ];
    }

    private function configure(array $overrides = []): void
    {
        $configuration = Mockery::mock(BunnyConfiguration::class);
        foreach (array_replace([
            'isEnabled' => true,
            'getLibraryId' => '123',
            'getCdnHostname' => 'stream.example.test',
            'getSecurityKey' => 'stream-key',
            'getStorageCdnHostname' => 'assets.example.test',
            'getStorageSecurityKey' => 'storage-key',
        ], $overrides) as $method => $value) {
            $configuration->shouldReceive($method)->andReturn($value);
        }
        $configuration->shouldNotReceive('getApiKey');
        $configuration->shouldNotReceive('getStoragePassword');
        $this->app->instance(BunnyConfiguration::class, $configuration);
    }
}
