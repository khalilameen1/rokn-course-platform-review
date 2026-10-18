<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\DeviceLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DeviceLoginService owns the bearer checks and revocations of social sessions. */
final class DeviceLoginSocialSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_undelivered_social_session_is_revoked_and_no_longer_authenticates(): void
    {
        $user = $this->learner();
        $kept = $user->generateApiToken('google', 'google-owner');
        $undelivered = $user->generateApiToken('google', 'google-owner');
        $devices = app(DeviceLoginService::class);

        $devices->revokeUndeliveredSession('');
        $devices->revokeUndeliveredSession($undelivered);
        $devices->revokeUndeliveredSession($undelivered);

        $this->app['auth']->forgetGuards();
        $this->withToken($undelivered)->getJson('/api/v1/user/profile')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($kept)->getJson('/api/v1/user/profile')->assertOk();
    }

    public function test_fresh_social_reauthentication_is_bound_to_the_presented_bearer(): void
    {
        $user = $this->learner();
        SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-owner',
            'last_verified_at' => now(),
        ]);
        $verified = $user->generateApiToken('google', 'google-owner');
        $unbound = $user->generateApiToken();
        $devices = app(DeviceLoginService::class);

        self::assertTrue($devices->hasFreshSocialReauthentication($user, $verified));
        self::assertFalse($devices->hasFreshSocialReauthentication($user, $unbound));
        self::assertFalse($devices->hasFreshSocialReauthentication($user, null));
        self::assertFalse($devices->hasFreshSocialReauthentication($this->learner('other@rokn.test'), $verified));

        $this->travel(11)->minutes();
        self::assertFalse($devices->hasFreshSocialReauthentication($user, $verified));
    }

    private function learner(string $email = 'social-session@rokn.test'): User
    {
        return User::query()->forceCreate([
            'name' => 'Social Learner', 'email' => $email,
            'role' => 'client', 'active' => true,
        ]);
    }
}
