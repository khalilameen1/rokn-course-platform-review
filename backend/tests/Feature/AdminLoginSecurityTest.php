<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminLoginSecurityTest extends TestCase
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
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('password_resets', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_login_validates_email_and_password_before_authentication(): void
    {
        $this->post('/login', [
            'email' => 'not-an-email',
            'password' => '',
        ])->assertRedirect()->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest('web');
    }

    public function test_failed_login_is_generic_and_limited_by_normalized_email_and_ip(): void
    {
        $this->createAdmin('admin@rokn.test', 'correct-password');

        $knownFailure = $this->post('/login', [
            'email' => ' ADMIN@ROKN.TEST ',
            'password' => 'wrong-password',
        ])->assertRedirect()->assertSessionHasErrors('email');
        $knownMessage = $knownFailure->getSession()->get('errors')->first('email');

        $unknownFailure = $this->post('/login', [
            'email' => 'missing@rokn.test',
            'password' => 'wrong-password',
        ])->assertRedirect()->assertSessionHasErrors('email');
        self::assertSame($knownMessage, $unknownFailure->getSession()->get('errors')->first('email'));
        self::assertSame('بيانات الدخول غير صحيحة.', $knownMessage);

        // The first known-account failure already consumed one attempt.
        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => 'admin@rokn.test',
                'password' => 'wrong-password',
            ])->assertRedirect()->assertSessionHasErrors('email');
        }

        $key = $this->limiterKey('admin@rokn.test', '127.0.0.1');
        self::assertTrue(RateLimiter::tooManyAttempts($key, 5));
        $blocked = $this->post('/login', [
            'email' => 'admin@rokn.test',
            'password' => 'correct-password',
        ])->assertRedirect()->assertSessionHasErrors('email');
        self::assertSame(
            'محاولات كثيرة. حاول مرة أخرى بعد قليل.',
            $blocked->getSession()->get('errors')->first('email')
        );
        $this->assertGuest('web');
    }

    public function test_success_rotates_session_and_clears_credential_limiter(): void
    {
        $admin = $this->createAdmin('owner@rokn.test', 'correct-password');
        $key = $this->limiterKey('owner@rokn.test', '127.0.0.1');
        RateLimiter::hit($key, 60);

        $session = $this->app['session.store'];
        $session->setId(str_repeat('a', 40));
        $oldSessionId = $session->getId();

        $this->post('/login', [
            'email' => 'OWNER@ROKN.TEST',
            'password' => 'correct-password',
            'remember' => 'on',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'web');
        self::assertSame(0, RateLimiter::attempts($key));
        self::assertNotSame($oldSessionId, $this->app['session.store']->getId());
    }

    public function test_dashboard_login_accepts_only_administrator_and_moderator_roles(): void
    {
        foreach (['client', 'teacher'] as $role) {
            $user = $this->createUser("{$role}@rokn.test", 'correct-password', $role);

            $response = $this->post('/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])->assertRedirect()->assertSessionHasErrors('email');

            self::assertSame(
                'بيانات الدخول غير صحيحة.',
                $response->getSession()->get('errors')->first('email')
            );
            $this->assertGuest('web');
        }

        $moderator = $this->createUser('moderator@rokn.test', 'correct-password', 'Moderator');
        $this->post('/login', [
            'email' => $moderator->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($moderator, 'web');
    }

    public function test_public_web_registration_is_not_routable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Unexpected Account',
            'email' => 'unexpected@rokn.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ])->assertNotFound();

        self::assertFalse(User::query()->where('email', 'unexpected@rokn.test')->exists());
    }

    public function test_legacy_home_target_never_renders_a_second_dashboard_surface(): void
    {
        $this->get('/home')->assertRedirect(route('login'));

        $admin = $this->createAdmin('home-admin@rokn.test', 'correct-password');
        $this->actingAs($admin, 'web')
            ->get('/home')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_password_reset_mail_is_limited_to_dashboard_roles_without_enumeration(): void
    {
        Notification::fake();
        $admin = $this->createAdmin('reset-admin@rokn.test', 'correct-password');
        $client = $this->createUser('reset-client@rokn.test', 'correct-password', 'client');

        $clientResponse = $this->post('/password/email', ['email' => $client->email])
            ->assertRedirect()
            ->assertSessionHas('status');
        $missingResponse = $this->post('/password/email', ['email' => 'missing-reset@rokn.test'])
            ->assertRedirect()
            ->assertSessionHas('status');
        $adminResponse = $this->post('/password/email', ['email' => strtoupper($admin->email)])
            ->assertRedirect()
            ->assertSessionHas('status');

        self::assertSame(
            $clientResponse->getSession()->get('status'),
            $missingResponse->getSession()->get('status')
        );
        self::assertSame(
            $clientResponse->getSession()->get('status'),
            $adminResponse->getSession()->get('status')
        );
        Notification::assertNotSentTo($client, ResetPassword::class);
        Notification::assertSentTo($admin, ResetPassword::class);
    }

    public function test_password_reset_provider_failure_never_becomes_a_public_server_error(): void
    {
        $admin = $this->createAdmin('mail-outage-admin@rokn.test', 'correct-password');
        $broker = \Mockery::mock(\Illuminate\Contracts\Auth\PasswordBroker::class);
        $broker->shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new \RuntimeException('mail provider unavailable'));

        Password::shouldReceive('broker')->once()->andReturn($broker);
        Log::spy();

        $this->post('/password/email', ['email' => $admin->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Dashboard password reset notification could not be sent.',
                \Mockery::on(fn (array $context): bool =>
                    $context['email_hash'] === hash('sha256', $admin->email)
                    && $context['exception'] === \RuntimeException::class
                )
            );
    }

    public function test_legacy_learner_reset_token_cannot_create_a_web_password_session(): void
    {
        $client = $this->createUser('legacy-client@rokn.test', 'correct-password', 'client');
        $oldHash = $client->password;
        $token = Password::broker()->createToken($client);

        $this->post('/password/reset', [
            'token' => $token,
            'email' => $client->email,
            'password' => 'new-correct-password',
            'password_confirmation' => 'new-correct-password',
        ])->assertRedirect()->assertSessionHasErrors('email');

        self::assertSame($oldHash, $client->fresh()->password);
        $this->assertGuest('web');
    }

    public function test_dashboard_role_can_complete_password_reset_and_returns_to_mfa_gate(): void
    {
        $moderator = $this->createUser('recover-moderator@rokn.test', 'correct-password', 'moderator');
        $token = Password::broker()->createToken($moderator);

        $this->post('/password/reset', [
            'token' => $token,
            'email' => strtoupper($moderator->email),
            'password' => 'new-correct-password',
            'password_confirmation' => 'new-correct-password',
        ])->assertRedirect('/dashboard');

        self::assertTrue(Hash::check('new-correct-password', $moderator->fresh()->password));
        $this->assertAuthenticatedAs($moderator, 'web');
    }

    public function test_inactive_administrator_cannot_login_or_keep_an_existing_session(): void
    {
        $admin = $this->createAdmin('inactive@rokn.test', 'correct-password');
        $admin->forceFill(['active' => false])->save();

        $this->post('/login', [
            'email' => 'inactive@rokn.test',
            'password' => 'correct-password',
        ])->assertRedirect()->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->actingAs($admin, 'web')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    private function createAdmin(string $email, string $password): User
    {
        return $this->createUser($email, $password, 'admin');
    }

    private function createUser(string $email, string $password, string $role): User
    {
        return User::query()->forceCreate([
            'name' => 'Rokn Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'active' => true,
        ]);
    }

    private function limiterKey(string $email, string $ip): string
    {
        return 'admin-login:' . hash('sha256', strtolower(trim($email)) . '|' . $ip);
    }
}
