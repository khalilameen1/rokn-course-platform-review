<?php

declare(strict_types=1);

namespace Tests\Feature;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use App\Services\StoredFileDeletionService;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

final class ProjectUploadStorageBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('account_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('disk');
            $table->char('path_hash', 64);
            $table->text('path');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path_hash']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('account_file_deletions');
        parent::tearDown();
    }

    public function test_project_upload_budget_is_bounded_below_the_platform_deadline(): void
    {
        self::assertLessThan(20, (int) config('projects.submission_request_budget_seconds'));
        self::assertGreaterThanOrEqual(5, (int) config('projects.submission_request_budget_seconds'));
    }

    public function test_fresh_deterministic_upload_skips_remote_metadata_and_retry_uses_one_probe(): void
    {
        Queue::fake();
        $file = UploadedFile::fake()->createWithContent('attempt.jpg', 'learner-work');
        $storedPath = null;
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')
            ->once()
            ->andReturnUsing(function (string $directory, UploadedFile $upload, string $name) use (&$storedPath): string {
                $storedPath = $directory.'/'.$name;
                return $storedPath;
            });
        $disk->shouldReceive('size')
            ->once()
            ->with(Mockery::on(function (string $path) use (&$storedPath): bool {
                return $path === $storedPath;
            }))
            ->andReturn((int) $file->getSize());

        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->times(3)->with('project-test')->andReturn($disk);
        $this->app->instance('filesystem', $factory);
        $this->app->instance(FilesystemFactory::class, $factory);

        $files = app(StoredFileDeletionService::class);
        $first = $files->storeTrackedUpload(
            $file,
            'project_submissions/5/8',
            'project-test',
            60,
            'project-submission|5|8|request-1|0|hash'
        );
        $replayed = $files->storeTrackedUpload(
            $file,
            'project_submissions/5/8',
            'project-test',
            60,
            'project-submission|5|8|request-1|0|hash'
        );

        self::assertSame($first, $replayed);
        self::assertSame($storedPath, $first);
        self::assertSame(1, Schema::getConnection()
            ->table('account_file_deletions')
            ->count());
    }

    public function test_one_request_budget_bounds_each_upload_and_stops_before_a_second_slow_file(): void
    {
        Queue::fake();
        $first = UploadedFile::fake()->createWithContent('first.jpg', 'first-project-file');
        $second = UploadedFile::fake()->createWithContent('second.jpg', 'second-project-file');
        request()->attributes->set(
            StoredFileDeletionService::REQUEST_UPLOAD_DEADLINE_ATTRIBUTE,
            microtime(true) + 16
        );

        $handler = new MockHandler([
            function (CommandInterface $command, RequestInterface $httpRequest): Result {
                self::assertSame('PutObject', $command->getName());
                self::assertSame(0, $command['@retries']);
                self::assertLessThanOrEqual(6.0, $command['@http']['timeout']);
                self::assertLessThanOrEqual(2.0, $command['@http']['connect_timeout']);
                // Simulate the first remote operation consuming the shared
                // request budget. No second storage call may then start.
                request()->attributes->set(
                    StoredFileDeletionService::REQUEST_UPLOAD_DEADLINE_ATTRIBUTE,
                    microtime(true) - 1
                );

                return new Result(['@metadata' => [], 'ETag' => 'test-etag']);
            },
        ]);
        config()->set('filesystems.disks.project-budget-test', [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'bucket' => 'project-tests',
            'endpoint' => 'https://storage.example.test',
            'use_path_style_endpoint' => true,
            'throw' => true,
            'handler' => $handler,
        ]);
        Storage::forgetDisk('project-budget-test');

        $files = app(StoredFileDeletionService::class);
        try {
            $files->storeTrackedUpload(
                $first,
                'project_submissions/5/8',
                'project-budget-test',
                60,
                'project-submission|5|8|request-2|0|hash'
            );
            $files->storeTrackedUpload(
                $second,
                'project_submissions/5/8',
                'project-budget-test',
                60,
                'project-submission|5|8|request-2|1|hash'
            );
            self::fail('A second upload started after the shared request budget expired.');
        } catch (UnableToWriteFile) {
            self::assertSame(1, Schema::getConnection()
                ->table('account_file_deletions')
                ->count());
            self::assertCount(0, $handler);
        } finally {
            Storage::forgetDisk('project-budget-test');
            request()->attributes->remove(
                StoredFileDeletionService::REQUEST_UPLOAD_DEADLINE_ATTRIBUTE
            );
        }
    }
}
