<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\User;
use App\Services\StoredFileReferenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TrackedAuthoringUploadRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        $admin = new User();
        $admin->forceFill([
            'name_ar' => 'مدير', 'email' => 'upload-retry-admin@example.test',
            'role' => 'admin', 'active' => true,
        ])->save();
        $this->actingAs($admin, 'web');
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    #[DataProvider('authoringRoutes')]
    public function test_retry_after_owner_write_failure_survives_old_cleanup(string $kind): void
    {
        $payload = $this->payload($kind);
        $fixture = $this->image();
        $table = $kind === 'categories' ? 'categories' : 'photos';
        DB::statement("CREATE TRIGGER reject_owned_upload BEFORE INSERT ON {$table}
            BEGIN SELECT RAISE(ABORT, 'owner write unavailable'); END");
        try {
            $this->postImage($kind, $payload, $fixture)->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER reject_owned_upload');
        }
        self::assertSame('failed', DB::table('admin_authoring_create_intents')->value('status'));
        self::assertSame(0, DB::table('photos')->count());
        $cleanup = AccountFileDeletion::query()->sole();
        $path = (string) $cleanup->path;
        $disk = Storage::disk('public');
        $disk->assertExists($path);
        $observedDisk = \Mockery::mock($disk);
        $observedDisk->shouldReceive('delete')->once()->with($path)
            ->andReturnUsing(function () use ($kind, $payload, $fixture, $disk, $path): bool {
                // Real worker already checked references; real HTTP retry
                // now commits before the old storage deletion completes.
                $this->postImage($kind, $payload, $fixture)->assertRedirect(route('admin.'.$kind.'.index'));

                return $disk->delete($path);
            });
        Storage::set('public', $observedDisk);
        try {
            (new DeleteAccountFile((int) $cleanup->id))->handle(app(StoredFileReferenceService::class));
        } finally {
            Storage::set('public', $disk);
        }
        $owner = $this->owner($kind, $payload['authoring_request_id']);
        $photo = $owner->allPhotos()->sole();
        $disk->assertExists($photo->path);
        self::assertSame(file_get_contents($fixture->getPathname()), $disk->get($photo->path));
        self::assertNotSame($path, $photo->path);
        $disk->assertMissing($path);
        $this->assertAcceptedReplay($kind, $payload, $fixture, $photo->path);
    }

    #[DataProvider('photoPathFormats')]
    public function test_photo_committed_before_receipt_failure_is_reused_by_same_intent(bool $legacyPath): void
    {
        $payload = $this->payload('coupons');
        $fixture = $this->image();
        DB::statement("CREATE TRIGGER reject_upload_receipt BEFORE UPDATE ON admin_authoring_create_intents
            WHEN NEW.status = 'completed' BEGIN SELECT RAISE(ABORT, 'receipt write unavailable'); END");
        try {
            $this->postImage('coupons', $payload, $fixture)->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER reject_upload_receipt');
        }
        $owner = $this->owner('coupons', $payload['authoring_request_id']);
        $path = $owner->allPhotos()->sole()->path;
        if ($legacyPath) {
            $legacy = 'coupons/' . basename($path);
            if ($legacy !== $path) {
                self::assertTrue(Storage::disk('public')->move($path, $legacy));
                $owner->allPhotos()->sole()->update(['path' => $legacy]);
                $path = $legacy;
            }
        }
        self::assertSame('failed', DB::table('admin_authoring_create_intents')->value('status'));
        $this->postImage('coupons', $payload, $fixture)->assertRedirect(route('admin.coupons.index'));
        self::assertSame($path, $owner->fresh()->allPhotos()->sole()->path);
        self::assertSame(1, AccountFileDeletion::query()->count());
        $this->assertAcceptedReplay('coupons', $payload, $fixture, $path);
    }

    public function test_owned_photo_reuse_is_scoped_to_owner_type_and_directory(): void
    {
        $payload = $this->payload('coupons');
        $fixture = $this->image();
        $this->postImage('coupons', $payload, $fixture)->assertRedirect(route('admin.coupons.index'));
        $owner = $this->owner('coupons', $payload['authoring_request_id']);
        $featured = $owner->allPhotos()->sole();
        $identity = 'admin-coupon|' . $payload['authoring_request_id'] . '|' . hash_file('sha256', $fixture->getRealPath());
        self::assertSame($featured->id, $owner->storeImage($fixture, 'coupons', 'featured', $identity)->id);

        $gallery = $owner->storeImage($fixture, 'coupons', 'gallery', $identity);
        self::assertNotSame($featured->path, $gallery->path);
        self::assertSame(basename($featured->path), basename($gallery->path));
        self::assertSame('gallery', $gallery->type);

        $second = Coupon::create([...$this->payload('coupons'), 'code' => 'SECOND-OWNER']);
        $otherOwner = $second->storeImage($fixture, 'coupons', 'featured', $identity);
        self::assertNotSame($featured->path, $otherOwner->path);
        self::assertSame(basename($featured->path), basename($otherOwner->path));
        self::assertSame($second->id, $otherOwner->photoable_id);

        $otherDirectory = $owner->storeImage($fixture, 'other-coupons', 'featured', $identity);
        self::assertStringStartsWith('other-coupons/', $otherDirectory->path);
        self::assertNotSame($featured->path, $otherDirectory->path);
        self::assertSame(4, AccountFileDeletion::query()->count());
        self::assertSame(3, $owner->allPhotos()->count());
        self::assertSame(1, $second->allPhotos()->count());
        foreach ([$featured, $gallery, $otherOwner, $otherDirectory] as $photo) {
            Storage::disk('public')->assertExists($photo->path);
        }
        Http::assertNothingSent();
    }

    public static function photoPathFormats(): array
    {
        return ['current attempt path' => [false], 'legacy flat path' => [true]];
    }

    public static function authoringRoutes(): array
    {
        return ['controller upload' => ['categories'], 'HasPhoto upload' => ['coupons']];
    }

    private function assertAcceptedReplay(string $kind, array $payload, UploadedFile $fixture, string $path): void
    {
        $disk = Storage::disk('public');
        $readOnlyDisk = \Mockery::mock($disk);
        $readOnlyDisk->shouldNotReceive('putFileAs');
        Storage::set('public', $readOnlyDisk);
        try {
            $this->postImage($kind, $payload, $fixture)->assertRedirect(route('admin.'.$kind.'.index'));
        } finally {
            Storage::set('public', $disk);
        }
        self::assertSame($path, $this->owner($kind, $payload['authoring_request_id'])->allPhotos()->sole()->path);
        self::assertSame([$path], $disk->allFiles($kind));
        self::assertSame('completed', DB::table('admin_authoring_create_intents')->value('status'));
        $this->postImage($kind, [...$payload, 'name_ar' => 'بيانات مختلفة'], $fixture)->assertStatus(409);
        $changedImage = UploadedFile::fake()->createWithContent('image.png', file_get_contents($fixture->getPathname()) . 'different image');
        $this->postImage($kind, $payload, $changedImage)->assertStatus(409);
        self::assertSame(1, DB::table($kind)->where('authoring_request_id', $payload['authoring_request_id'])->count());
        Http::assertNothingSent();
    }

    private function owner(string $kind, string $intentId): Category|Coupon
    {
        $class = $kind === 'categories' ? Category::class : Coupon::class;

        return $class::query()->where('authoring_request_id', $intentId)->sole();
    }

    private function postImage(string $kind, array $payload, UploadedFile $fixture): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('admin.'.$kind.'.store'), [
            ...$payload,
            'image' => new UploadedFile($fixture->getPathname(), 'image.png', 'image/png', null, true),
        ], ['Accept' => 'application/json']);
    }

    private function payload(string $kind): array
    {
        return [
            'name_ar' => 'عنصر بصورة', 'name_en' => 'Item with image',
            'authoring_request_id' => (string) Str::uuid(),
            ...($kind === 'coupons' ? [
                'code' => 'RETRY-IMAGE', 'balance' => 10, 'active' => true,
                'expiry_date' => now()->addMonth()->format('Y-m-d'),
            ] : []),
        ];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('image.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2uoAAAAASUVORK5CYII=',
            true
        ));
    }
}
