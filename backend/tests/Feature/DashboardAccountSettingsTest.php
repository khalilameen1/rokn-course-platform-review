<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminSessionIdentity;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class DashboardAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_can_update_own_login_without_losing_the_current_session(): void
    {
        $moderator = new User;
        $moderator->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'moderator@example.test',
            'password' => Hash::make('old-password-123'),
            'role' => 'moderator',
            'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $response = $this->actingAs($moderator)->post(route('admin.update_admin_data'), [
            'email' => 'moderator.updated@example.test',
            'password' => 'new-password-123',
            'current_password' => 'old-password-123',
        ]);

        $response
            ->assertRedirect(route('admin.admin_data'))
            ->assertSessionHas(
                AdminSessionIdentity::SESSION_KEY,
                app(AdminSessionIdentity::class)->fingerprint($moderator->fresh())
            );

        $this->get(route('admin.admin_data'))->assertOk()->assertSee('name="current_password"', false);
        $this->assertAuthenticatedAs($moderator);
        self::assertTrue(Hash::check('new-password-123', $moderator->fresh()->password));
    }

    public function test_credentials_do_not_change_without_the_correct_current_password(): void
    {
        $moderator = new User;
        $moderator->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'moderator@example.test',
            'password' => Hash::make('old-password-123'),
            'role' => 'moderator',
            'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);

        foreach ([[], ['current_password' => 'wrong-password-1']] as $proof) {
            $this->actingAs($moderator)->post(route('admin.update_admin_data'), [
                'email' => 'attacker@example.test',
                'password' => 'attacker-password-1',
            ] + $proof)->assertSessionHasErrors('current_password')
                ->assertSessionMissing('_old_input.current_password');
        }

        $fresh = $moderator->fresh();
        self::assertSame('moderator@example.test', $fresh->email);
        self::assertTrue(Hash::check('old-password-123', $fresh->password));
    }
}
