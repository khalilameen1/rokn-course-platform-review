<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\Level;
use App\Models\User;
use App\Services\StoredFileReferenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LevelBadgeStorageRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Byte staging commits before the owning transaction, as in production.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        $admin = new User();
        $admin->forceFill([
            'name_ar' => 'مدير', 'email' => 'level-badge-admin@example.test',
            'role' => 'admin', 'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin, 'web');
    }

    public function test_failed_create_retry_cannot_be_deleted_by_the_previous_attempt_cleanup(): void
    {
        $requestId = (string) Str::uuid();
        $data = $this->payload($requestId);
        $fixture = UploadedFile::fake()->image('badge.png', 16, 16);
        $bytes = file_get_contents($fixture->getPathname());
        $disk = Storage::disk('public');
        DB::statement("CREATE TRIGGER reject_level_badge_create BEFORE INSERT ON levels
            WHEN NEW.authoring_request_id = '{$requestId}' BEGIN SELECT RAISE(ABORT, 'level create unavailable'); END");
        try {
            $this->postBadge($data, $fixture)->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER reject_level_badge_create');
        }
        self::assertSame(0, Level::where('authoring_request_id', $requestId)->count());
        self::assertSame('failed', DB::table('admin_authoring_create_intents')->value('status'));
        $cleanup = AccountFileDeletion::query()->sole();
        $failedPath = $cleanup->path;
        self::assertSame($bytes, $disk->get($failedPath));

        $interleavedDisk = \Mockery::mock($disk);
        $interleavedDisk->shouldReceive('delete')->once()->with($failedPath)
            ->andReturnUsing(function () use ($data, $fixture, $disk, $failedPath): bool {
                // Real DeleteAccountFile has already checked references. The
                // retry commits through HTTP before that old native delete.
                $this->postBadge($data, $fixture)->assertRedirect(route('admin.levels.index'));
                return $disk->delete($failedPath);
            });
        Storage::set('public', $interleavedDisk);
        try {
            (new DeleteAccountFile((int) $cleanup->id))->handle(app(StoredFileReferenceService::class));
        } finally {
            Storage::set('public', $disk);
        }

        $level = Level::where('authoring_request_id', $requestId)->sole();
        $photo = $level->allPhotos()->sole();
        $disk->assertExists($photo->path);
        self::assertSame($bytes, $disk->get($photo->path));
        self::assertNotSame($failedPath, $photo->path);
        $disk->assertMissing($failedPath);
        $this->assertReceiptReplay($data, $fixture, $level, $photo->path);
        Http::assertNothingSent();
    }

    public function test_successful_create_replay_has_one_badge_and_rejects_changed_payload(): void
    {
        $data = $this->payload((string) Str::uuid());
        $fixture = UploadedFile::fake()->image('badge.png', 16, 16);
        $this->postBadge($data, $fixture)->assertRedirect(route('admin.levels.index'));
        $level = Level::where('authoring_request_id', $data['authoring_request_id'])->sole();
        $path = $level->allPhotos()->sole()->path;
        $this->assertReceiptReplay($data, $fixture, $level, $path);
        $this->postBadge(array_replace($data, ['name_ar' => 'اسم مختلف']), $fixture)->assertStatus(409);
        $changedImage = UploadedFile::fake()->image('badge.png', 32, 32);
        $this->postBadge($data, $changedImage)->assertStatus(409);
        self::assertSame($data['name_ar'], $level->fresh()->name_ar);
        self::assertSame(1, AccountFileDeletion::count());
        self::assertCount(1, Storage::disk('public')->allFiles('levels'));
        Http::assertNothingSent();
    }

    private function assertReceiptReplay(array $data, UploadedFile $fixture, Level $level, string $path): void
    {
        $disk = Storage::disk('public');
        $readOnlyDisk = \Mockery::mock($disk);
        $readOnlyDisk->shouldNotReceive('putFileAs');
        Storage::set('public', $readOnlyDisk);
        try {
            $this->postBadge($data, $fixture)->assertRedirect(route('admin.levels.index'));
        } finally {
            Storage::set('public', $disk);
        }
        self::assertSame(1, Level::where('authoring_request_id', $data['authoring_request_id'])->count());
        self::assertSame($path, $level->fresh()->allPhotos()->sole()->path);
        self::assertCount(1, $disk->allFiles('levels'));
        self::assertSame(1, DB::table('admin_authoring_create_intents')->where('status', 'completed')->count());
    }

    private function postBadge(array $data, UploadedFile $fixture): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('admin.levels.store'), [
            ...$data,
            'badge_image' => new UploadedFile($fixture->getPathname(), 'badge.png', 'image/png', null, true),
        ], ['Accept' => 'application/json']);
    }

    private function payload(string $requestId): array
    {
        return [
            'name_ar' => 'مستوى جديد', 'name_en' => 'New level', 'order' => 4,
            'authoring_request_id' => $requestId,
        ];
    }
}
