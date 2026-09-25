<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\TeacherController;
use App\Models\Course;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminTeacherAuthoringService;
use App\Support\TeacherEditorVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminTeacherAuthoringOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Storage::fake('public');
        Http::preventStrayRequests();
        foreach ([TeacherController::class, AdminAuthoringCreateIntentService::class] as $httpOwner) {
            $this->app->bind($httpOwner, static function (): never {
                throw new \LogicException('Teacher authoring must not resolve HTTP owners.');
            });
        }
    }

    public function test_creation_completes_receipt_atomically_and_reuses_existing_profile(): void
    {
        $writer = app(AdminTeacherAuthoringService::class);
        $requestId = (string) Str::uuid();
        $completed = [];
        $complete = static function (User $teacher) use (&$completed): void {
            self::assertSame(1, DB::transactionLevel());
            self::assertSame('teacher', $teacher->fresh()->role);
            $completed[] = $teacher->id;
        };
        $teacher = $writer->create([
            'name_ar' => 'محاضر ركن', 'email' => 'teacher@rokn.test', 'password' => 'test-password',
        ], true, $requestId, null, $complete);
        $again = $writer->create([
            'name_ar' => 'لا يستبدل الملف الموجود', 'password' => 'different-password',
        ], false, $requestId, null, $complete);

        self::assertSame([$teacher->id, $teacher->id], $completed);
        self::assertSame($teacher->id, $again->id);
        self::assertSame(1, User::query()->where('authoring_request_id', $requestId)->count());
        self::assertSame('محاضر ركن', $teacher->fresh()->name_ar);
        self::assertTrue((bool) $teacher->fresh()->active);
        self::assertTrue(Hash::check('test-password', $teacher->fresh()->password));
        Http::assertNothingSent();
    }

    public function test_receipt_failure_rolls_back_profile_and_can_be_retried(): void
    {
        $requestId = (string) Str::uuid();
        $writer = app(AdminTeacherAuthoringService::class);
        try {
            $writer->create(['name_ar' => 'محاضر'], true, $requestId, null, static function (): never {
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'teacher-receipt']);
                throw new \RuntimeException('receipt failed');
            });
            self::fail('An incomplete receipt must roll back its profile.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertFalse(User::withTrashed()->where('authoring_request_id', $requestId)->exists());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'teacher-receipt')->exists());
        $teacher = $writer->create(['name_ar' => 'محاضر'], true, $requestId, null, static function (): void {});
        self::assertNotNull($teacher->fresh());
    }

    public function test_resuming_creation_with_the_same_image_does_not_append_another_featured_photo(): void
    {
        $writer = app(AdminTeacherAuthoringService::class);
        $requestId = (string) Str::uuid();
        $image = UploadedFile::fake()->image('teacher.png', 24, 24);
        $payload = ['name_ar' => 'محاضر', 'email' => 'teacher-image@rokn.test', 'password' => 'test-password'];
        $complete = static function (User $teacher): void {
            self::assertSame(1, $teacher->allPhotos()->where('type', 'featured')->count());
        };
        $teacher = $writer->create($payload, true, $requestId, $image, $complete);
        $path = $teacher->allPhotos()->sole()->path;
        $again = $writer->create($payload, true, $requestId, $image, $complete);
        self::assertSame($teacher->id, $again->id);
        self::assertSame($path, $teacher->fresh()->allPhotos()->sole()->path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_stale_edit_is_rejected_and_profile_only_edit_preserves_credentials(): void
    {
        $teacher = $this->teacher();
        $version = TeacherEditorVersion::for($teacher);
        $password = $teacher->password;
        $teacher->fresh()->update(['bio_ar' => 'نبذة أحدث']);
        $writer = app(AdminTeacherAuthoringService::class);
        try {
            $writer->update($teacher->id, ['name_ar' => 'اسم قديم'], true, $version, null);
            self::fail('A stale profile must not overwrite a more recent edit.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        $writer->update($teacher->id, ['job_title' => 'محاضر تصميم'], true, TeacherEditorVersion::for($teacher->fresh()), null);
        self::assertSame('نبذة أحدث', $teacher->fresh()->bio_ar);
        self::assertSame('محاضر تصميم', $teacher->fresh()->job_title);
        self::assertSame($password, $teacher->fresh()->password);
        self::assertSame($teacher->email, $teacher->fresh()->email);
    }

    public function test_editor_version_changes_when_the_featured_image_changes(): void
    {
        $teacher = $this->teacher();
        $version = TeacherEditorVersion::for($teacher);
        $teacher->allPhotos()->create(['path' => 'users/new-portrait.png', 'type' => 'featured']);
        self::assertNotSame($version, TeacherEditorVersion::for($teacher->fresh()));
    }

    public function test_edit_and_toggle_both_protect_the_only_published_instructor(): void
    {
        $teacher = $this->teacher();
        $course = $this->course($teacher);
        $writer = app(AdminTeacherAuthoringService::class);
        foreach (['update', 'toggle'] as $command) {
            try {
                if ($command === 'update') {
                    $writer->update($teacher->id, ['name_ar' => 'لا يحفظ هذا التغيير'], false, TeacherEditorVersion::for($teacher->fresh()), null);
                } else {
                    $writer->toggleActive($teacher->id, true);
                }
                self::fail('A published course must keep an active instructor.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('active', $error->errors());
            }
            self::assertTrue((bool) $teacher->fresh()->active);
            self::assertSame($teacher->name_ar, $teacher->fresh()->name_ar);
        }
        $other = $this->teacher();
        $course->teachers()->attach($other);
        $writer->toggleActive($teacher->id, true);
        self::assertFalse((bool) $teacher->fresh()->active);
        self::assertTrue((bool) $other->fresh()->active);
    }

    public function test_toggle_rejects_stale_state_and_delete_retains_assigned_teacher(): void
    {
        $teacher = $this->teacher();
        $writer = app(AdminTeacherAuthoringService::class);
        try {
            $writer->toggleActive($teacher->id, false);
            self::fail('A stale toggle must not reverse current state.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('expected_active', $error->errors());
        }
        $this->course($teacher);
        self::assertFalse($writer->delete($teacher->id));
        self::assertFalse($teacher->fresh()->trashed());
        $unassigned = $this->teacher();
        self::assertTrue($writer->delete($unassigned->id));
        self::assertTrue(User::withTrashed()->findOrFail($unassigned->id)->trashed());
    }

    private function teacher(): User
    {
        return app(AdminTeacherAuthoringService::class)->create([
            'name_ar' => 'محاضر ركن', 'email' => Str::uuid().'@rokn.test', 'password' => 'test-password',
        ], true, (string) Str::uuid(), null, static function (): void {});
    }

    private function course(User $teacher): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس منشور', 'price' => 500,
            'teacher_id' => $teacher->id, 'is_coming_soon' => false,
            'is_catalog_visible' => true, 'authoring_version' => 1,
        ])->save();
        $course->teachers()->attach($teacher);

        return $course;
    }
}
