<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminSessionIdentity;
use App\Filament\Auth\EditStaffProfile;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use App\Services\AdminSelfAccountAuthoringService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentSelfAccountWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->staff = User::query()->forceCreate(['name_ar' => 'المحرر', 'email' => 'staff@rokn.test', 'role' => 'moderator',
            'password' => Hash::make('Current-password-2026'), 'active' => true]);
        $this->actingAs($this->staff, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_profile_reuses_confirmation_and_updates_session_without_double_hashing_password(): void
    {
        Livewire::test(EditStaffProfile::class)->fillForm(['email' => 'Updated@rokn.test', 'password' => 'Replacement-password-2026',
            'passwordConfirmation' => 'Replacement-password-2026', 'current_password' => 'Current-password-2026'])
            ->call('save')->assertHasNoFormErrors()->assertSet('data.password', null)->assertSet('data.current_password', null);
        $fresh = $this->staff->fresh();
        self::assertTrue(Hash::check('Replacement-password-2026', $fresh->password));
        self::assertSame('updated@rokn.test', $fresh->email);
        self::assertSame('moderator', $fresh->role);
        self::assertSame(app(AdminSessionIdentity::class)->fingerprint($fresh), session(AdminSessionIdentity::SESSION_KEY));
        $this->get(EditStaffProfile::getUrl())->assertOk();
    }

    public function test_blank_new_password_keeps_credentials_and_other_accounts_unchanged(): void
    {
        $before = $this->staff->password;
        Livewire::test(EditStaffProfile::class)->fillForm(['email' => 'email-only@rokn.test', 'password' => '', 'current_password' => 'Current-password-2026'])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame($before, $this->staff->fresh()->password);
        self::assertSame('email-only@rokn.test', $this->staff->fresh()->email);
    }

    public function test_current_password_confirmation_and_unique_email_are_visible_form_errors(): void
    {
        Livewire::test(EditStaffProfile::class)->fillForm(['email' => 'changed@rokn.test', 'current_password' => 'wrong-password'])
            ->call('save')->assertHasFormErrors(['current_password']);
        Livewire::test(EditStaffProfile::class)->fillForm(['password' => 'Replacement-password-2026', 'passwordConfirmation' => 'different-password', 'current_password' => 'Current-password-2026'])
            ->call('save')->assertHasFormErrors(['password']);
        User::query()->forceCreate(['email' => 'taken@rokn.test', 'role' => 'client', 'active' => true]);
        Livewire::test(EditStaffProfile::class)->fillForm(['email' => 'taken@rokn.test', 'current_password' => 'Current-password-2026'])
            ->call('save')->assertHasFormErrors(['email' => 'unique']);
        Livewire::test(EditStaffProfile::class)->fillForm(['email' => 'TAKEN@rokn.test', 'current_password' => 'Current-password-2026'])
            ->call('save')->assertHasFormErrors(['email']);
        self::assertSame('staff@rokn.test', $this->staff->fresh()->email);
    }

    public function test_owner_rechecks_fresh_proof_after_another_password_change(): void
    {
        $this->staff->fresh()->update(['password' => Hash::make('Changed-elsewhere-2026')]);
        try {
            app(AdminSelfAccountAuthoringService::class)->fromPlaintext($this->staff->id,
                ['email' => 'not-written@rokn.test', 'current_password' => 'Current-password-2026']);
            self::fail('Stale proof must not change credentials.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('current_password', $exception->errors());
        }
        self::assertSame('staff@rokn.test', $this->staff->fresh()->email);
    }

    public function test_learner_cannot_access_a_staff_profile(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'client', 'active' => true]), 'web');
        $this->get(EditStaffProfile::getUrl())->assertForbidden();
    }
}
