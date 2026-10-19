<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ModeratorResource;
use App\Filament\Resources\ModeratorResource\Pages\CreateModerator;
use App\Filament\Resources\ModeratorResource\Pages\EditModerator;
use App\Filament\Resources\ModeratorResource\Pages\ListModerators;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\ModeratorEditorVersion;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FilamentModeratorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المدير', 'email' => 'owner@rokn.test', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_creation_uses_the_owner_receipt_and_content_role(): void
    {
        $page = Livewire::test(CreateModerator::class)->fillForm($this->fields())->call('create')->assertHasNoFormErrors();
        $staff = User::query()->where('email', 'content@rokn.test')->sole();
        self::assertSame('moderator', $staff->role);
        self::assertTrue(Hash::check('Test-only-password-2026', $staff->password));
        self::assertNull($staff->admin_totp_confirmed_at);
        $page->call('create')->assertHasNoFormErrors();
        self::assertSame(1, User::query()->where('email', 'content@rokn.test')->count());
        self::assertFalse(ModeratorResource::canDelete($staff));
    }

    public function test_profile_save_ignores_autofilled_credentials_until_explicitly_selected(): void
    {
        $staff = $this->staff();
        $before = $staff->password;
        $page = Livewire::test(EditModerator::class, ['record' => $staff->id]);
        $page->fillForm(['name_ar' => 'اسم أحدث', 'manage_credentials' => false, 'email' => 'autofill@rokn.test',
            'password' => 'Autofilled-password', 'password_confirmation' => 'not-matching'])->call('save')->assertHasNoFormErrors();
        self::assertSame('content@rokn.test', $staff->fresh()->email);
        self::assertSame($before, $staff->fresh()->password);
        self::assertSame('اسم أحدث', $staff->fresh()->name_ar);
        $page->fillForm(['manage_credentials' => true, 'email' => 'intentional@rokn.test', 'password' => 'New-password-2026', 'password_confirmation' => 'New-password-2026'])
            ->call('save')->assertHasNoFormErrors()->assertSet('data.password', null)->assertSet('data.manage_credentials', false);
        self::assertSame('intentional@rokn.test', $staff->fresh()->email);
        self::assertTrue(Hash::check('New-password-2026', $staff->fresh()->password));
        self::assertNull($staff->fresh()->email_verified_at);
    }

    public function test_stale_profile_does_not_overwrite_newer_account_state(): void
    {
        $staff = $this->staff();
        $page = Livewire::test(EditModerator::class, ['record' => $staff->id])->fillForm(['name_ar' => 'قديم']);
        $staff->update(['name_ar' => 'أحدث', 'profile_revision' => 2]);
        $page->call('save')->assertNotified('تغيّرت بيانات مسؤول المحتوى');
        self::assertSame('أحدث', $staff->fresh()->name_ar);
    }

    public function test_native_queue_and_saved_urls_remain_scoped_to_content_staff(): void
    {
        $staff = $this->staff();
        $learner = User::query()->forceCreate(['role' => 'client', 'active' => true]);
        Livewire::test(ListModerators::class)->assertCanSeeTableRecords([$staff])->assertCanNotSeeTableRecords([$learner, auth('web')->user()]);
        $this->get(ModeratorResource::getUrl('edit', ['record' => $learner->id]))->assertNotFound();
        $this->get(route('admin.moderators.index'))->assertRedirect(ModeratorResource::getUrl());
        $this->get(route('admin.moderators.create'))->assertRedirect(ModeratorResource::getUrl('create'));
        $this->get(route('admin.moderators.edit', $staff))->assertRedirect(ModeratorResource::getUrl('edit', ['record' => $staff->id]));
    }

    public function test_mfa_reset_requires_matching_email_and_only_resets_the_selected_staff_member(): void
    {
        $staff = $this->staff();
        $staff->forceFill(['admin_totp_secret' => 'JBSWY3DPEHPK3PXP', 'admin_totp_confirmed_at' => now(), 'remember_token' => 'previous-token'])->save();
        $page = Livewire::test(EditModerator::class, ['record' => $staff->id]);
        $page->callAction('resetMfa', ['confirm_email' => 'wrong@rokn.test'])->assertHasActionErrors(['confirm_email']);
        self::assertNotNull($staff->fresh()->admin_totp_confirmed_at);
        $page->setActionData(['confirm_email' => ' CONTENT@ROKN.TEST '])->callMountedAction()->assertHasNoActionErrors();
        self::assertNull($staff->fresh()->admin_totp_confirmed_at);
        self::assertNull($staff->fresh()->getRawOriginal('admin_totp_secret'));
        self::assertNotSame('previous-token', $staff->fresh()->remember_token);
    }

    public function test_invalid_confirmation_and_duplicate_email_cannot_create_accounts(): void
    {
        Livewire::test(CreateModerator::class)->fillForm([...$this->fields(), 'password_confirmation' => 'Wrong-password-2026'])
            ->call('create')->assertHasFormErrors(['password']);
        $this->staff();
        Livewire::test(CreateModerator::class)->fillForm([...$this->fields(), 'email' => 'CONTENT@ROKN.TEST'])
            ->call('create')->assertHasFormErrors(['email' => 'unique']);
    }

    public function test_moderator_does_not_gain_access_to_team_management(): void
    {
        $this->actingAs($this->staff(), 'web');
        $this->get(ModeratorResource::getUrl())->assertForbidden();
    }

    public function test_team_manager_cannot_take_over_another_staff_identity(): void
    {
        $staff = $this->staff();
        $staff->forceFill(['phone' => '201055559991', 'admin_totp_secret' => 'JBSWY3DPEHPK3PXP', 'admin_totp_confirmed_at' => now()])->save();
        $password = $staff->password;
        $operator = auth('web')->user();
        $member = StaffMember::query()->create(['user_id' => $operator->id]);
        foreach (['team.read', 'team.manage'] as $permission) {
            $member->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        request()->attributes->remove('staff-permissions:'.$operator->id);

        $this->get(ModeratorResource::getUrl('create'))->assertForbidden();
        Livewire::test(EditModerator::class, ['record' => $staff->id])
            ->assertFormFieldIsHidden('manage_credentials')->assertFormFieldIsHidden('phone')
            ->assertActionHidden('resetMfa')
            ->fillForm(['name_ar' => 'اسم محدث'])
            ->set('data.manage_credentials', true)->set('data.email', 'stolen@rokn.test')
            ->set('data.phone', '201055559992')->set('data.password', 'Stolen-password-2026')
            ->call('save')->assertHasNoFormErrors();
        self::assertSame('اسم محدث', $staff->fresh()->name_ar);
        self::assertSame('content@rokn.test', $staff->fresh()->email);
        self::assertSame('201055559991', $staff->fresh()->phone);
        self::assertSame($password, $staff->fresh()->password);
        self::assertNotNull($staff->fresh()->admin_totp_confirmed_at);

        $this->put(route('admin.moderators.update', $staff), [
            ...$this->fields(), 'manage_credentials' => true,
            'editor_version' => ModeratorEditorVersion::for($staff->fresh()),
        ])->assertForbidden();
        $this->post(route('admin.moderators.mfa-reset', $staff), ['confirm_email' => $staff->email])->assertForbidden();
        self::assertNotNull($staff->fresh()->admin_totp_confirmed_at);
    }

    private function staff(): User
    {
        return User::query()->forceCreate(['name_ar' => 'مسؤول المحتوى', 'email' => 'content@rokn.test', 'role' => 'moderator',
            'password' => Hash::make('Test-only-password-2026'), 'email_verified_at' => now(), 'active' => true]);
    }

    private function fields(): array
    {
        return ['name_ar' => 'مسؤول المحتوى', 'email' => 'Content@rokn.test', 'active' => true,
            'password' => 'Test-only-password-2026', 'password_confirmation' => 'Test-only-password-2026'];
    }
}
