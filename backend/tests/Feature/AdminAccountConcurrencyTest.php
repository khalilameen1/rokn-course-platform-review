<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\StudentAccountStateService;
use App\Support\AdminEditorVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminAccountConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->admin = User::query()->forceCreate([
            'name' => 'Admin',
            'email' => 'admin-concurrency@rokn.test',
            'password' => Hash::make('not-used-in-this-test'),
            'role' => 'admin',
            'active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    public function test_stale_student_editor_cannot_overwrite_social_identity_profile(): void
    {
        $student = User::query()->forceCreate([
            'name' => 'Student One',
            'email' => 'student-one@rokn.test',
            'email_verified_at' => now(),
            'phone' => '201000000001',
            'password' => Hash::make('not-used-in-this-test'),
            'role' => 'client',
            'active' => true,
            'profile_revision' => 4,
        ]);
        $identity = SocialAccount::query()->create([
            'user_id' => $student->id,
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
            'provider_email' => 'student-one@rokn.test',
        ]);
        $staleVersion = $this->studentEditorVersion($student);

        $student->forceFill([
            'name' => 'Student Newest',
            'email' => 'newest@rokn.test',
            'email_verified_at' => null,
            'profile_revision' => 5,
        ])->save();

        $this->patch(route('admin.users.update', $student), [
            'name' => 'Stale Name',
            'email' => 'stale@rokn.test',
            'phone' => '201000000001',
            'editor_version' => $staleVersion,
        ])->assertSessionHasErrors('editor_version');

        $student->refresh();
        self::assertSame('Student Newest', $student->getRawOriginal('name'));
        self::assertSame('newest@rokn.test', $student->email);
        self::assertSame(5, (int) $student->profile_revision);

        $this->patch(route('admin.users.update', $student), [
            'name' => 'Student Newest',
            'email' => 'admin-corrected@rokn.test',
            'phone' => '201000000001',
            'editor_version' => $this->studentEditorVersion($student),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $student->refresh();
        self::assertSame('admin-corrected@rokn.test', $student->email);
        self::assertNull($student->email_verified_at);
        self::assertSame(6, (int) $student->profile_revision);
        self::assertDatabaseHas('social_accounts', [
            'id' => $identity->id,
            'user_id' => $student->id,
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
        ]);
    }

    public function test_stale_moderator_editor_cannot_reenable_or_replace_credentials(): void
    {
        $moderator = User::query()->forceCreate([
            'name_ar' => 'مسؤول المحتوى',
            'name_en' => 'Moderator',
            'email' => 'moderator@rokn.test',
            'email_verified_at' => now(),
            'phone' => '201000000002',
            'password' => Hash::make('first-password'),
            'role' => 'moderator',
            'active' => true,
            'profile_revision' => 2,
        ]);
        $staleVersion = $this->moderatorEditorVersion($moderator);
        $newPassword = Hash::make('rotated-password');
        $moderator->forceFill([
            'active' => false,
            'password' => $newPassword,
            'profile_revision' => 3,
        ])->save();

        $this->put(route('admin.moderators.update', $moderator), [
            'name_ar' => 'مسؤول المحتوى',
            'name_en' => 'Moderator',
            'email' => 'moderator@rokn.test',
            'phone' => '201000000002',
            'active' => '1',
            'password' => 'stale-password',
            'password_confirmation' => 'stale-password',
            'editor_version' => $staleVersion,
        ])->assertSessionHasErrors('editor_version');

        $moderator->refresh();
        self::assertFalse((bool) $moderator->active);
        self::assertSame($newPassword, $moderator->getRawOriginal('password'));
        self::assertSame(3, (int) $moderator->profile_revision);
    }

    public function test_moderator_profile_update_ignores_unselected_credentials_and_explicit_edit_can_change_them(): void
    {
        $originalPassword = Hash::make('original-password');
        $moderator = User::query()->forceCreate([
            'name_ar' => 'مسؤول المحتوى',
            'name_en' => 'Moderator',
            'email' => 'moderator-profile@rokn.test',
            'email_verified_at' => now(),
            'phone' => '201000000004',
            'password' => $originalPassword,
            'role' => 'moderator',
            'active' => true,
            'profile_revision' => 1,
        ]);

        $page = $this->get(route('admin.moderators.edit', $moderator))->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//input[@name="email" and @disabled]')->length);
        self::assertSame(1, $xpath->query('//input[@name="password" and @disabled]')->length);

        $this->put(route('admin.moderators.update', $moderator), [
            'name_ar' => 'مسؤول المحتوى الأول',
            'name_en' => 'Moderator',
            'phone' => '201000000005',
            'active' => '1',
            'editor_version' => $this->moderatorEditorVersion($moderator),
            // Simulate credentials injected at submit time without the
            // administrator choosing to edit the login.
            'email' => $this->admin->email,
            'password' => 'autofilled-password',
            'password_confirmation' => 'different-autofill',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.moderators.index'));

        $moderator->refresh();
        self::assertSame('مسؤول المحتوى الأول', $moderator->name_ar);
        self::assertSame('201000000005', $moderator->phone);
        self::assertSame('moderator-profile@rokn.test', $moderator->email);
        self::assertSame($originalPassword, $moderator->getRawOriginal('password'));
        self::assertNotNull($moderator->email_verified_at);

        $this->put(route('admin.moderators.update', $moderator), [
            'name_ar' => $moderator->name_ar,
            'name_en' => $moderator->name_en,
            'phone' => $moderator->phone,
            'active' => '1',
            'manage_credentials' => '1',
            'email' => 'moderator-new-login@rokn.test',
            'password' => 'intentional-password',
            'password_confirmation' => 'intentional-password',
            'editor_version' => $this->moderatorEditorVersion($moderator),
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.moderators.index'));

        $moderator->refresh();
        self::assertSame('moderator-new-login@rokn.test', $moderator->email);
        self::assertTrue(Hash::check('intentional-password', (string) $moderator->password));
        self::assertNull($moderator->email_verified_at);
    }

    public function test_urgent_activation_rejects_an_aba_state_change(): void
    {
        $student = User::query()->forceCreate([
            'name' => 'Inactive Student',
            'email' => 'inactive@rokn.test',
            'phone' => '201000000003',
            'password' => Hash::make('not-used-in-this-test'),
            'role' => 'client',
            'active' => false,
            'profile_revision' => 7,
        ]);
        $accounts = app(StudentAccountStateService::class);
        $student->refresh();
        $staleVersion = $accounts->editorVersion($student);
        $student = $accounts->setActive($student, false, $staleVersion, true);
        $student = $accounts->setActive(
            $student,
            true,
            $accounts->editorVersion($student),
            false
        );

        $this->post(route('admin.urgent-tasks.activate-student', $student), [
            'expected_active' => '0',
            'state_version' => $staleVersion,
        ])->assertSessionHasErrors('expected_active');

        $student->refresh();
        self::assertFalse((bool) $student->active);
        self::assertSame(9, (int) $student->profile_revision);
    }

    private function studentEditorVersion(User $student): string
    {
        return AdminEditorVersion::for($student, [
            'name', 'email', 'phone', 'profile_revision', 'email_verified_at',
        ]);
    }

    private function moderatorEditorVersion(User $moderator): string
    {
        return AdminEditorVersion::for($moderator, [
            'name_ar', 'name_en', 'email', 'phone', 'password', 'active',
            'profile_revision', 'email_verified_at',
        ]);
    }
}
