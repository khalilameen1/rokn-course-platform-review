<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CategoryController;
use App\Models\AccountFileDeletion;
use App\Models\Category;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCategoryAuthoringService;
use App\Support\CategoryEditorVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminCategoryAuthoringOwnershipTest extends TestCase
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
        foreach ([CategoryController::class, AdminAuthoringCreateIntentService::class] as $adapter) {
            $this->app->bind($adapter, static function (): never {
                throw new \LogicException('Category authoring cannot resolve HTTP adapters.');
            });
        }
    }

    public function test_create_and_receipt_are_atomic_and_replay_does_not_upload_or_attach_again(): void
    {
        $writer = app(AdminCategoryAuthoringService::class);
        $intent = (string) Str::uuid();
        $image = UploadedFile::fake()->image('category.png');
        $ids = [];
        $receipt = static function (Category $category) use (&$ids): void {
            self::assertSame(1, DB::transactionLevel());
            self::assertSame(1, $category->allPhotos()->count());
            self::assertNotNull($category->photo);
            $ids[] = $category->id;
        };
        $first = $writer->create($this->fields(), $intent, $image, $receipt);
        $path = $first->photo->path;
        $again = $writer->create($this->fields(), $intent, $image, $receipt);
        self::assertSame([$first->id, $first->id], $ids);
        self::assertSame($path, $again->photo->path);
        self::assertSame([$path], Storage::disk('public')->allFiles('categories'));
        self::assertSame(1, Category::query()->count());
    }

    public function test_failed_receipt_leaves_no_category_or_image_reference_and_retry_uses_new_bytes(): void
    {
        $writer = app(AdminCategoryAuthoringService::class);
        $intent = (string) Str::uuid();
        $image = UploadedFile::fake()->image('category.png');
        try {
            $writer->create($this->fields(), $intent, $image, static function (): never {
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'category-receipt']);
                throw new \RuntimeException('receipt failed');
            });
            self::fail('The category and receipt must roll back together.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertSame(0, Category::query()->count());
        self::assertSame(0, DB::table('photos')->where('photoable_type', Category::class)->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'category-receipt')->exists());
        $orphan = AccountFileDeletion::query()->sole();
        $category = $writer->create($this->fields(), $intent, $image, static function (): void {});
        self::assertNotSame($orphan->path, $category->photo->path);
        Storage::disk('public')->assertExists($category->photo->path);
    }

    public function test_replay_rejects_changed_content_instead_of_redefining_an_existing_category(): void
    {
        $writer = app(AdminCategoryAuthoringService::class);
        $intent = (string) Str::uuid();
        $category = $writer->create($this->fields(), $intent, null, static function (): void {});
        try {
            $writer->create(array_replace($this->fields(), ['name_ar' => 'اسم مختلف']), $intent, null, static function (): void {});
            self::fail('A create retry cannot update the original category.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('authoring_request_id', $error->errors());
        }
        self::assertSame('قسم ركن', $category->fresh()->name_ar);
    }

    public function test_stale_image_edit_is_rejected_and_replacement_keeps_gallery_images(): void
    {
        $writer = app(AdminCategoryAuthoringService::class);
        $category = $writer->create($this->fields(), (string) Str::uuid(), UploadedFile::fake()->image('old.png'), static function (): void {});
        $path = $category->photo->path;
        $gallery = $category->allPhotos()->create(['type' => 'gallery', 'path' => 'categories/gallery.png']);
        $version = CategoryEditorVersion::for($category);
        $category->fresh()->update(['name_ar' => 'اسم أحدث']);
        try {
            $writer->update($category->id, $this->fields(), $version, UploadedFile::fake()->image('stale.png'));
            self::fail('A stale editor cannot replace category artwork.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertSame($path, $category->fresh()->photo->path);
        self::assertSame('اسم أحدث', $category->fresh()->name_ar);
        $writer->update($category->id, $this->fields() + ['authoring_request_id' => (string) Str::uuid()],
            CategoryEditorVersion::for($category->fresh()), UploadedFile::fake()->image('new.png'));
        self::assertNotSame($path, $category->fresh()->photo->path);
        self::assertSame($category->authoring_request_id, $category->fresh()->authoring_request_id);
        self::assertNotNull($gallery->fresh());
        self::assertSame(1, $category->allPhotos()->where('type', 'featured')->count());
        self::assertTrue(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->where('available_at', '<=', now())->exists());
    }

    public function test_delete_and_photo_retirement_share_the_callers_transaction(): void
    {
        $writer = app(AdminCategoryAuthoringService::class);
        $category = $writer->create($this->fields(), (string) Str::uuid(), UploadedFile::fake()->image('category.png'), static function (): void {});
        $path = $category->photo->path;
        DB::beginTransaction();
        $writer->delete($category->id);
        self::assertNull($category->fresh());
        DB::rollBack();
        self::assertSame($path, $category->fresh()->photo->path);
        $writer->delete($category->id);
        self::assertNull($category->fresh());
        self::assertSame(0, DB::table('photos')->where('photoable_type', Category::class)->count());
        self::assertTrue(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->where('available_at', '<=', now())->exists());
        Storage::disk('public')->assertExists($path);
    }

    private function fields(): array
    {
        return ['name_ar' => 'قسم ركن', 'name_en' => 'Rokn category', 'type' => 'course'];
    }
}
