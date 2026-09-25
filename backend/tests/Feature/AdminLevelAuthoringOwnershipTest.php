<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\LevelController;
use App\Models\AccountFileDeletion;
use App\Models\Course;
use App\Models\Level;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminLevelAuthoringService;
use App\Services\StoredFileReferenceService;
use App\Support\LevelEditorVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminLevelAuthoringOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        foreach ([LevelController::class, AdminAuthoringCreateIntentService::class] as $adapter) {
            $this->app->bind($adapter, static function (): never {
                throw new \LogicException('Level authoring cannot depend on an HTTP adapter.');
            });
        }
    }

    public function test_creation_and_receipt_share_a_transaction_and_retry_keeps_one_featured_image(): void
    {
        $writer = app(AdminLevelAuthoringService::class);
        $requestId = (string) Str::uuid();
        $image = UploadedFile::fake()->image('badge.png');
        $ids = [];
        $complete = static function (Level $level) use (&$ids): void {
            self::assertSame(1, DB::transactionLevel());
            self::assertSame(1, $level->allPhotos()->count());
            $ids[] = $level->id;
        };
        $level = $writer->create($this->fields(), $requestId, $image, $complete);
        $path = $level->photo->path;
        $again = $writer->create($this->fields(), $requestId, $image, $complete);

        self::assertSame([$level->id, $level->id], $ids);
        self::assertSame($level->id, $again->id);
        self::assertSame(1, Level::query()->where('authoring_request_id', $requestId)->count());
        self::assertSame(1, $level->allPhotos()->count());
        self::assertSame($path, $level->fresh()->photo->path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_receipt_failure_rolls_back_level_and_photo_then_allows_retry(): void
    {
        $writer = app(AdminLevelAuthoringService::class);
        $requestId = (string) Str::uuid();
        try {
            $writer->create($this->fields(), $requestId, UploadedFile::fake()->image('badge.png'),
                static function (): never {
                    DB::table('admin_singleton_locks')->insert(['lock_key' => 'level-receipt']);
                    throw new \RuntimeException('receipt failed');
                }
            );
            self::fail('The level, image reference and receipt must commit together.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertFalse(Level::query()->where('authoring_request_id', $requestId)->exists());
        self::assertSame(0, DB::table('photos')->where('photoable_type', Level::class)->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'level-receipt')->exists());
        $level = $writer->create($this->fields(), $requestId, UploadedFile::fake()->image('badge.png'), static function (): void {});
        self::assertNotNull($level->fresh());
        self::assertSame(1, $level->allPhotos()->count());
    }

    public function test_stale_image_edit_is_rejected_and_text_edit_keeps_the_badge(): void
    {
        $level = Level::query()->create($this->fields() + ['badge_image' => 'levels/legacy.png']);
        Storage::disk('public')->put('levels/legacy.png', 'legacy badge');
        $version = LevelEditorVersion::for($level);
        $level->fresh()->update(['name_ar' => 'اسم أحدث']);
        $writer = app(AdminLevelAuthoringService::class);
        try {
            $writer->update((int) $level->id, $this->fields(), $version, UploadedFile::fake()->image('new.png'));
            self::fail('A stale image editor cannot overwrite a newer level.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertSame('اسم أحدث', $level->fresh()->name_ar);
        self::assertSame('levels/legacy.png', $level->fresh()->badge_image);
        self::assertSame(0, $level->allPhotos()->count());
        $writer->update((int) $level->id, ['description_ar' => 'وصف جديد', 'badge_image' => 'untrusted.png'],
            LevelEditorVersion::for($level->fresh()), null);
        self::assertSame('وصف جديد', $level->fresh()->description_ar);
        self::assertSame('levels/legacy.png', $level->fresh()->badge_image);
        self::assertFalse(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', 'levels/legacy.png'))->exists());
        Storage::disk('public')->assertExists('levels/legacy.png');
    }

    public function test_shared_legacy_badge_is_retired_only_after_its_last_level_releases_it(): void
    {
        $path = 'levels/shared.png';
        Storage::disk('public')->put($path, 'shared badge');
        $first = Level::query()->create($this->fields() + ['badge_image' => $path]);
        $second = Level::query()->create($this->fields() + ['badge_image' => '/'.$path]);
        $writer = app(AdminLevelAuthoringService::class);
        $writer->update((int) $first->id, $this->fields(), LevelEditorVersion::for($first), UploadedFile::fake()->image('one.png'));
        self::assertNull($first->fresh()->badge_image);
        self::assertNotNull($first->fresh()->photo);
        self::assertTrue(app(StoredFileReferenceService::class)->isReferenced('public', $path));
        self::assertFalse(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->exists());

        $writer->update((int) $second->id, $this->fields(), LevelEditorVersion::for($second), UploadedFile::fake()->image('two.png'));
        self::assertFalse(app(StoredFileReferenceService::class)->isReferenced('public', $path));
        self::assertSame(AccountFileDeletion::STATUS_PENDING, AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->sole()->status);
        // Physical deletion remains owned by the cleanup worker.
        Storage::disk('public')->assertExists($path);
    }

    public function test_referenced_levels_cannot_be_deleted_and_unused_legacy_images_get_durable_cleanup(): void
    {
        $writer = app(AdminLevelAuthoringService::class);
        $courseLevel = Level::query()->create($this->fields());
        Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 100, 'level_id' => $courseLevel->id]);
        self::assertFalse($writer->deleteIfUnused((int) $courseLevel->id));

        $earnedLevel = Level::query()->create($this->fields());
        $user = User::query()->forceCreate(['name_ar' => 'طالب', 'email' => 'badge@rokn.test', 'role' => 'client']);
        $earnedLevel->users()->attach($user->id, ['earned_at' => now()]);
        self::assertFalse($writer->deleteIfUnused((int) $earnedLevel->id));

        $unused = Level::query()->create($this->fields() + ['badge_image' => 'levels/unused.png']);
        Storage::disk('public')->put('levels/unused.png', 'unused badge');
        DB::beginTransaction();
        self::assertTrue($writer->deleteIfUnused((int) $unused->id));
        self::assertTrue(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', 'levels/unused.png'))->exists());
        DB::rollBack();
        self::assertNotNull($unused->fresh());
        self::assertFalse(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', 'levels/unused.png'))->exists());
        self::assertTrue($writer->deleteIfUnused((int) $unused->id));
        self::assertNull($unused->fresh());
        self::assertTrue(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', 'levels/unused.png'))->exists());
        Storage::disk('public')->assertExists('levels/unused.png');
    }

    public function test_external_and_bundled_badges_are_never_scheduled_for_local_deletion(): void
    {
        foreach (['https://example.test/badge.png', 'assets/img/badges/junior.png'] as $path) {
            $level = Level::query()->create($this->fields() + ['badge_image' => $path]);
            self::assertTrue(app(AdminLevelAuthoringService::class)->deleteIfUnused((int) $level->id));
            self::assertFalse(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->exists());
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function fields(): array
    {
        return ['name_ar' => 'مستوى ركن', 'name_en' => 'Rokn level', 'order' => 1];
    }
}
