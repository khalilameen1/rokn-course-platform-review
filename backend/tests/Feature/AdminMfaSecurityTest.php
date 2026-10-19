<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\TwoFactorAuthenticationProvider;
use App\Models\User;
use App\Services\AdminMfaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class AdminMfaSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('role')->default('admin');
            $table->boolean('active')->default(true);
            $table->text('admin_totp_secret')->nullable();
            $table->timestamp('admin_totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('admin_totp_last_used_step')->nullable();
            $table->text('admin_mfa_backup_codes')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_malformed_setup_input_is_not_flashed_back_to_the_session(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.mfa.setup.confirm'), ['code' => 'invalid-mfa-setup-code'])
            ->assertRedirect()->assertSessionHasErrors('code');
        self::assertArrayNotHasKey('code', $response->getSession()->getOldInput());
    }

    public function test_admin_is_forced_through_setup_and_secret_is_encrypted_at_rest(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.mfa.setup'));

        $this->get(route('admin.mfa.setup'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $secret = Crypt::decryptString((string) session('admin_mfa_setup_secret_ciphertext'));
        self::assertNotSame('', $secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->post(route('admin.mfa.setup.confirm'), ['code' => $code])
            ->assertRedirect(route('admin.mfa.backup-codes'))
            ->assertHeader('Cache-Control', 'no-store, private');

        $fresh = $admin->fresh();
        self::assertSame($secret, $fresh->admin_totp_secret);
        self::assertNotSame($secret, DB::table('users')->where('id', $admin->id)->value('admin_totp_secret'));
        self::assertContains('admin_totp_secret', $fresh->getHidden());
        self::assertArrayNotHasKey('admin_totp_secret', $fresh->setAppends([])->toArray());

        $plainCodes = json_decode(
            Crypt::decryptString((string) session('admin_mfa_new_recovery_codes_ciphertext')),
            true,
            8,
            JSON_THROW_ON_ERROR
        );
        self::assertCount(10, $plainCodes);
        self::assertCount(10, $fresh->admin_mfa_backup_codes);
        self::assertFalse(in_array($plainCodes[0], $fresh->admin_mfa_backup_codes, true));

        // actingAs keeps the same in-memory model across test requests; a real
        // browser request reloads it from the session provider.
        $this->actingAs($admin->fresh());
        $this->get(route('admin.mfa.backup-codes'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($plainCodes[0]);

        $this->get(route('admin.mfa.backup-codes'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_enrolment_confirmed_in_another_session_is_not_overwritten(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.mfa.setup'))->assertOk();
        $pendingSecret = Crypt::decryptString((string) session('admin_mfa_setup_secret_ciphertext'));

        // Another browser tab finished enrolment with its own secret first.
        $otherSecret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $admin->newQuery()->whereKey($admin->id)->first()->forceFill([
            'admin_totp_secret' => $otherSecret,
            'admin_totp_confirmed_at' => now(),
        ])->save();

        $this->post(route('admin.mfa.setup.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($pendingSecret)])
            ->assertStatus(409);

        self::assertSame($otherSecret, $admin->fresh()->admin_totp_secret);
        self::assertNull($admin->fresh()->admin_totp_last_used_step);
        self::assertNull(session('admin_mfa_new_recovery_codes_ciphertext'));
    }

    public function test_recovery_code_is_single_use_and_invalid_responses_do_not_identify_code_type(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $provider = app(TwoFactorAuthenticationProvider::class);
        $secret = $provider->generateSecretKey();
        $recovery = $provider->generateRecoveryCodes();
        $admin = $this->admin([
            'admin_totp_secret' => $secret,
            'admin_totp_confirmed_at' => now(),
            'admin_mfa_backup_codes' => array_map(app(AdminMfaService::class)->hashRecoveryCode(...), $recovery),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.mfa.challenge.verify'), ['code' => $recovery[0]])
            ->assertRedirect(route('admin.dashboard'));
        self::assertCount(9, $admin->fresh()->admin_mfa_backup_codes);

        $this->forgetVerifiedSession();
        $failure = $this->post(route('admin.mfa.challenge.verify'), ['code' => $recovery[0]])
            ->assertRedirect()
            ->assertSessionHasErrors('code');

        self::assertSame(
            'The verification code is invalid or has already been used.',
            $failure->getSession()->get('errors')->first('code')
        );
        self::assertCount(9, $admin->fresh()->admin_mfa_backup_codes);
    }

    public function test_totp_step_cannot_be_replayed_across_sessions(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $admin = $this->admin([
            'admin_totp_secret' => $secret,
            'admin_totp_confirmed_at' => now(),
            'admin_mfa_backup_codes' => [],
        ]);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->actingAs($admin)
            ->post(route('admin.mfa.challenge.verify'), ['code' => $code])
            ->assertRedirect(route('admin.dashboard'));

        $usedStep = $admin->fresh()->admin_totp_last_used_step;
        self::assertNotNull($usedStep);
        $this->forgetVerifiedSession();

        $this->post(route('admin.mfa.challenge.verify'), ['code' => $code])
            ->assertRedirect()
            ->assertSessionHasErrors('code');
        self::assertSame($usedStep, $admin->fresh()->admin_totp_last_used_step);
    }

    public function test_secret_enrolled_before_the_library_swap_still_passes_the_challenge(): void
    {
        // RFC 6238's SHA-1 reference secret, stored exactly as the previous
        // in-house generator wrote secrets: 32 unpadded Base32 characters.
        $legacySecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $admin = $this->admin([
            'admin_totp_secret' => $legacySecret,
            'admin_totp_confirmed_at' => now(),
            'admin_totp_last_used_step' => 1,
            'admin_mfa_backup_codes' => [],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.mfa.challenge.verify'), ['code' => app(Google2FA::class)->getCurrentOtp($legacySecret)])
            ->assertRedirect(route('admin.dashboard'));

        self::assertGreaterThan(1, $admin->fresh()->admin_totp_last_used_step);
    }

    public function test_malformed_challenge_input_is_not_flashed_back_to_the_session(): void
    {
        $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $admin = $this->admin([
            'admin_totp_secret' => $secret,
            'admin_totp_confirmed_at' => now(),
            'admin_mfa_backup_codes' => [],
        ]);
        $submitted = str_repeat('RECOVERY', 8);

        $response = $this->actingAs($admin)
            ->post(route('admin.mfa.challenge.verify'), ['code' => $submitted])
            ->assertRedirect()
            ->assertSessionHasErrors('code');

        self::assertArrayNotHasKey('code', $response->getSession()->getOldInput());
        self::assertStringNotContainsString($submitted, serialize($response->getSession()->all()));
    }

    private function admin(array $overrides = []): User
    {
        $admin = User::query()->forceCreate(array_merge([
            'name' => 'Rokn Admin',
            'email' => 'admin@rokn.test',
            'password' => Hash::make('correct horse battery staple'),
            'role' => 'admin',
            'active' => true,
        ], $overrides));

        return $admin;
    }

    private function forgetVerifiedSession(): void
    {
        session()->forget([
            'admin_mfa_verified_user_id',
            'admin_mfa_verified_at',
            'admin_mfa_secret_fingerprint',
        ]);
    }
}
