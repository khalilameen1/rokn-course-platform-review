<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ModeratorController;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminModeratorAuthoringService;
use App\Support\ModeratorEditorVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminModeratorAuthoringOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([ModeratorController::class, AdminAuthoringCreateIntentService::class] as $adapter) {
            $this->app->bind($adapter, static function (): never {
                throw new \LogicException('Staff authoring cannot resolve HTTP adapters.');
            });
        }
    }

    public function test_creation_forces_content_staff_role_and_commits_with_its_receipt(): void
    {
        $baseline = DB::transactionLevel();
        $moderator = app(AdminModeratorAuthoringService::class)->create($this->fields() + ['role' => 'admin'],
            static function (User $moderator) use ($baseline): void {
                self::assertGreaterThan($baseline, DB::transactionLevel());
                self::assertSame('moderator', $moderator->role);
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'moderator-receipt']);
            });
        self::assertSame('moderator@example.test', $moderator->email);
        self::assertSame('مسؤول محتوى', $moderator->name_ar);
        self::assertTrue(Hash::check('original-test-password', $moderator->password));
        self::assertNotNull($moderator->email_verified_at);
        self::assertNull($moderator->admin_totp_confirmed_at);
        self::assertTrue(DB::table('admin_singleton_locks')->where('lock_key', 'moderator-receipt')->exists());
    }

    public function test_failed_receipt_does_not_leave_a_staff_account(): void
    {
        try {
            app(AdminModeratorAuthoringService::class)->create($this->fields(), static function (): never {
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'failed-moderator-receipt']);
                throw new \RuntimeException('receipt failed');
            });
            self::fail('A staff account cannot commit without a receipt.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertFalse(User::query()->where('email', 'moderator@example.test')->exists());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'failed-moderator-receipt')->exists());
    }

    public function test_profile_edit_ignores_autofilled_credentials_and_increments_profile_revision(): void
    {
        $writer = app(AdminModeratorAuthoringService::class);
        $moderator = $writer->create($this->fields(), static function (): void {});
        $revision = (int) $moderator->profile_revision;
        $verified = $moderator->email_verified_at->getTimestamp();
        $writer->update($moderator->id, array_replace($this->fields(), [
            'name_ar' => 'اسم جديد', 'email' => 'autofill@example.test',
            'password' => 'autofilled-password', 'role' => 'admin', 'active' => false,
        ]), ModeratorEditorVersion::for($moderator->fresh()));
        $saved = $moderator->fresh();
        self::assertSame('اسم جديد', $saved->name_ar);
        self::assertSame('moderator', $saved->role);
        self::assertFalse($saved->active);
        self::assertSame($revision + 1, (int) $saved->profile_revision);
        self::assertSame('moderator@example.test', $saved->email);
        self::assertTrue(Hash::check('original-test-password', $saved->password));
        self::assertSame($verified, $saved->email_verified_at->getTimestamp());
    }

    public function test_intentional_credential_change_resets_email_verification_only_when_the_address_changes(): void
    {
        $writer = app(AdminModeratorAuthoringService::class);
        $moderator = $writer->create($this->fields(), static function (): void {});
        $writer->update($moderator->id, array_replace($this->fields(), [
            'manage_credentials' => true, 'email' => ' MODERATOR@EXAMPLE.TEST ', 'password' => 'new-test-password',
        ]), ModeratorEditorVersion::for($moderator->fresh()));
        self::assertNotNull($moderator->fresh()->email_verified_at);
        self::assertTrue(Hash::check('new-test-password', $moderator->fresh()->password));
        $writer->update($moderator->id, array_replace($this->fields(), [
            'manage_credentials' => true, 'email' => 'new@example.test', 'password' => '',
        ]), ModeratorEditorVersion::for($moderator->fresh()));
        self::assertNull($moderator->fresh()->email_verified_at);
        self::assertSame('new@example.test', $moderator->fresh()->email);
        self::assertTrue(Hash::check('new-test-password', $moderator->fresh()->password));
    }

    public function test_stale_profile_or_role_change_cannot_be_overwritten(): void
    {
        $writer = app(AdminModeratorAuthoringService::class);
        $moderator = $writer->create($this->fields(), static function (): void {});
        $version = ModeratorEditorVersion::for($moderator->fresh());
        $moderator->fresh()->forceFill(['name_ar' => 'أحدث'])->save();
        try {
            $writer->update($moderator->id, $this->fields(), $version);
            self::fail('A stale staff form cannot overwrite newer data.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertSame('أحدث', $moderator->fresh()->name_ar);
        $moderator->fresh()->forceFill(['role' => 'client'])->save();
        try {
            $writer->update($moderator->id, $this->fields(), ModeratorEditorVersion::for($moderator->fresh()));
            self::fail('A former content-staff account is no longer owned by this writer.');
        } catch (ModelNotFoundException) {
            self::assertSame('client', $moderator->fresh()->role);
        }
    }

    private function fields(): array
    {
        return [
            'name_ar' => ' مسؤول محتوى ', 'name_en' => 'Content staff',
            'email' => ' MODERATOR@EXAMPLE.TEST ', 'phone' => null,
            'password' => 'original-test-password', 'active' => true,
        ];
    }
}
