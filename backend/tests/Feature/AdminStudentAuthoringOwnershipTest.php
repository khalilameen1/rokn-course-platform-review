<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\UsersController;
use App\Models\User;
use App\Models\UserNote;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminStudentAuthoringService;
use App\Services\AdminStudentNoteService;
use App\Support\StudentEditorVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminStudentAuthoringOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        foreach ([UsersController::class, AdminAuthoringCreateIntentService::class] as $httpOwner) {
            $this->app->bind($httpOwner, static function (): never {
                throw new \LogicException('Student authoring must not resolve HTTP or receipt adapters.');
            });
        }
    }

    public function test_creation_and_image_replay_preserve_social_only_identity_and_complete_atomically(): void
    {
        $writer = app(AdminStudentAuthoringService::class);
        $requestId = (string) Str::uuid();
        $image = UploadedFile::fake()->image('student.png', 24, 24);
        $complete = static function (User $student): void {
            self::assertSame(1, DB::transactionLevel());
            self::assertSame(1, $student->allPhotos()->where('type', 'featured')->count());
            self::assertNull($student->fresh()->email_verified_at);
        };
        $student = $writer->create($this->payload(), $requestId, $image, $complete);
        $password = $student->password;
        $again = $writer->create([...$this->payload(), 'name' => 'Do not overwrite'], $requestId, $image, $complete);
        self::assertSame($student->id, $again->id);
        self::assertSame(1, User::withTrashed()->where('authoring_request_id', $requestId)->count());
        self::assertSame('client', $student->fresh()->role);
        self::assertSame('Student', $student->fresh()->getRawOriginal('name'));
        self::assertSame('student@rokn.test', $student->fresh()->email);
        self::assertSame('01000000001', $student->fresh()->phone);
        self::assertSame($password, $student->fresh()->password);
        self::assertSame(1, $student->allPhotos()->count());
        Http::assertNothingSent();
    }

    public function test_receipt_failure_rolls_back_profile_photo_and_checkpoint_then_retry_succeeds(): void
    {
        $requestId = (string) Str::uuid();
        $image = UploadedFile::fake()->image('retry.png', 24, 24);
        $writer = app(AdminStudentAuthoringService::class);
        try {
            $writer->create($this->payload(), $requestId, $image, static function (User $student): never {
                self::assertSame(1, $student->allPhotos()->count());
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'student-receipt']);
                throw new \RuntimeException('Receipt failed');
            });
            self::fail('An incomplete create receipt must not commit a profile or photo.');
        } catch (\RuntimeException $error) {
            self::assertSame('Receipt failed', $error->getMessage());
        }
        self::assertFalse(User::withTrashed()->where('authoring_request_id', $requestId)->exists());
        self::assertSame(0, DB::table('photos')->where('photoable_type', User::class)->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'student-receipt')->exists());
        $student = $writer->create($this->payload(), $requestId, $image, static function (): void {});
        self::assertSame(1, $student->allPhotos()->count());
    }

    public function test_retry_can_finish_a_legacy_checkpoint_without_overwriting_existing_profile(): void
    {
        $requestId = (string) Str::uuid();
        $student = User::query()->forceCreate([
            ...$this->payload(), 'email' => 'existing@rokn.test', 'password' => 'unchanged-hash',
            'role' => 'client', 'active' => false, 'authoring_request_id' => $requestId,
            'email_verified_at' => now(),
        ]);
        $again = app(AdminStudentAuthoringService::class)->create($this->payload(), $requestId, null, static function (): void {});
        self::assertSame($student->id, $again->id);
        self::assertSame('existing@rokn.test', $student->fresh()->email);
        self::assertSame('unchanged-hash', $student->fresh()->password);
        self::assertFalse((bool) $student->fresh()->active);
        self::assertNotNull($student->fresh()->email_verified_at);
    }

    public function test_profile_update_resets_verification_only_when_email_changes_and_rejects_stale_editor(): void
    {
        $writer = app(AdminStudentAuthoringService::class);
        $student = $writer->create($this->payload(), (string) Str::uuid(), null, static function (): void {});
        $student->forceFill(['email_verified_at' => now()])->save();
        $password = $student->password;
        $version = StudentEditorVersion::for($student->fresh());
        $revision = (int) $student->profile_revision;
        $writer->update((int) $student->id, [...$this->payload(), 'name' => 'Updated'], $version);
        self::assertNotNull($student->fresh()->email_verified_at);
        self::assertSame($revision + 1, (int) $student->fresh()->profile_revision);
        try {
            $writer->update((int) $student->id, $this->payload(), $version);
            self::fail('Stale profile edits cannot overwrite the current student.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        $writer->update((int) $student->id, [...$this->payload(), 'email' => 'NEW@rokn.test '], StudentEditorVersion::for($student->fresh()));
        self::assertNull($student->fresh()->email_verified_at);
        self::assertSame('new@rokn.test', $student->fresh()->email);
        self::assertSame($password, $student->fresh()->password);
    }

    public function test_staff_accounts_cannot_be_edited_or_annotated_through_student_commands(): void
    {
        $admin = $this->staff('admin');
        foreach (['profile', 'note'] as $operation) {
            try {
                if ($operation === 'profile') {
                    app(AdminStudentAuthoringService::class)->update((int) $admin->id, $this->payload(), StudentEditorVersion::for($admin));
                } else {
                    app(AdminStudentNoteService::class)->create((int) $admin->id, 'Invalid student target', (int) $admin->id, static function (): void {});
                }
                self::fail('Student commands must not accept staff targets.');
            } catch (ModelNotFoundException) {
                self::assertSame('admin', $admin->fresh()->role);
            }
        }
        self::assertSame(0, UserNote::query()->count());
    }

    public function test_note_actor_and_permissions_do_not_depend_on_ambient_authentication(): void
    {
        $student = app(AdminStudentAuthoringService::class)->create($this->payload(), (string) Str::uuid(), null, static function (): void {});
        $author = $this->staff('moderator');
        $other = $this->staff('moderator');
        $notes = app(AdminStudentNoteService::class);
        $note = $notes->create((int) $student->id, 'متابعة الطالب', (int) $author->id, static function (UserNote $note): void {
            self::assertSame(1, DB::transactionLevel());
            self::assertNotNull($note->fresh());
        });
        self::assertSame((int) $author->id, (int) $note->created_by);
        self::assertFalse($notes->delete((int) $note->id, (int) $other->id, false));
        self::assertTrue($notes->delete((int) $note->id, (int) $author->id, false));
        $note = $notes->create((int) $student->id, 'ملاحظة ثانية', (int) $author->id, static function (): void {});
        self::assertTrue($notes->delete((int) $note->id, (int) $this->staff('admin')->id, true));
        self::assertSame(0, UserNote::query()->count());
    }

    public function test_note_receipt_failure_does_not_leave_a_note(): void
    {
        $student = app(AdminStudentAuthoringService::class)->create($this->payload(), (string) Str::uuid(), null, static function (): void {});
        try {
            app(AdminStudentNoteService::class)->create((int) $student->id, 'ملاحظة', (int) $this->staff('admin')->id, static function (): never {
                throw new \RuntimeException('Note receipt failed');
            });
            self::fail('Note and receipt must commit together.');
        } catch (\RuntimeException $error) {
            self::assertSame('Note receipt failed', $error->getMessage());
        }
        self::assertSame(0, UserNote::query()->count());
    }

    private function payload(): array
    {
        return ['name' => 'Student', 'email' => ' Student@rokn.test ', 'phone' => ' 01000000001 '];
    }

    private function staff(string $role): User
    {
        return User::query()->forceCreate([
            'name_ar' => 'Staff', 'email' => Str::uuid().'@rokn.test', 'role' => $role,
            'active' => true, 'password' => 'test-only',
        ]);
    }
}
