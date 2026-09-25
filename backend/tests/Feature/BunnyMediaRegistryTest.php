<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BunnyStorageCleanupCandidate;
use App\Services\BunnyConfiguration;
use App\Services\BunnyMediaRegistry;
use App\Services\BunnyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class BunnyMediaRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bunny.storage_cdn_hostname' => 'assets.example.test']);
        Http::preventStrayRequests();
    }

    public function test_url_and_relative_path_reserve_one_durable_object_without_remote_io(): void
    {
        $registry = app(BunnyMediaRegistry::class);
        self::assertTrue($registry->queueStorageCleanup('https://assets.example.test/portfolio/work.jpg', 'first'));
        $id = BunnyStorageCleanupCandidate::query()->sole()->id;
        self::assertTrue($registry->queueStorageCleanup('portfolio/work.jpg', 'retry'));
        $candidate = BunnyStorageCleanupCandidate::query()->sole();
        self::assertSame($id, $candidate->id);
        self::assertSame('portfolio/work.jpg', $candidate->path);
        self::assertSame(hash('sha256', $candidate->path), $candidate->path_hash);
        Http::assertNothingSent();
    }

    public function test_a_claimed_delete_can_neither_be_reset_nor_published(): void
    {
        $registry = app(BunnyMediaRegistry::class);
        $registry->queueStorageCleanup('portfolio/work.jpg', 'pending');
        $candidate = BunnyStorageCleanupCandidate::query()->sole();
        $candidate->update(['last_attempt_at' => now(), 'attempts' => 1]);
        $before = $candidate->fresh()->getAttributes();
        self::assertFalse($registry->queueStorageCleanup('portfolio/work.jpg', 'replacement'));
        try {
            DB::transaction(fn () => $registry->consumeStorageCleanupCandidate('portfolio/work.jpg'));
            self::fail('An uncertain delete must block publishing that object key.');
        } catch (RuntimeException $exception) {
            self::assertSame('The staged Bunny Storage object is no longer safe to publish.', $exception->getMessage());
        }
        self::assertSame($before, $candidate->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_failed_publication_restores_the_cleanup_reservation(): void
    {
        $registry = app(BunnyMediaRegistry::class);
        $registry->queueStorageCleanup('portfolio/work.jpg', 'pending');
        try {
            DB::transaction(function () use ($registry): void {
                $registry->consumeStorageCleanupCandidate('portfolio/work.jpg');
                self::assertSame(0, BunnyStorageCleanupCandidate::query()->count());
                throw new RuntimeException('publication failed');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('publication failed', $exception->getMessage());
        }
        self::assertSame(1, BunnyStorageCleanupCandidate::query()->count());
        DB::transaction(fn () => $registry->consumeStorageCleanupCandidate('portfolio/work.jpg'));
        self::assertSame(0, BunnyStorageCleanupCandidate::query()->count());
        Http::assertNothingSent();
    }

    public function test_storage_upload_reserves_cleanup_before_sending_bytes_and_cannot_reuse_a_claimed_key(): void
    {
        $configuration = Mockery::mock(BunnyConfiguration::class);
        $configuration->shouldReceive('isEnabled')->andReturnTrue();
        $configuration->shouldReceive('getStorageZoneName')->andReturn('test-zone');
        $configuration->shouldReceive('getStoragePassword')->andReturn('test-secret');
        $configuration->shouldReceive('getStorageCdnHostname')->andReturn('assets.example.test');
        $this->app->instance(BunnyConfiguration::class, $configuration);
        $key = '11111111-1111-4111-8111-111111111111';
        Http::fake(function ($request) {
            $candidate = BunnyStorageCleanupCandidate::query()->sole();
            self::assertSame('unpublished_storage_upload', $candidate->reason);
            self::assertStringEndsWith('/'.$candidate->path, $request->url());
            return Http::response('', 201);
        });
        $file = UploadedFile::fake()->createWithContent('work.txt', 'A durable learner attachment');
        $service = app(BunnyService::class);
        $path = $service->uploadFileToStorage($file, 'portfolio', $key);
        self::assertNotNull($path);
        Http::assertSentCount(1);
        BunnyStorageCleanupCandidate::query()->sole()->update(['last_attempt_at' => now()]);

        self::assertNull($service->uploadFileToStorage($file, 'portfolio', $key));
        Http::assertSentCount(1);
    }
}
