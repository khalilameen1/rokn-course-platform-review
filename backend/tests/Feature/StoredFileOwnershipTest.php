<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Services\StoredFileDeletionService;
use App\Services\StoredFileReferenceService;
use App\Services\StoredFileUploadService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

final class StoredFileOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_08_07_000022_create_account_file_deletions_table.php'))->up();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('profile_image')->nullable();
        });
        Queue::fake();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('account_file_deletions');
        parent::tearDown();
    }

    public function test_released_files_require_the_reference_removal_transaction(): void
    {
        try {
            app(StoredFileDeletionService::class)->queueReleasedFiles($this->files());
            self::fail('Cleanup must not be detached from reference removal.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('reference-removal transaction', $error->getMessage());
        }
        self::assertSame(0, AccountFileDeletion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_batch_admits_still_referenced_files_and_dispatches_only_after_outer_commit(): void
    {
        DB::table('users')->insert(['id' => 7, 'profile_image' => 'profiles/avatar.jpg']);
        Storage::disk('public')->put('profiles/avatar.jpg', 'private bytes');
        DB::transaction(function (): void {
            $ids = DB::transaction(fn () => app(StoredFileDeletionService::class)->queueReleasedFiles($this->files(), 7));
            self::assertCount(1, $ids);
            Queue::assertNothingPushed();
            $row = AccountFileDeletion::query()->sole();
            self::assertSame(7, (int) $row->user_id);
            self::assertSame('profiles/avatar.jpg', $row->path);
            self::assertNotSame($row->path, $row->getRawOriginal('path'));
            self::assertSame(hash('sha256', $row->path), $row->path_hash);
            DB::table('users')->where('id', 7)->update(['profile_image' => null]);
        });
        Queue::assertPushed(DeleteAccountFile::class, 1);
        $row = AccountFileDeletion::query()->sole();
        (new DeleteAccountFile($row->id))->handle(app(StoredFileReferenceService::class));
        Storage::disk('public')->assertMissing('profiles/avatar.jpg');
        self::assertSame(AccountFileDeletion::STATUS_COMPLETED, $row->fresh()->status);
    }

    public function test_rollback_preserves_references_and_bytes_without_dispatching_cleanup(): void
    {
        DB::table('users')->insert(['id' => 7, 'profile_image' => 'profiles/avatar.jpg']);
        Storage::disk('public')->put('profiles/avatar.jpg', 'private bytes');
        try {
            DB::transaction(function (): void {
                app(StoredFileDeletionService::class)->queueReleasedFiles($this->files(), 7);
                DB::table('users')->where('id', 7)->update(['profile_image' => null]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('rollback', $error->getMessage());
        }
        // A later commit must not release callbacks discarded with the rollback.
        DB::transaction(static fn () => null);
        self::assertSame('profiles/avatar.jpg', DB::table('users')->where('id', 7)->value('profile_image'));
        self::assertSame(0, AccountFileDeletion::query()->count());
        Storage::disk('public')->assertExists('profiles/avatar.jpg');
        Queue::assertNothingPushed();
    }

    public function test_batch_normalizes_and_deduplicates_by_disk_but_ignores_external_urls(): void
    {
        $ids = DB::transaction(fn () => app(StoredFileDeletionService::class)->queueReleasedFiles([
            ['disk' => ' public ', 'path' => ' /profiles/avatar.jpg '],
            ...$this->files(),
            ['disk' => 'local', 'path' => 'profiles/avatar.jpg'],
            ['disk' => 'public', 'path' => 'https://example.test/private.jpg'],
            ['disk' => '', 'path' => 'profiles/ignored.jpg'],
            ['disk' => 'public', 'path' => ' '],
        ]));
        self::assertCount(2, $ids);
        self::assertSame(2, AccountFileDeletion::query()->count());
        Queue::assertPushed(DeleteAccountFile::class, 2);
    }

    public function test_immediate_cleanup_refuses_live_references_and_worker_rechecks_new_ones(): void
    {
        DB::table('users')->insert(['id' => 7, 'profile_image' => 'profiles/avatar.jpg']);
        Storage::disk('public')->put('profiles/avatar.jpg', 'private bytes');
        $cleanup = app(StoredFileDeletionService::class);
        $cleanup->deleteOrQueue('public', 'profiles/avatar.jpg');
        self::assertSame(0, AccountFileDeletion::query()->count());
        DB::table('users')->where('id', 7)->update(['profile_image' => null]);
        $cleanup->deleteOrQueue('public', 'profiles/avatar.jpg');
        DB::table('users')->where('id', 7)->update(['profile_image' => 'profiles/avatar.jpg']);
        $row = AccountFileDeletion::query()->sole();
        (new DeleteAccountFile($row->id))->handle(app(StoredFileReferenceService::class));
        self::assertSame(AccountFileDeletion::STATUS_SKIPPED, $row->fresh()->status);
        self::assertNull($row->fresh()->path);
        Storage::disk('public')->assertExists('profiles/avatar.jpg');
    }

    public function test_queue_outage_leaves_durable_intent_and_scheduler_can_dispatch_the_same_row(): void
    {
        $original = app(Dispatcher::class);
        $failed = Mockery::mock(Dispatcher::class);
        $failed->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('broker unavailable'));
        $this->app->instance(Dispatcher::class, $failed);
        app(StoredFileDeletionService::class)->deleteOrQueue('public', 'profiles/avatar.jpg');
        $row = AccountFileDeletion::query()->sole();
        self::assertSame(AccountFileDeletion::STATUS_PENDING, $row->status);
        self::assertSame('profiles/avatar.jpg', $row->path);
        $this->app->instance(Dispatcher::class, $original);
        $this->artisan('privacy:cleanup-account-files')->assertExitCode(0);
        Queue::assertPushed(DeleteAccountFile::class, fn ($job) => $job->deletionId === $row->id);
        self::assertSame(1, AccountFileDeletion::query()->count());
    }

    public function test_storage_failure_keeps_the_path_for_retry_then_clears_it_on_success(): void
    {
        app(StoredFileDeletionService::class)->deleteOrQueue('local', 'profiles/avatar.jpg');
        $row = AccountFileDeletion::query()->sole();
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->twice()->with('profiles/avatar.jpg')->andReturnTrue();
        $disk->shouldReceive('delete')->twice()->with('profiles/avatar.jpg')->andReturn(false, true);
        Storage::shouldReceive('disk')->twice()->with('local')->andReturn($disk);
        $job = new DeleteAccountFile($row->id);
        try {
            $job->handle(app(StoredFileReferenceService::class));
            self::fail('A refused deletion must be retried.');
        } catch (\RuntimeException $error) {
            self::assertSame('Storage refused the deletion.', $error->getMessage());
        }
        self::assertSame(AccountFileDeletion::STATUS_PENDING, $row->fresh()->status);
        self::assertSame('profiles/avatar.jpg', $row->fresh()->path);
        self::assertTrue($row->fresh()->available_at->isFuture());
        $this->travel(3)->minutes();
        $job->handle(app(StoredFileReferenceService::class));
        self::assertSame(2, $row->fresh()->attempts);
        self::assertSame(AccountFileDeletion::STATUS_COMPLETED, $row->fresh()->status);
        self::assertNull($row->fresh()->path);
    }

    public function test_upload_ledger_is_committed_before_storage_and_failed_bytes_remain_recoverable(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $file, $name): never {
            self::assertSame(0, DB::transactionLevel());
            $row = AccountFileDeletion::query()->sole();
            self::assertSame($directory.'/'.$name, $row->path);
            self::assertTrue($row->available_at->isFuture());
            Queue::assertPushed(DeleteAccountFile::class, 1);
            throw new \RuntimeException('storage interrupted');
        });
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        try {
            app(StoredFileUploadService::class)->storeTrackedUpload(
                UploadedFile::fake()->createWithContent('work.txt', 'work'), 'projects'
            );
            self::fail('The simulated write must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('storage interrupted', $error->getMessage());
        }
        self::assertSame(AccountFileDeletion::STATUS_PENDING, AccountFileDeletion::query()->sole()->status);
    }

    public function test_failed_ledger_prevents_any_storage_write(): void
    {
        AccountFileDeletion::creating(static fn () => throw new \RuntimeException('ledger unavailable'));
        Storage::shouldReceive('disk')->never();
        try {
            app(StoredFileUploadService::class)->storeTrackedUpload(
                UploadedFile::fake()->createWithContent('work.txt', 'work'), 'projects'
            );
            self::fail('Bytes must not be written without durable cleanup intent.');
        } catch (\RuntimeException $error) {
            self::assertSame('ledger unavailable', $error->getMessage());
        }
        Queue::assertNothingPushed();
        self::assertSame(0, AccountFileDeletion::query()->count());
    }

    public function test_generic_retry_creates_distinct_physical_attempts_for_the_same_logical_operation(): void
    {
        $file = UploadedFile::fake()->createWithContent('work.txt', 'work');
        $uploads = app(StoredFileUploadService::class);
        $first = $uploads->storeTrackedUpload($file, 'projects', operationIdentity: 'one-operation');
        $second = $uploads->storeTrackedUpload($file, 'projects', operationIdentity: 'one-operation');
        self::assertNotSame($first, $second);
        self::assertSame(basename($first), basename($second));
        self::assertSame(2, AccountFileDeletion::query()->count());
        Storage::disk('public')->assertExists([$first, $second]);
    }

    private function files(): array
    {
        return [['disk' => 'public', 'path' => 'profiles/avatar.jpg']];
    }
}
