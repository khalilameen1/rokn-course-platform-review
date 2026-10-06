<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CourseAuthoringEdit;
use App\Http\Resources\BaseCourseResource;
use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\Course;
use App\Services\AdminCourseAuthoringService;
use App\Services\CourseCoverStorageService;
use App\Services\StoredFileReferenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CourseCoverDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The byte-write ledger commits before the owning domain transaction.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Storage::fake('public');
        Queue::fake();
        Exceptions::fake();
        Http::preventStrayRequests();
    }

    public function test_catalogue_uses_a_separate_bounded_rendition_and_authoring_keeps_original_bytes(): void
    {
        $upload = UploadedFile::fake()->image('cover.png', 1600, 2400);
        $original = file_get_contents($upload->getRealPath());
        $edit = CourseAuthoringEdit::fromValidated([
            'name_ar' => 'كورس بغلاف', 'certificate_text_template_key' => 'completion',
            'authoring_request_id' => (string) Str::uuid(), 'image' => $upload,
        ]);
        $writer = app(AdminCourseAuthoringService::class);
        $result = $writer->create($edit, static fn (Course $course) => null);
        self::assertSame('created', $result['status']);
        $course = $result['course']->fresh();
        $photo = $course->allPhotos()->sole();
        $disk = Storage::disk('public');
        self::assertSame($original, $disk->get($photo->path));
        $dimensions = getimagesizefromstring($disk->get($photo->preview_path));
        self::assertSame('image/webp', $dimensions['mime']);
        self::assertSame(1280, $dimensions[1]);
        self::assertEqualsWithDelta(2 / 3, $dimensions[0] / $dimensions[1], .002);
        self::assertSame($disk->url($photo->path), $course->image);
        self::assertSame($disk->url($photo->preview_path), (new BaseCourseResource($course))->resolve()['image']);
        self::assertSame('existing', $writer->create($edit, static fn (Course $course) => null)['status']);
        self::assertCount(2, $disk->allFiles('courses'));
        Exceptions::throwFirstReported();
    }

    public function test_failed_owner_transaction_retires_both_staged_objects_without_leaving_an_owner(): void
    {
        $edit = CourseAuthoringEdit::fromValidated([
            'name_ar' => 'مسودة', 'certificate_text_template_key' => 'completion',
            'authoring_request_id' => (string) Str::uuid(),
            'image' => UploadedFile::fake()->image('cover.png', 640, 360),
        ]);
        $result = app(AdminCourseAuthoringService::class)->create($edit, static function (Course $course): never {
            throw new \RuntimeException('Receipt could not commit.');
        });
        self::assertSame('failed', $result['status']);
        self::assertSame(0, Course::query()->count());
        self::assertCount(2, Storage::disk('public')->allFiles('courses'));
        foreach (AccountFileDeletion::query()->get() as $row) {
            (new DeleteAccountFile((int) $row->id))->handle(app(StoredFileReferenceService::class));
        }
        self::assertSame([], Storage::disk('public')->allFiles('courses'));
    }

    public function test_a_shared_revision_keeps_both_files_until_the_last_photo_owner_releases_them(): void
    {
        $cover = app(CourseCoverStorageService::class)->stage(UploadedFile::fake()->image('cover.png', 640, 360));
        $first = $this->course()->allPhotos()->create($cover + ['type' => 'featured']);
        $second = $first->replicate();
        $second->photoable_id = $this->course()->id;
        $second->save();
        $references = app(StoredFileReferenceService::class);
        $first->delete();
        foreach ($cover as $path) {
            self::assertTrue($references->isReferenced('public', $path));
            Storage::disk('public')->assertExists($path);
        }
        $second->delete();
        foreach (AccountFileDeletion::query()->get() as $row) {
            (new DeleteAccountFile((int) $row->id))->handle($references);
        }
        foreach ($cover as $path) Storage::disk('public')->assertMissing($path);
    }

    public function test_existing_shared_original_is_backfilled_once_and_legacy_api_falls_back_until_then(): void
    {
        $upload = UploadedFile::fake()->image('legacy.png', 640, 360);
        $bytes = file_get_contents($upload->getRealPath());
        $disk = Storage::disk('public');
        $disk->put('courses/legacy.png', $bytes);
        $course = $this->course();
        $photo = $course->allPhotos()->create(['path' => 'courses/legacy.png', 'type' => 'featured']);
        $clone = $photo->replicate();
        $clone->photoable_id = $this->course()->id;
        $clone->save();
        self::assertSame($course->fresh()->image, $course->fresh()->catalogue_image);
        $this->artisan('courses:generate-cover-previews', ['--dry-run' => true])->assertExitCode(0);
        self::assertCount(1, $disk->allFiles('courses'));
        $this->artisan('courses:generate-cover-previews')->assertExitCode(0);
        self::assertSame($photo->fresh()->preview_path, $clone->fresh()->preview_path);
        self::assertSame($bytes, $disk->get('courses/legacy.png'));
        $this->artisan('courses:generate-cover-previews')->assertExitCode(0);
        self::assertCount(2, $disk->allFiles('courses'));
        Exceptions::throwFirstReported();
    }

    public function test_corrupt_cover_is_rejected_before_any_storage_write(): void
    {
        try {
            app(CourseCoverStorageService::class)->stage(UploadedFile::fake()->createWithContent('invalid.png', 'not an image'));
            self::fail('Corrupt cover was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('image budget', $exception->getMessage());
        }
        self::assertSame([], Storage::disk('public')->allFiles());
        self::assertSame(0, AccountFileDeletion::query()->count());
    }

    private function course(): Course
    {
        return Course::query()->forceCreate([
            'name_ar' => 'كورس', 'is_coming_soon' => true,
            'is_catalog_visible' => false, 'authoring_version' => 1,
            'certificate_text_template_key' => 'completion',
        ]);
    }
}
