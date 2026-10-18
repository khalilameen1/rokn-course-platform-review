<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Totp;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminMfaResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_reset_clears_enrolment_and_writes_an_audit_entry(): void
    {
        $moderator = $this->enrolled('moderator', 'Mod@Rokn.test');
        $rememberToken = $moderator->remember_token;

        $this->artisan('admin:mfa-reset', ['email' => 'mod@rokn.test', '--force' => true])
            ->expectsOutputToContain('MFA reset for')
            ->assertExitCode(0);

        $fresh = $moderator->fresh();
        self::assertNull($fresh->admin_totp_confirmed_at);
        self::assertNull($fresh->getRawOriginal('admin_totp_secret'));
        self::assertNull($fresh->admin_mfa_backup_codes);
        self::assertNull($fresh->admin_totp_last_used_step);
        self::assertNotSame($rememberToken, $fresh->remember_token);
        $audit = AdminAuditLog::query()->sole();
        self::assertNull($audit->actor_id);
        self::assertSame('console.admin.mfa-reset', $audit->route_name);
        self::assertSame((string) $moderator->id, $audit->route_parameters['target_user_id']);
    }

    public function test_console_reset_requires_confirmation_and_a_staff_account(): void
    {
        $admin = $this->enrolled('admin', 'owner@rokn.test');
        $this->enrolled('client', 'learner@rokn.test');

        $this->artisan('admin:mfa-reset', ['email' => 'owner@rokn.test'])
            ->expectsConfirmation('Reset MFA for owner@rokn.test (admin)? They must enrol a new authenticator at next login.', 'no')
            ->assertExitCode(1);
        self::assertNotNull($admin->fresh()->admin_totp_confirmed_at);

        $this->artisan('admin:mfa-reset', ['email' => 'learner@rokn.test', '--force' => true])->assertExitCode(1);
        $this->artisan('admin:mfa-reset', ['email' => 'missing@rokn.test', '--force' => true])->assertExitCode(1);
        self::assertSame(0, AdminAuditLog::query()->count());
    }

    public function test_admin_resets_a_moderator_only_after_typing_their_email(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $admin = $this->enrolled('admin', 'owner@rokn.test');
        $moderator = $this->enrolled('moderator', 'mod@rokn.test');

        $this->actingAs($admin)
            ->post(route('admin.moderators.mfa-reset', $moderator), ['confirm_email' => 'someone@rokn.test'])
            ->assertSessionHasErrors('confirm_email');
        self::assertNotNull($moderator->fresh()->admin_totp_confirmed_at);

        $this->actingAs($admin)
            ->post(route('admin.moderators.mfa-reset', $moderator), ['confirm_email' => ' MOD@rokn.test '])
            ->assertRedirect(route('admin.moderators.edit', $moderator))
            ->assertSessionHas('success');
        self::assertNull($moderator->fresh()->admin_totp_confirmed_at);
        self::assertSame('admin.moderators.mfa-reset', AdminAuditLog::query()->latest('id')->value('route_name'));
    }

    public function test_route_cannot_reset_an_administrator_and_moderators_cannot_use_it(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $admin = $this->enrolled('admin', 'owner@rokn.test');
        $otherAdmin = $this->enrolled('admin', 'second@rokn.test');
        $moderator = $this->enrolled('moderator', 'mod@rokn.test');
        $peer = $this->enrolled('moderator', 'peer@rokn.test');

        $this->actingAs($admin)
            ->post(route('admin.moderators.mfa-reset', $otherAdmin), ['confirm_email' => 'second@rokn.test'])
            ->assertNotFound();
        $this->flushSession();
        $this->actingAs($moderator)
            ->post(route('admin.moderators.mfa-reset', $peer), ['confirm_email' => 'peer@rokn.test'])
            ->assertForbidden();
        self::assertNotNull($otherAdmin->fresh()->admin_totp_confirmed_at);
        self::assertNotNull($peer->fresh()->admin_totp_confirmed_at);
    }

    public function test_reset_account_is_sent_to_enrolment_on_next_request(): void
    {
        $moderator = $this->enrolled('moderator', 'mod@rokn.test');
        $this->artisan('admin:mfa-reset', ['email' => 'mod@rokn.test', '--force' => true])->assertExitCode(0);

        $this->actingAs($moderator->fresh())
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.mfa.setup'));
    }

    private function enrolled(string $role, string $email): User
    {
        $totp = app(Totp::class);
        $user = User::query()->forceCreate([
            'name' => ucfirst($role), 'email' => $email, 'role' => $role, 'active' => true,
            'password' => bcrypt(Str::random(20)), 'remember_token' => Str::random(60),
        ]);
        $user->forceFill([
            'admin_totp_secret' => $totp->generateSecret(),
            'admin_totp_confirmed_at' => now(),
            'admin_totp_last_used_step' => 1,
            'admin_mfa_backup_codes' => [$totp->hashRecoveryCode('ABCD-EFGH-1234')],
        ])->save();

        return $user;
    }
}
