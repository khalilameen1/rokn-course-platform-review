<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\GradeController;
use App\Models\AccountFileDeletion;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Grade;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminContentInventoryReadService;
use App\Services\AdminCouponAuthoringService;
use App\Services\CourseStagedAuthoringService;
use App\Support\BusinessClock;
use App\Support\CouponEditorVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminCouponAuthoringOwnershipTest extends TestCase
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
    }

    public function test_coupon_choices_and_grade_courses_exclude_drafts_and_archives_not_unpublished_originals(): void
    {
        $grade = Grade::query()->create(['name_ar' => 'مرحلة', 'name_en' => 'Grade', 'type' => 'university', 'country' => 'Egypt']);
        $course = $this->course(['grade_id' => $grade->id]);
        $unpublished = $this->course(['grade_id' => $grade->id, 'is_coming_soon' => true, 'is_catalog_visible' => false]);
        $other = $this->course();
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        $deleted = $this->course();
        $deleted->delete();
        $coupon = Coupon::query()->create($this->fields($course));
        foreach ([CourseAuthoringRevision::DRAFT, CourseAuthoringRevision::ARCHIVED] as $status) {
            CourseAuthoringRevision::query()->where('revision_course_id', $draft->id)
                ->update(['status' => $status, 'active_slot' => $status === CourseAuthoringRevision::DRAFT
                    ? CourseAuthoringRevision::draftSlot((int) $course->id) : null]);
            $controller = app(CouponController::class);
            self::assertEqualsCanonicalizing([$course->id, $unpublished->id, $other->id],
                $controller->create()->getData()['courses']->modelKeys());
            self::assertEqualsCanonicalizing([$course->id, $unpublished->id, $other->id],
                $controller->edit($coupon)->getData()['courses']->modelKeys());
            $data = app(GradeController::class)->courses($grade, app(AdminContentInventoryReadService::class))->getData();
            self::assertEqualsCanonicalizing([$course->id, $unpublished->id], $data['courses']->modelKeys());
        }
    }

    public function test_create_is_http_independent_and_commits_coupon_photo_and_receipt_together(): void
    {
        foreach ([CouponController::class, AdminAuthoringCreateIntentService::class] as $adapter) {
            $this->app->bind($adapter, static function (): never {
                throw new \LogicException('Coupon authoring cannot resolve an HTTP adapter.');
            });
        }
        $course = $this->course();
        $writer = app(AdminCouponAuthoringService::class);
        $requestId = (string) Str::uuid();
        $image = UploadedFile::fake()->image('coupon.png');
        $fields = $this->fields($course) + ['starts_at' => '2026-09-25T12:00'];
        $ids = [];
        $complete = static function (Coupon $coupon) use (&$ids): void {
            self::assertSame(1, DB::transactionLevel());
            self::assertSame(1, $coupon->allPhotos()->count());
            self::assertNotNull($coupon->photo);
            $ids[] = $coupon->id;
        };
        $coupon = $writer->create($fields, $requestId, $image, $complete);
        $path = $coupon->photo->path;
        $again = $writer->create($fields, $requestId, $image, $complete);
        self::assertSame([$coupon->id, $coupon->id], $ids);
        self::assertSame($coupon->id, $again->id);
        self::assertSame($path, $again->photo->path);
        self::assertSame([$path], Storage::disk('public')->allFiles('coupons'));
        self::assertSame(BusinessClock::localInputToUtc($fields['starts_at'])->getTimestamp(), $coupon->starts_at->getTimestamp());
        self::assertSame(1, Coupon::query()->count());
    }

    public function test_receipt_failure_rolls_back_coupon_photo_and_receipt_without_reusing_orphan_bytes(): void
    {
        $writer = app(AdminCouponAuthoringService::class);
        $requestId = (string) Str::uuid();
        $image = UploadedFile::fake()->image('coupon.png');
        try {
            $writer->create($this->fields(), $requestId, $image, static function (): never {
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'coupon-receipt']);
                throw new \RuntimeException('receipt failed');
            });
            self::fail('No part of a failed create may commit.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertSame(0, Coupon::withTrashed()->count());
        self::assertSame(0, DB::table('photos')->where('photoable_type', Coupon::class)->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'coupon-receipt')->exists());
        $orphan = AccountFileDeletion::query()->sole();
        $coupon = $writer->create($this->fields(), $requestId, $image, static function (): void {});
        self::assertNotSame($orphan->path, $coupon->photo->path);
        self::assertSame(1, $coupon->allPhotos()->count());
        Storage::disk('public')->assertExists($coupon->photo->path);
    }

    public function test_revision_deleted_and_missing_course_targets_are_rejected_on_create_and_update(): void
    {
        $course = $this->course();
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        $deleted = $this->course();
        $deleted->delete();
        $writer = app(AdminCouponAuthoringService::class);
        $coupon = $writer->create($this->fields($course), (string) Str::uuid(), null, static function (): void {});
        foreach ([$draft->id, $deleted->id, 999999] as $invalid) {
            $fields = array_replace($this->fields($course), ['course_id' => $invalid, 'code' => 'INVALID']);
            try {
                $writer->create($fields, (string) Str::uuid(), null, static function (): never {
                    self::fail('An invalid course cannot receive a receipt.');
                });
                self::fail('A coupon must target a real canonical course.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('course_id', $error->errors());
            }
            try {
                $writer->update($coupon, $fields, CouponEditorVersion::for($coupon->fresh()), null);
                self::fail('Updates must use the same eligibility rule as creates.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('course_id', $error->errors());
            }
        }
        self::assertSame(1, Coupon::query()->count());
        self::assertSame((int) $course->id, (int) $coupon->fresh()->course_id);
    }

    public function test_stale_editor_cannot_replace_image_and_current_editor_retires_only_the_old_image(): void
    {
        $writer = app(AdminCouponAuthoringService::class);
        $coupon = $writer->create($this->fields(), (string) Str::uuid(), UploadedFile::fake()->image('old.png'), static function (): void {});
        $oldPath = $coupon->photo->path;
        $oldVersion = CouponEditorVersion::for($coupon);
        $coupon->fresh()->update(['name_ar' => 'اسم أحدث']);
        try {
            $writer->update($coupon, $this->fields(), $oldVersion, UploadedFile::fake()->image('rejected.png'));
            self::fail('A stale editor cannot replace the image or text.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertSame('اسم أحدث', $coupon->fresh()->name_ar);
        self::assertSame($oldPath, $coupon->fresh()->photo->path);
        $writer->update($coupon->fresh(), $this->fields(), CouponEditorVersion::for($coupon->fresh()), UploadedFile::fake()->image('new.png'));
        self::assertNotSame($oldPath, $coupon->fresh()->photo->path);
        self::assertSame(1, $coupon->allPhotos()->count());
        self::assertTrue(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $oldPath))->where('available_at', '<=', now())->exists());
        Storage::disk('public')->assertExists($coupon->fresh()->photo->path);
    }

    public function test_legacy_invalid_target_can_be_disabled_but_not_enabled_or_silently_retargeted(): void
    {
        $draft = app(CourseStagedAuthoringService::class)->draftFor($this->course());
        $coupon = Coupon::query()->create($this->fields($draft));
        $writer = app(AdminCouponAuthoringService::class);
        $writer->update($coupon, array_replace($this->fields($draft), ['active' => false]), CouponEditorVersion::for($coupon), null);
        self::assertFalse($coupon->fresh()->active);
        self::assertSame((int) $draft->id, (int) $coupon->fresh()->course_id);
        try {
            $writer->update($coupon->fresh(), $this->fields($draft), CouponEditorVersion::for($coupon->fresh()), null);
            self::fail('An archived or draft target must not become an active campaign.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('course_id', $error->errors());
        }
        self::assertFalse($coupon->fresh()->active);
    }

    public function test_changed_or_deleted_create_intent_cannot_overwrite_or_restore_a_coupon(): void
    {
        $writer = app(AdminCouponAuthoringService::class);
        $id = (string) Str::uuid();
        $coupon = $writer->create($this->fields(), $id, null, static function (): void {});
        try {
            $writer->create(array_replace($this->fields(), ['balance' => 20]), $id, null, static function (): void {});
            self::fail('A create retry cannot redefine a campaign.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('authoring_request_id', $error->errors());
        }
        self::assertSame(10, $coupon->fresh()->balance);
        $writer->delete((int) $coupon->id, CouponEditorVersion::for($coupon));
        try {
            $writer->create($this->fields(), $id, null, static function (): void {});
            self::fail('A deleted campaign cannot be restored by a retry.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('authoring_request_id', $error->errors());
        }
        self::assertSame(0, Coupon::query()->count());
        self::assertSame(1, Coupon::withTrashed()->count());
    }

    public function test_delete_preserves_photo_on_rollback_and_rejects_stale_versions(): void
    {
        $writer = app(AdminCouponAuthoringService::class);
        $coupon = $writer->create($this->fields(), (string) Str::uuid(), UploadedFile::fake()->image('coupon.png'), static function (): void {});
        $path = $coupon->photo->path;
        $version = CouponEditorVersion::for($coupon);
        DB::beginTransaction();
        $writer->delete((int) $coupon->id, $version);
        self::assertSame(0, $coupon->allPhotos()->count());
        DB::rollBack();
        self::assertNotNull($coupon->fresh());
        self::assertSame($path, $coupon->fresh()->photo->path);
        $coupon->fresh()->update(['name_ar' => 'أحدث']);
        try {
            $writer->delete((int) $coupon->id, $version);
            self::fail('A stale delete cannot discard a newer edit.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        $writer->delete((int) $coupon->id, CouponEditorVersion::for($coupon->fresh()));
        self::assertNull(Coupon::query()->find($coupon->id));
        self::assertTrue($coupon->fresh()->trashed());
        self::assertTrue(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->where('available_at', '<=', now())->exists());
        Storage::disk('public')->assertExists($path);
    }

    private function fields(?Course $course = null): array
    {
        return [
            'name_ar' => 'خصم ركن', 'name_en' => 'Rokn offer', 'code' => 'ROKN10',
            'course_id' => $course?->id, 'balance' => 10, 'max_redemptions' => null,
            'expiry_date' => now()->addMonth()->format('Y-m-d'), 'active' => true,
        ];
    }

    private function course(array $overrides = []): Course
    {
        return Course::query()->forceCreate(array_replace([
            'tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 100,
            'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1, 'published_at' => now(),
        ], $overrides));
    }
}
